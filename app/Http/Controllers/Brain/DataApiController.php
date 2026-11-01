<?php

namespace App\Http\Controllers\Brain;

use App\Etl\AutoMapper;
use App\Etl\Exporter;
use App\Etl\SourceReader;
use App\Etl\Targets;
use App\Http\Controllers\Controller;
use App\Jobs\RunExport;
use App\Jobs\RunImport;
use App\Models\Dataset;
use App\Models\DatasetRow;
use App\Models\Export;
use App\Models\Import;
use App\Models\Usage;
use App\Saas\Quota;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** ETL over the REST API: push data in from any system, pull it out in any format. */
class DataApiController extends Controller
{
    /**
     * POST /imports  multipart: file + target [+ mapping JSON, mode, match_on, dataset_name]
     *            or  json: {target, url, mapping?, ...}
     * Mapping is auto-suggested when omitted.
     */
    public function import(Request $request, AutoMapper $mapper, SourceReader $reader)
    {
        $data = $request->validate([
            'target' => ['required', 'in:'.implode(',', array_keys(Targets::all()))],
            'file' => ['required_without:url', 'file', 'max:51200', 'extensions:csv,tsv,txt,xlsx,json,ndjson,jsonl'],
            'url' => ['required_without:file', 'url'],
            'name' => ['nullable', 'string', 'max:120'],
            'dataset_name' => ['nullable', 'string', 'max:120'],
            'mapping' => ['nullable'],
            'mode' => ['nullable', 'in:create,upsert,update,skip'],
            'match_on' => ['nullable', 'string', 'max:120'],
            'json_path' => ['nullable', 'string', 'max:200'],
        ]);

        if (isset($data['url'])) {
            Quota::feature(Tenant::current(), 'url_imports');
        }

        $source = isset($data['url'])
            ? ['url' => $data['url']]
            : ['path' => $request->file('file')->store('imports', config('brain.knowledge.disk')), 'format' => strtolower($request->file('file')->getClientOriginalExtension())];

        $options = array_filter([
            'mode' => $data['mode'] ?? 'upsert',
            'match_on' => $data['match_on'] ?? null,
            'dataset_name' => $data['dataset_name'] ?? null,
            'json_path' => $data['json_path'] ?? null,
        ]);

        $preview = $reader->preview(isset($data['url']) ? 'url' : 'upload', $source, $options);
        $mapping = is_string($data['mapping'] ?? null) ? json_decode($data['mapping'], true) : ($data['mapping'] ?? null);

        $import = Import::create([
            'user_id' => $request->user()?->id,
            'name' => $data['name'] ?? ($data['dataset_name'] ?? 'API import '.now()->toDateTimeString()),
            'target' => $data['target'],
            'source_type' => isset($data['url']) ? 'url' : 'upload',
            'source' => $source,
            'columns' => $preview['columns'],
            'mapping' => $mapping ?: $mapper->suggest($data['target'], $preview['columns'], $preview['rows']),
            'options' => $options,
            'status' => 'queued',
        ]);

        RunImport::dispatch(Tenant::id(), $import->id);

        return response()->json($import->fresh(), 202);
    }

    public function showImport(Import $import)
    {
        return $import;
    }

    public function export(Request $request)
    {
        Quota::feature(Tenant::current(), 'export');
        $data = $request->validate([
            'target' => ['required', 'in:'.implode(',', array_keys(Exporter::targets()))],
            'format' => ['nullable', 'in:csv,json,xlsx'],
            'dataset_id' => ['required_if:target,dataset', 'nullable', 'integer'],
            'fields' => ['nullable', 'array'],
            'fields.*.source' => ['required', 'string'],
            'fields.*.header' => ['required', 'string'],
            'filters' => ['nullable', 'array'],
        ]);
        if (! empty($data['dataset_id'])) {
            Dataset::findOrFail($data['dataset_id']);
        }

        $export = Export::create($data + ['format' => $data['format'] ?? 'csv', 'user_id' => $request->user()?->id]);
        RunExport::dispatch(Tenant::id(), $export->id);

        return response()->json($export->fresh(), 202);
    }

    public function showExport(Export $export)
    {
        return $export;
    }

    public function download(Export $export)
    {
        abort_unless($export->status === 'done' && $export->path, 404, 'Export not ready.');

        return Storage::disk(config('brain.knowledge.disk'))->download($export->path);
    }

    public function datasets()
    {
        return Dataset::orderBy('name')->get();
    }

    public function rows(Request $request, Dataset $dataset)
    {
        return DatasetRow::where('dataset_id', $dataset->id)->orderBy('id')->paginate(min(500, (int) $request->query('per_page', 100)));
    }

    /** Push rows straight in: {rows: [{...}], key?: "sku"} - upserts on key. */
    public function pushRows(Request $request, Dataset $dataset)
    {
        $data = $request->validate(['rows' => ['required', 'array', 'max:5000'], 'key' => ['nullable', 'string']]);
        Quota::ensure(Tenant::current(), 'import_rows', count($data['rows']));

        $created = $updated = 0;
        foreach ($data['rows'] as $row) {
            $key = isset($data['key']) ? (string) ($row[$data['key']] ?? '') : null;
            $existing = $key ? DatasetRow::where('dataset_id', $dataset->id)->where('external_key', $key)->first() : null;
            if ($existing) {
                $existing->update(['data' => array_merge($existing->data, $row)]);
                $updated++;
            } else {
                DatasetRow::create(['dataset_id' => $dataset->id, 'external_key' => $key ?: null, 'data' => $row]);
                $created++;
            }
        }
        $dataset->rememberFields(array_keys(array_merge(...array_map(fn ($r) => (array) $r, $data['rows']))));
        $dataset->update(['row_count' => $dataset->rows()->count()]);
        Usage::record(Tenant::current(), ['import_rows' => $created + $updated]);

        return ['created' => $created, 'updated' => $updated, 'row_count' => $dataset->row_count];
    }
}
