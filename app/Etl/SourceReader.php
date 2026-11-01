<?php

namespace App\Etl;

use App\Security\UrlGuard;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * EXTRACT: turns any source into associative rows.
 *   upload   - CSV, TSV, XLSX, JSON (array or {path: [...]}), NDJSON
 *   url      - same formats over HTTP(S), optional bearer token / headers
 *   database - read-only SELECT against MySQL, MariaDB, Postgres, SQL Server, SQLite
 */
class SourceReader
{
    /** @return \Generator<int, array<string, mixed>> */
    public function rows(string $type, array $source, array $options = []): \Generator
    {
        yield from match ($type) {
            'upload' => $this->fromFile(Storage::disk(config('brain.knowledge.disk'))->path($source['path']), $source['format'] ?? null, $options),
            'url' => $this->fromUrl($source, $options),
            'database' => $this->fromDatabase($source),
            default => throw new RuntimeException("Unknown source type {$type}"),
        };
    }

    /** First N rows + column names, for the mapping screen. */
    public function preview(string $type, array $source, array $options = [], int $limit = 5): array
    {
        $rows = [];
        $columns = [];
        foreach ($this->rows($type, $source, $options) as $row) {
            $columns = array_values(array_unique(array_merge($columns, array_keys($row))));
            $rows[] = $row;
            if (count($rows) >= $limit) {
                break;
            }
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    public function fromFile(string $path, ?string $format, array $options = []): \Generator
    {
        $format = strtolower($format ?: pathinfo($path, PATHINFO_EXTENSION));

        if ($format === 'xlsx') {
            yield from $this->withHeader(XlsxReader::rows($path, (int) ($options['sheet'] ?? 1)), $options);

            return;
        }
        if (in_array($format, ['json', 'ndjson', 'jsonl'], true)) {
            yield from $this->fromJson(file_get_contents($path), $format, $options);

            return;
        }

        $delimiter = $options['delimiter'] ?? ($format === 'tsv' ? "\t" : $this->sniffDelimiter($path));
        $fh = fopen($path, 'r');
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }
        $lines = (function () use ($fh, $delimiter) {
            while (($row = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
                if ($row !== [null]) {
                    yield $row;
                }
            }
            fclose($fh);
        })();

        yield from $this->withHeader($lines, $options);
    }

    protected function fromUrl(array $source, array $options): \Generator
    {
        $request = UrlGuard::http($source['url'])->timeout(60)->withHeaders($source['headers'] ?? []);
        if (! empty($source['token'])) {
            $request = $request->withToken($source['token']);
        }
        $response = $request->get($source['url'])->throw();

        $format = $source['format'] ?? null;
        if (! $format) {
            $type = $response->header('Content-Type');
            $format = str_contains($type, 'json') ? 'json' : (str_contains($type, 'spreadsheet') ? 'xlsx' : 'csv');
        }

        if (in_array($format, ['json', 'ndjson', 'jsonl'], true)) {
            yield from $this->fromJson($response->body(), $format, $options);

            return;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'etl').'.'.$format;
        file_put_contents($tmp, $response->body());
        try {
            yield from $this->fromFile($tmp, $format, $options);
        } finally {
            @unlink($tmp);
        }
    }

    protected function fromJson(string $body, string $format, array $options): \Generator
    {
        if ($format !== 'json') {
            foreach (preg_split("/\r?\n/", $body) as $line) {
                if (trim($line) !== '' && is_array($row = json_decode($line, true))) {
                    yield Arr::dot($row);
                }
            }

            return;
        }

        $data = json_decode($body, true);
        if (! empty($options['json_path'])) {
            $data = data_get($data, $options['json_path']);
        } elseif (is_array($data) && ! array_is_list($data)) {
            // auto-detect the first list inside an object, e.g. {"data": [...]}
            $data = collect($data)->first(fn ($v) => is_array($v) && array_is_list($v)) ?? [$data];
        }
        if (! is_array($data)) {
            throw new RuntimeException('JSON does not contain a list of records. Set the JSON path option.');
        }

        foreach ($data as $row) {
            if (is_array($row)) {
                yield collect(Arr::dot($row))->map(fn ($v) => is_array($v) ? json_encode($v) : $v)->all();
            }
        }
    }

    protected function fromDatabase(array $source): \Generator
    {
        $query = trim($source['query'] ?? '');
        if (! preg_match('/^\s*(select|with)\s/i', $query) || preg_match('/;\s*\S/', rtrim($query, "; \n\t"))
            || preg_match('/\b(insert|update|delete|drop|alter|truncate|create|grant|revoke|merge|call|exec)\b/i', $query)) {
            throw new RuntimeException('Only a single read-only SELECT query is allowed.');
        }

        $name = 'etl_'.Str::random(8);
        config(["database.connections.{$name}" => array_filter([
            'driver' => $source['driver'] ?? 'mysql',
            'host' => $source['host'] ?? null,
            'port' => $source['port'] ?? null,
            'database' => $source['database'] ?? null,
            'username' => $source['username'] ?? null,
            'password' => $source['password'] ?? null,
            'charset' => ($source['driver'] ?? 'mysql') === 'pgsql' ? 'utf8' : 'utf8mb4',
            'prefix' => '',
        ], fn ($v) => $v !== null && $v !== '')]);

        try {
            foreach (DB::connection($name)->cursor(rtrim($query, "; \n\t")) as $row) {
                yield (array) $row;
            }
        } finally {
            DB::purge($name);
        }
    }

    protected function withHeader(iterable $lines, array $options): \Generator
    {
        $hasHeader = $options['has_header'] ?? true;
        $header = null;
        foreach ($lines as $line) {
            if ($header === null) {
                if ($hasHeader) {
                    $header = $this->uniqueHeaders($line);

                    continue;
                }
                $header = array_map(fn ($i) => 'column_'.($i + 1), array_keys($line));
            }
            if (count(array_filter($line, fn ($v) => $v !== '' && $v !== null)) === 0) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $name) {
                $row[$name] = $line[$i] ?? null;
            }
            yield $row;
        }
    }

    protected function uniqueHeaders(array $line): array
    {
        $seen = [];

        return array_map(function ($h, $i) use (&$seen) {
            $h = trim((string) $h) ?: 'column_'.($i + 1);
            $base = $h;
            $n = 2;
            while (isset($seen[$h])) {
                $h = $base.'_'.$n++;
            }
            $seen[$h] = true;

            return $h;
        }, $line, array_keys($line));
    }

    protected function sniffDelimiter(string $path): string
    {
        $sample = (string) file_get_contents($path, false, null, 0, 4096);
        $first = strtok($sample, "\n") ?: '';
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t"), '|' => substr_count($first, '|')];
        arsort($counts);

        return array_key_first($counts);
    }
}
