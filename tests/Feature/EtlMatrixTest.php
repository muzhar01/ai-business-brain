<?php

namespace Tests\Feature;

use App\Brain\Tools\QueryDatasetTool;
use App\Etl\Exporter;
use App\Etl\Importer;
use App\Etl\SourceReader;
use App\Etl\XlsxWriter;
use App\Models\Dataset;
use App\Models\DatasetRow;
use App\Models\Export;
use App\Models\Import;
use App\Models\Lead;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EtlMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['brain.embeddings.provider' => null, 'brain.knowledge.queue' => false]);
    }

    /* ---- import modes × match keys × existing/new ---- */

    public static function modes(): iterable
    {
        $expect = [ // mode => [existing row result, new row result]
            'create' => ['created', 'created'],
            'upsert' => ['updated', 'created'],
            'update' => ['updated', 'skipped'],
            'skip' => ['skipped', 'created'],
        ];
        foreach ($expect as $mode => [$onExisting, $onNew]) {
            foreach (['email', 'phone', 'name'] as $key) {
                yield "{$mode} by {$key} existing" => [$mode, $key, true, $onExisting];
                yield "{$mode} by {$key} new" => [$mode, $key, false, $onNew];
            }
        }
    }

    #[DataProvider('modes')]
    public function test_import_mode(string $mode, string $matchOn, bool $exists, string $expected): void
    {
        $this->tenant();
        if ($exists) {
            Lead::create(['name' => 'Sara', 'email' => 'sara@x.pk', 'phone' => '+923001112233', 'company' => 'Old Co']);
        }
        Storage::disk('local')->put('imports/l.csv', "name,email,phone,company\nSara,sara@x.pk,+923001112233,New Co\n");
        $import = Import::create(['name' => 'i', 'target' => 'leads', 'source_type' => 'upload', 'source' => ['path' => 'imports/l.csv'],
            'mapping' => collect(['name', 'email', 'phone', 'company'])->map(fn ($f) => ['source' => $f, 'target' => $f, 'transform' => 'trim'])->all(),
            'options' => ['mode' => $mode, 'match_on' => $matchOn]]);

        $stats = app(Importer::class)->run($import)['stats'];
        $this->assertSame(1, $stats[$expected], json_encode($stats));
        $this->assertSame($exists && $expected === 'created' ? 2 : ($exists || $expected === 'created' ? 1 : 0), Lead::count());
        if ($expected === 'updated') {
            $this->assertSame('New Co', Lead::first()->company);
        }
    }

    /* ---- every file format and delimiter ---- */

    public static function formats(): iterable
    {
        $rows = [['sku' => 'A1', 'name' => 'Panel', 'price' => '100'], ['sku' => 'B2', 'name' => 'Inverter, 6kW', 'price' => '250.5'], ['sku' => 'C3', 'name' => 'Café "Pro"', 'price' => '9']];
        foreach ([',' => 'csv', ';' => 'csv', "\t" => 'tsv', '|' => 'csv'] as $d => $ext) {
            yield 'delimiter '.json_encode($d) => [$ext, $d, $rows];
        }
        yield 'json array' => ['json', null, $rows];
        yield 'json wrapped' => ['json-wrapped', null, $rows];
        yield 'ndjson' => ['ndjson', null, $rows];
        yield 'xlsx' => ['xlsx', null, $rows];
        yield 'csv with bom' => ['csv-bom', ',', $rows];
        yield 'csv crlf' => ['csv-crlf', ',', $rows];
    }

    #[DataProvider('formats')]
    public function test_every_format_reads_identically(string $format, ?string $delimiter, array $rows): void
    {
        $disk = Storage::disk('local');
        $csv = function (string $d, string $eol = "\n") use ($rows) {
            $fh = fopen('php://temp', 'w+');
            fputcsv($fh, array_keys($rows[0]), $d, '"', '\\', $eol);
            foreach ($rows as $r) {
                fputcsv($fh, $r, $d, '"', '\\', $eol);
            }
            rewind($fh);

            return stream_get_contents($fh);
        };
        [$file, $ext] = match ($format) {
            'csv', 'tsv' => [$csv($delimiter), $format],
            'csv-bom' => ["\xEF\xBB\xBF".$csv(','), 'csv'],
            'csv-crlf' => [$csv(',', "\r\n"), 'csv'],
            'json' => [json_encode($rows), 'json'],
            'json-wrapped' => [json_encode(['meta' => ['n' => 3], 'data' => $rows]), 'json'],
            'ndjson' => [implode("\n", array_map('json_encode', $rows)), 'ndjson'],
            'xlsx' => [null, 'xlsx'],
        };
        $path = "imports/f.{$ext}";
        if ($format === 'xlsx') {
            $disk->makeDirectory('imports');
            XlsxWriter::write($disk->path($path), array_keys($rows[0]), $rows);
        } else {
            $disk->put($path, $file);
        }

        $read = iterator_to_array(app(SourceReader::class)->rows('upload', ['path' => $path, 'format' => $ext]), false);
        $this->assertSame($rows, array_map(fn ($r) => array_map('strval', $r), $read));
    }

    /* ---- dataset query tool vs. a reference implementation ---- */

    public static function queries(): iterable
    {
        foreach (['=', '!=', '>', '<', '>=', '<='] as $op) {
            foreach ([0, 25, 50, 75, 100] as $v) {
                yield "price {$op} {$v}" => [[['field' => 'price', 'op' => $op, 'value' => (string) $v]], 'count', null];
            }
        }
        foreach (['panel', 'INV', 'x', 'é'] as $needle) {
            yield "contains {$needle}" => [[['field' => 'name', 'op' => 'contains', 'value' => $needle]], 'count', null];
        }
        foreach (['sum', 'avg', 'min', 'max', 'count'] as $agg) {
            yield "{$agg} price" => [[], $agg, 'price'];
            yield "{$agg} stock" => [[], $agg, 'stock'];
            yield "{$agg} price where stock>0" => [[['field' => 'stock', 'op' => '>', 'value' => '0']], $agg, 'price'];
        }
        foreach (['=', '!='] as $op) {
            foreach (['solar', 'storage', 'SOLAR'] as $v) {
                yield "category {$op} {$v}" => [[['field' => 'category', 'op' => $op, 'value' => $v]], 'count', null];
            }
        }
    }

    #[DataProvider('queries')]
    public function test_query_dataset_matches_reference(array $filters, string $aggregate, ?string $field): void
    {
        $this->tenant();
        mt_srand(9009);
        $ds = Dataset::create(['name' => 'Products']);
        $names = ['Solar Panel', 'Inverter', 'Battery', 'Café Box', 'Cable X'];
        $rows = [];
        for ($i = 0; $i < 60; $i++) {
            $rows[] = $r = ['name' => $names[$i % 5].' '.$i, 'price' => mt_rand(0, 100), 'stock' => mt_rand(0, 3), 'category' => $i % 2 ? 'solar' : 'storage'];
            DatasetRow::create(['dataset_id' => $ds->id, 'data' => $r]);
        }

        $ref = collect($rows);
        foreach ($filters as $f) {
            $ref = $ref->filter(function ($r) use ($f) {
                $a = $r[$f['field']];
                $b = $f['value'];
                if ($f['op'] === 'contains') {
                    return str_contains(mb_strtolower((string) $a), mb_strtolower($b));
                }
                [$a, $b] = is_numeric($a) && is_numeric($b) ? [(float) $a, (float) $b] : [mb_strtolower((string) $a), mb_strtolower($b)];

                return match ($f['op']) {
                    '=' => $a == $b, '!=' => $a != $b, '>' => $a > $b, '<' => $a < $b, '>=' => $a >= $b, '<=' => $a <= $b
                };
            });
        }
        $values = $ref->pluck($field)->map(fn ($v) => (float) $v);
        $expected = match ($aggregate) {
            'count' => $ref->count(),
            'sum' => round($values->sum(), 4),
            'avg' => $values->isEmpty() ? null : round($values->avg(), 4),
            'min' => $values->min(),
            'max' => $values->max(),
        };

        $result = (new QueryDatasetTool)->handle(['dataset' => 'products', 'filters' => $filters, 'aggregate' => $aggregate, 'field' => $field]);
        $this->assertEquals($expected, $result['result']);
    }

    public function test_group_by(): void
    {
        $this->tenant();
        $ds = Dataset::create(['name' => 'Sales']);
        foreach ([['r' => 'KP', 'v' => 10], ['r' => 'KP', 'v' => 5], ['r' => 'Punjab', 'v' => 7]] as $row) {
            DatasetRow::create(['dataset_id' => $ds->id, 'data' => $row]);
        }
        $out = (new QueryDatasetTool)->handle(['dataset' => 'sales', 'aggregate' => 'sum', 'field' => 'v', 'group_by' => 'r']);
        $this->assertEquals(['KP' => 15, 'Punjab' => 7], $out['result']->all());
    }

    /* ---- CSV formula injection in exports ---- */

    public static function formulas(): iterable
    {
        foreach (['=', '+', '-', '@'] as $p) {
            foreach (['1+1', 'cmd|\' /C calc\'!A0', 'HYPERLINK("http://x")', 'SUM(A1:A9)', '2*3', 'IMPORTXML(x)', 'DDE("cmd")', 'A1', '0', 'x'] as $i => $body) {
                yield "{$p} #{$i}" => [$p.$body];
            }
        }
    }

    #[DataProvider('formulas')]
    public function test_csv_export_neutralises_formulas(string $value): void
    {
        $this->tenant();
        Lead::create(['name' => $value]);
        $export = app(Exporter::class)->run(Export::create(['target' => 'leads', 'format' => 'csv', 'fields' => [['source' => 'name', 'header' => 'n']]]));
        $lines = array_map(fn ($l) => str_getcsv($l, ',', '"', '\\'), array_filter(explode("\n", Storage::disk('local')->get($export->path))));
        $this->assertSame("'".$value, array_values($lines)[1][0]);
    }

    /* ---- input validation ---- */

    public static function invalidLeads(): iterable
    {
        $cases = [
            'no name' => [['email' => 'a@b.co'], 'name'],
            'long name' => [['name' => str_repeat('x', 192)], 'name'],
            'bad email' => [['name' => 'x', 'email' => 'not-an-email'], 'email'],
            'bad status' => [['name' => 'x', 'status' => 'hacked'], 'status'],
            'value text' => [['name' => 'x', 'value' => 'lots'], 'value'],
            'long phone' => [['name' => 'x', 'phone' => str_repeat('1', 51)], 'phone'],
            'array name' => [['name' => ['a']], 'name'],
            'long source' => [['name' => 'x', 'source' => str_repeat('s', 192)], 'source'],
            'long company' => [['name' => 'x', 'company' => str_repeat('c', 192)], 'company'],
            'email array' => [['name' => 'x', 'email' => ['a@b.co']], 'email'],
        ];
        foreach ($cases as $k => $v) {
            yield $k => $v;
        }
    }

    #[DataProvider('invalidLeads')]
    public function test_lead_validation(array $data, string $field): void
    {
        [$user] = $this->tenant();
        Tenant::forget();
        $this->actingAs($user)->post('/leads', $data)->assertSessionHasErrors($field);
        $this->assertSame(0, Lead::withoutGlobalScopes()->count());
    }

    public static function invalidChats(): iterable
    {
        yield 'empty' => [['message' => '']];
        yield 'too long' => [['message' => str_repeat('x', 20001)]];
        yield 'array' => [['message' => ['x']]];
        yield 'bad provider' => [['message' => 'hi', 'provider' => 'nope']];
        yield 'temperature high' => [['message' => 'hi', 'temperature' => 5]];
        yield 'max tokens zero' => [['message' => 'hi', 'max_tokens' => 0]];
        yield 'bad conversation' => [['message' => 'hi', 'conversation_id' => str_repeat('z', 200)]];
    }

    #[DataProvider('invalidChats')]
    public function test_chat_validation(array $payload): void
    {
        [, , $token] = $this->tenant();
        Tenant::forget();
        $this->postJson('/api/brain/chat', $payload, ['Authorization' => "Bearer {$token}"])->assertStatus(422);
    }
}
