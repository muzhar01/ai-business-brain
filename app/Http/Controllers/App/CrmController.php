<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CrmController extends Controller
{
    public function leads(Request $request)
    {
        $leads = Lead::query()
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($q) => $q->where('name', 'like', "%{$t}%")->orWhere('email', 'like', "%{$t}%")->orWhere('company', 'like', "%{$t}%")))
            ->latest()->paginate(40)->withQueryString();

        return view('app.leads', ['leads' => $leads, 'statuses' => Lead::STATUSES, 'counts' => Lead::selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status')]);
    }

    public function storeLead(Request $request)
    {
        Lead::create($this->leadData($request));

        return back()->with('status', __('Lead added.'));
    }

    public function updateLead(Request $request, Lead $lead)
    {
        $lead->update($this->leadData($request, partial: true));

        return back()->with('status', __('Lead updated.'));
    }

    public function destroyLead(Lead $lead)
    {
        $lead->delete();

        return back()->with('status', __('Lead deleted.'));
    }

    protected function leadData(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', Rule::in(Lead::STATUSES)],
            'value' => ['nullable', 'numeric'],
            'source' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string'],
        ]);
    }

    public function tasks(Request $request)
    {
        $tasks = Task::with('lead')
            ->when($request->query('status', 'open'), fn ($q, $s) => $s === 'all' ? $q : $q->where('status', $s))
            ->orderByRaw('due_date IS NULL, due_date')->paginate(50)->withQueryString();

        return view('app.tasks', ['tasks' => $tasks]);
    }

    public function storeTask(Request $request)
    {
        Task::create($request->validate([
            'title' => 'required|string|max:191',
            'due_date' => 'nullable|date',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'assignee' => 'nullable|string|max:120',
        ]));

        return back()->with('status', __('Task added.'));
    }

    public function toggleTask(Task $task)
    {
        $task->update(['status' => $task->status === 'done' ? 'open' : 'done']);

        return back();
    }

    public function destroyTask(Task $task)
    {
        $task->delete();

        return back()->with('status', __('Task deleted.'));
    }
}
