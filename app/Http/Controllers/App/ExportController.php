<?php

namespace App\Http\Controllers\App;

use App\Etl\Exporter;
use App\Http\Controllers\Controller;
use App\Jobs\RunExport;
use App\Models\Dataset;
use App\Models\Export;
use App\Saas\Quota;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExportController extends Controller
{
    public function index()
    {
        return view('app.exports', [
            'exports' => Export::latest()->paginate(30),
            'targets' => Exporter::targets(),
            'datasets' => Dataset::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        Quota::feature(Tenant::current(), 'export');
        $data = $request->validate([
            'target' => 'required|in:'.implode(',', array_keys(Exporter::targets())),
            'dataset_id' => 'required_if:target,dataset|nullable|integer',
            'format' => 'required|in:csv,json,xlsx',
            'columns' => 'nullable|string|max:5000',
            'status' => 'nullable|string|max:40',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);
        if ($data['target'] === 'audit') {
            Quota::feature(Tenant::current(), 'audit_log');
        }
        if (! empty($data['dataset_id'])) {
            Dataset::findOrFail($data['dataset_id']);
        }

        // "source as Header" per line, e.g. "email as E-mail" ; blank = all fields
        $fields = collect(preg_split('/\r?\n|,(?![^{]*})/', (string) ($data['columns'] ?? '')))
            ->map(fn ($l) => trim($l))->filter()
            ->map(function ($l) {
                [$source, $header] = array_pad(preg_split('/\s+as\s+/i', $l, 2), 2, null);

                return ['source' => trim($source), 'header' => trim($header ?: $source)];
            })->values()->all();

        $export = Export::create([
            'user_id' => $request->user()->id,
            'target' => $data['target'],
            'dataset_id' => $data['dataset_id'] ?? null,
            'format' => $data['format'],
            'fields' => $fields ?: null,
            'filters' => array_filter(['status' => $data['status'] ?? null, 'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null]),
        ]);

        RunExport::dispatch(Tenant::id(), $export->id);

        return back()->with('status', __('Export started. It appears below when ready.'));
    }

    public function download(Export $export)
    {
        abort_unless($export->status === 'done' && $export->path, 404);

        return Storage::disk(config('brain.knowledge.disk'))->download($export->path);
    }
}
