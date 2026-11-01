<?php

namespace Tests\Feature;

use App\Brain\Knowledge\KnowledgeBase;
use App\Brain\Tools\QueryDatasetTool;
use App\Etl\AutoMapper;
use App\Etl\Exporter;
use App\Etl\Importer;
use App\Etl\SourceReader;
use App\Etl\Transformer;
use App\Etl\WorkspaceBackup;
use App\Models\Agent;
use App\Models\Chunk;
use App\Models\Dataset;
use App\Models\DatasetRow;
use App\Models\Document;
use App\Models\Export;
use App\Models\Import;
use App\Models\Lead;
use App\Models\Task;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EtlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['brain.embeddings.provider' => null, 'brain.knowledge.queue' => false]);
    }

    protected function csv(string $name, string $content): string
    {
        Storage::disk('local')->put("imports/{$name}", $content);

        return "imports/{$name}";
    }

    public function test_transformer_pipeline_and_templates(): void
    {
        $row = ['First' => ' Sara ', 'Last' => 'Khan', 'Amount' => 'PKR 1,250.50', 'Joined' => '24/10/2026', 'Mail' => ' SARA@Nova.PK '];
        $this->assertSame('Sara Khan', Transformer::apply(Transformer::source($row, '{First} {Last}'), 'trim'));
        $this->assertSame(1250.5, Transformer::apply($row['Amount'], 'number'));
        $this->assertSame('2026-10-24', Transformer::apply($row['Joined'], 'date'));
        $this->assertSame('sara@nova.pk', Transformer::apply($row['Mail'], 'trim|email'));
        $this->assertSame('won', Transformer::apply('Closed Won', 'map:Closed Won=won,Closed Lost=lost'));
        $this->assertSame(1234.56, Transformer::apply('1.234,56', 'number'));
        $this->assertSame('2026-10-24', Transformer::apply('46319', 'date')); // Excel serial
        $this->assertNull(Transformer::apply('N/A', 'nullify'));
    }

    public function test_auto_mapper_understands_messy_headers(): void
    {
        $mapping = collect(app(AutoMapper::class)->suggest('leads', ['First Name', 'Last Name', 'E-mail Address', 'Mobile', 'Organisation', 'Deal Size', 'Stage'], [], false))->keyBy('target');

        $this->assertSame('{First Name} {Last Name}', $mapping['name']['source']);
        $this->assertSame('E-mail Address', $mapping['email']['source']);
        $this->assertSame('Mobile', $mapping['phone']['source']);
        $this->assertSame('Organisation', $mapping['company']['source']);
        $this->assertSame('Deal Size', $mapping['value']['source']);
        $this->assertSame('Stage', $mapping['status']['source']);
    }

    public function test_csv_import_to_leads_with_upsert_and_row_errors(): void
    {
        $this->tenant();
        Lead::create(['name' => 'Old Name', 'email' => 'sara@nova.pk']);
        $path = $this->csv('leads.csv', "Full Name;Email;Deal;Stage\nSara Khan;SARA@nova.pk;5,000;Qualified\nBilal;bilal@x.pk;1200;bogus\n;nobody@x.pk;1;new\nAyesha;ayesha@y.pk;300;won\n");

        $import = Import::create([
            'name' => 'Leads', 'target' => 'leads', 'source_type' => 'upload', 'source' => ['path' => $path, 'format' => 'csv'],
            'mapping' => [
                ['source' => 'Full Name', 'target' => 'name', 'transform' => 'trim'],
                ['source' => 'Email', 'target' => 'email', 'transform' => 'trim|email'],
                ['source' => 'Deal', 'target' => 'value', 'transform' => 'number'],
                ['source' => 'Stage', 'target' => 'status', 'transform' => 'lower'],
                ['source' => null, 'target' => 'source', 'default' => 'csv import'],
            ],
            'options' => ['mode' => 'upsert', 'match_on' => 'email'],
        ]);

        $result = app(Importer::class)->run($import);

        $this->assertSame(['total' => 4, 'created' => 1, 'updated' => 1, 'skipped' => 0, 'failed' => 2], $result['stats']);
        $this->assertSame('Sara Khan', Lead::firstWhere('email', 'sara@nova.pk')->name);
        $this->assertEquals(5000, Lead::firstWhere('email', 'sara@nova.pk')->value);
        $this->assertSame('csv import', Lead::firstWhere('email', 'ayesha@y.pk')->source);
        $this->assertSame('done', $import->fresh()->status);
        $this->assertCount(2, $import->fresh()->errors);
    }

    public function test_json_api_import_into_dataset_and_brain_can_query_it(): void
    {
        $this->tenant();
        Http::fake(['shop.test/*' => Http::response(['data' => ['products' => [
            ['sku' => 'P1', 'name' => 'Solar Panel 550W', 'price' => '42000', 'stock' => 12, 'meta' => ['brand' => 'Jinko']],
            ['sku' => 'P2', 'name' => 'Inverter 6kW', 'price' => '185000', 'stock' => 0, 'meta' => ['brand' => 'Growatt']],
            ['sku' => 'P3', 'name' => 'Battery 5kWh', 'price' => '310000', 'stock' => 4, 'meta' => ['brand' => 'Pylontech']],
        ]]])]);

        $source = ['url' => 'https://shop.test/api/products'];
        $options = ['json_path' => 'data.products', 'dataset_name' => 'Products', 'mode' => 'upsert', 'match_on' => 'sku'];
        $preview = app(SourceReader::class)->preview('url', $source, $options);
        $this->assertContains('meta.brand', $preview['columns']); // nested JSON flattened

        $import = Import::create(['name' => 'Products', 'target' => 'dataset', 'source_type' => 'url', 'source' => $source, 'options' => $options,
            'mapping' => app(AutoMapper::class)->suggest('dataset', $preview['columns'])]);
        $import->update(['mapping' => collect($import->mapping)->map(fn ($m) => $m['source'] === 'price' ? array_merge($m, ['transform' => 'number']) : $m)->all()]);
        app(Importer::class)->run($import);
        app(Importer::class)->run($import->fresh()); // re-run = upsert, no duplicates

        $dataset = Dataset::firstWhere('slug', 'products');
        $this->assertSame(3, $dataset->row_count);
        $this->assertContains('meta_brand', $dataset->fieldNames());

        $tool = new QueryDatasetTool;
        $this->assertSame(2, $tool->handle(['dataset' => 'products', 'filters' => [['field' => 'stock', 'op' => '>', 'value' => '0']], 'aggregate' => 'count'])['result']);
        $this->assertEquals(537000, $tool->handle(['dataset' => 'products', 'aggregate' => 'sum', 'field' => 'price'])['result']);
        $this->assertSame('Battery 5kWh', $tool->handle(['dataset' => 'products', 'sort_by' => 'price', 'sort_desc' => true, 'limit' => 1])['rows'][0]['name']);
    }

    public function test_xlsx_round_trip_and_export_formats(): void
    {
        $this->tenant();
        Lead::create(['name' => 'Sara', 'email' => 'sara@nova.pk', 'status' => 'won', 'value' => 5000]);
        Lead::create(['name' => '=HYPERLINK("x")', 'status' => 'new']);

        foreach (['csv', 'json', 'xlsx'] as $format) {
            $export = Export::create(['target' => 'leads', 'format' => $format, 'fields' => [['source' => 'name', 'header' => 'Customer'], ['source' => 'value', 'header' => 'Deal value']]]);
            $export = app(Exporter::class)->run($export);
            $this->assertSame('done', $export->status, (string) $export->error);
            $this->assertSame(2, $export->rows);
            $file = Storage::disk('local')->path($export->path);

            if ($format === 'csv') {
                $this->assertStringContainsString('Customer,"Deal value"', file_get_contents($file));
                $this->assertStringContainsString("'=HYPERLINK", file_get_contents($file)); // formula injection neutralised
            }
            if ($format === 'json') {
                $this->assertSame('Sara', json_decode(file_get_contents($file), true)[0]['Customer']);
            }
            if ($format === 'xlsx') {
                $rows = iterator_to_array(app(SourceReader::class)->fromFile($file, 'xlsx'));
                $this->assertSame('Sara', $rows[0]['Customer']);
                $this->assertEquals(5000, $rows[0]['Deal value']);
            }
        }
    }

    public function test_api_import_with_auto_mapping(): void
    {
        [, , $token] = $this->tenant();
        Tenant::forget();
        $file = UploadedFile::fake()->createWithContent('crm.csv', "Customer Name,Email Address,Company\nZara,zara@q.pk,Q Traders\n");

        $this->post('/api/brain/imports', ['target' => 'leads', 'file' => $file], ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->assertStatus(202);

        $this->assertDatabaseHas('brain_leads', ['name' => 'Zara', 'email' => 'zara@q.pk', 'company' => 'Q Traders']);
        $this->assertSame('done', Import::withoutGlobalScopes()->first()->status);
    }

    public function test_workspace_backup_restores_into_new_workspace_with_remapped_ids(): void
    {
        [$user, $ws] = $this->tenant();
        $lead = Lead::create(['name' => 'Sara']);
        Task::create(['title' => 'Call Sara', 'lead_id' => $lead->id]);
        app(KnowledgeBase::class)->addFile(UploadedFile::fake()->createWithContent('policy.txt', 'Refunds within 14 days.'));
        $d = Dataset::create(['name' => 'Products']);
        DatasetRow::create(['dataset_id' => $d->id, 'data' => ['sku' => 'P1']]);

        $zip = app(WorkspaceBackup::class)->export($ws, storage_path('framework/testing/backup.zip'));
        Tenant::forget();
        $restored = app(WorkspaceBackup::class)->import($zip, $user);

        Tenant::set($restored);
        $this->assertNotSame($ws->id, $restored->id);
        $this->assertSame('Sara', Lead::first()->name);
        $this->assertSame(Lead::first()->id, Task::first()->lead_id);
        $this->assertSame('P1', DatasetRow::first()->data['sku']);
        $this->assertSame(Dataset::first()->id, DatasetRow::first()->dataset_id);
        $doc = Document::first();
        $this->assertStringContainsString('14 days', Storage::disk('local')->get($doc->path));
        $this->assertSame(1, Chunk::count());
        $this->assertSame(1, Agent::count());
        @unlink($zip);
    }

    public function test_database_source_only_allows_read_only_select(): void
    {
        $this->expectExceptionMessage('read-only SELECT');
        iterator_to_array(app(SourceReader::class)->rows('database', ['driver' => 'sqlite', 'database' => ':memory:', 'query' => 'DELETE FROM users']));
    }
}
