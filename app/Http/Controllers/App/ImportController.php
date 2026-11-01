<?php

namespace App\Http\Controllers\App;

use App\Etl\AutoMapper;
use App\Etl\Importer;
use App\Etl\SourceReader;
use App\Etl\Targets;
use App\Etl\Transformer;
use App\Http\Controllers\Controller;
use App\Jobs\RunImport;
use App\Models\Dataset;
use App\Models\Import;
use App\Saas\Quota;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** The ETL wizard: 1) source  2) map + transform  3) preview  4) run / schedule. */
class ImportController extends Controller
{
    public function index()
    {
        return view('app.imports.index', ['imports' => Import::latest()->paginate(30)]);
    }

    public function create(Request $request)
    {
        return view('app.imports.create', [
            'targets' => Targets::all(),
            'datasets' => Dataset::orderBy('name')->get(),
            'target' => $request->query('target', 'leads'),
        ]);
    }

    public function store(Request $request, SourceReader $reader, AutoMapper $mapper)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'target' => 'required|in:'.implode(',', array_keys(Targets::all())),
            'dataset_id' => 'nullable|integer',
            'dataset_name' => 'nullable|string|max:120',
            'source_type' => 'required|in:upload,url,database',
            'file' => 'required_if:source_type,upload|nullable|file|max:51200|extensions:csv,tsv,txt,xlsx,json,ndjson,jsonl',
            'url' => 'required_if:source_type,url|nullable|url',
            'url_token' => 'nullable|string|max:2000',
            'url_format' => 'nullable|in:csv,tsv,json,ndjson,xlsx',
            'json_path' => 'nullable|string|max:200',
            'db_driver' => 'required_if:source_type,database|nullable|in:mysql,mariadb,pgsql,sqlsrv',
            'db_host' => 'required_if:source_type,database|nullable|string|max:191',
            'db_port' => 'nullable|integer',
            'db_database' => 'required_if:source_type,database|nullable|string|max:191',
            'db_username' => 'nullable|string|max:191',
            'db_password' => 'nullable|string|max:191',
            'db_query' => 'required_if:source_type,database|nullable|string|max:20000',
            'has_header' => 'nullable|boolean',
            'delimiter' => 'nullable|string|max:2',
            'sheet' => 'nullable|integer|min:1',
        ]);

        $ws = Tenant::current();
        if ($data['source_type'] === 'url') {
            Quota::feature($ws, 'url_imports');
        }
        if ($data['source_type'] === 'database') {
            Quota::feature($ws, 'database_imports');
        }

        $source = match ($data['source_type']) {
            'upload' => ['path' => $request->file('file')->store('imports/'.$ws->id, config('brain.knowledge.disk')), 'format' => strtolower($request->file('file')->getClientOriginalExtension()), 'filename' => $request->file('file')->getClientOriginalName()],
            'url' => array_filter(['url' => $data['url'], 'token' => $data['url_token'] ?? null, 'format' => $data['url_format'] ?? null]),
            'database' => [
                'driver' => $data['db_driver'], 'host' => $data['db_host'], 'port' => $data['db_port'] ?? null,
                'database' => $data['db_database'], 'username' => $data['db_username'] ?? null, 'password' => $data['db_password'] ?? null,
                'query' => $data['db_query'],
            ],
        };

        $options = array_filter([
            'has_header' => $request->has('has_header') ? $request->boolean('has_header') : true,
            'delimiter' => $data['delimiter'] === '\t' ? "\t" : ($data['delimiter'] ?? null),
            'sheet' => $data['sheet'] ?? null,
            'json_path' => $data['json_path'] ?? null,
            'dataset_name' => $data['dataset_name'] ?? null,
            'mode' => 'upsert',
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $preview = $reader->preview($data['source_type'], $source, $options, 5);
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['source' => __('Could not read the source: :error', ['error' => $e->getMessage()])]);
        }
        if (! $preview['columns']) {
            return back()->withInput()->withErrors(['source' => __('No rows found in the source.')]);
        }

        $import = Import::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'target' => $data['target'],
            'dataset_id' => $data['target'] === 'dataset' && ! empty($data['dataset_id']) ? Dataset::findOrFail($data['dataset_id'])->id : null,
            'source_type' => $data['source_type'],
            'source' => $source,
            'columns' => $preview['columns'],
            'mapping' => $mapper->suggest($data['target'], $preview['columns'], $preview['rows']),
            'options' => $options,
        ]);

        return redirect()->route('imports.map', $import)->with('status', __('Columns detected and mapped automatically. Check the mapping, preview, then run.'));
    }

    public function map(Import $import, SourceReader $reader)
    {
        try {
            $sample = $reader->preview($import->source_type, $import->source ?? [], $import->options ?? [], 5)['rows'];
        } catch (Throwable $e) {
            $sample = [];
        }

        return view('app.imports.map', [
            'import' => $import,
            'sample' => $sample,
            'fields' => Targets::fields($import->target),
            'matchOn' => Targets::all()[$import->target]['match_on'],
            'steps' => Transformer::STEPS,
            'preview' => session('preview'),
        ]);
    }

    public function saveMap(Request $request, Import $import, Importer $importer, AutoMapper $mapper, SourceReader $reader)
    {
        if ($request->input('action') === 'automap') {
            $preview = $reader->preview($import->source_type, $import->source ?? [], $import->options ?? [], 5);
            $import->update(['mapping' => $mapper->suggest($import->target, $import->columns ?? [], $preview['rows'], useAi: true)]);

            return back()->with('status', __('Mapping re-suggested.'));
        }

        $data = $request->validate([
            'mapping' => 'array',
            'mapping.*.source' => 'nullable|string|max:500',
            'mapping.*.target' => 'nullable|string|max:120',
            'mapping.*.transform' => 'nullable|string|max:500',
            'mapping.*.default' => 'nullable|string|max:500',
            'mapping.*.include' => 'nullable',
            'mode' => 'required|in:create,upsert,update,skip',
            'match_on' => 'nullable|string|max:120',
            'schedule' => 'nullable|in:hourly,daily,weekly',
        ]);

        $mapping = collect($data['mapping'] ?? [])
            ->filter(fn ($m) => ! array_key_exists('include', $m) || $m['include'])
            ->filter(fn ($m) => filled($m['target'] ?? null))
            ->map(fn ($m) => ['source' => $m['source'] ?? null, 'target' => trim($m['target']), 'transform' => $m['transform'] ?? null, 'default' => $m['default'] ?? null])
            ->values()->all();

        if (! empty($data['schedule'])) {
            Quota::feature(Tenant::current(), 'scheduled_imports');
            abort_if($import->source_type === 'upload', 422, 'Only URL and database sources can be scheduled.');
        }

        $import->update([
            'mapping' => $mapping,
            'options' => array_merge($import->options ?? [], ['mode' => $data['mode'], 'match_on' => $data['match_on'] ?? null]),
            'schedule' => $data['schedule'] ?? null,
        ]);

        if ($request->input('action') === 'preview') {
            $result = $importer->run($import, dryRun: true);

            return back()->with('preview', $result);
        }

        $import->update(['status' => 'queued']);
        RunImport::dispatch(Tenant::id(), $import->id);

        return redirect()->route('imports.show', $import)->with('status', __('Import started.'));
    }

    public function show(Import $import)
    {
        return view('app.imports.show', ['import' => $import]);
    }

    public function rerun(Import $import)
    {
        $import->update(['status' => 'queued']);
        RunImport::dispatch(Tenant::id(), $import->id);

        return back()->with('status', __('Import queued again.'));
    }

    public function destroy(Import $import)
    {
        if ($import->source_type === 'upload' && ($path = $import->source['path'] ?? null)) {
            Storage::disk(config('brain.knowledge.disk'))->delete($path);
        }
        $import->delete();

        return redirect()->route('imports.index')->with('status', __('Import deleted (imported data is kept).'));
    }

    /** Downloadable CSV template for a target. */
    public function template(string $target)
    {
        abort_unless(isset(Targets::all()[$target]) && $target !== 'dataset', 404);
        $headers = array_keys(Targets::fields($target));

        return response(implode(',', $headers)."\n", 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$target}-template.csv\"",
        ]);
    }
}
