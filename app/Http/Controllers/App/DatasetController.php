<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Models\DatasetRow;
use Illuminate\Http\Request;

class DatasetController extends Controller
{
    public function index()
    {
        return view('app.datasets.index', ['datasets' => Dataset::orderBy('name')->get()]);
    }

    public function show(Request $request, Dataset $dataset)
    {
        $rows = DatasetRow::where('dataset_id', $dataset->id)
            ->when($request->query('q'), fn ($q, $t) => $q->where('data', 'like', '%'.$t.'%'))
            ->orderBy('id')->paginate(50)->withQueryString();

        return view('app.datasets.show', ['dataset' => $dataset, 'rows' => $rows]);
    }

    public function update(Request $request, Dataset $dataset)
    {
        $dataset->update($request->validate(['name' => 'required|string|max:120', 'description' => 'nullable|string|max:2000']));

        return back()->with('status', __('Dataset saved. The brain uses the description to know when to query it.'));
    }

    public function destroy(Dataset $dataset)
    {
        AuditLog::record('dataset.deleted', $dataset, ['name' => $dataset->name]);
        $dataset->delete();

        return redirect()->route('datasets.index')->with('status', __('Dataset deleted.'));
    }
}
