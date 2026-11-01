<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Usage;
use App\Models\User;
use App\Models\Workspace;
use App\Saas\Plan;
use Illuminate\Http\Request;

/** Platform owner console: every tenant, their plan, usage and status. */
class AdminController extends Controller
{
    public function index(Request $request)
    {
        $period = now()->format('Y-m');
        $workspaces = Workspace::with(['owner', 'subscriptions'])
            ->withCount('members')
            ->when($request->query('q'), fn ($q, $t) => $q->where('name', 'like', "%{$t}%"))
            ->latest()->paginate(40)->withQueryString();

        $usage = Usage::where('period', $period)->whereIn('workspace_id', $workspaces->pluck('id'))->get()->keyBy('workspace_id');

        return view('app.admin', [
            'workspaces' => $workspaces,
            'usage' => $usage,
            'plans' => Plan::all(),
            'totals' => [
                'workspaces' => Workspace::count(),
                'users' => User::count(),
                'messages' => (int) Usage::where('period', $period)->sum('messages'),
                'tokens' => (int) Usage::where('period', $period)->sum('input_tokens') + (int) Usage::where('period', $period)->sum('output_tokens'),
                'mrr' => Workspace::with('subscriptions')->get()->sum(fn ($w) => $w->plan_override ? 0 : (($w->subscription('default')?->valid()) ? $w->plan()->config['price'] : 0)),
            ],
        ]);
    }

    public function update(Request $request, Workspace $workspace)
    {
        $data = $request->validate(['plan_override' => 'nullable|in:'.implode(',', array_keys(config('saas.plans'))), 'suspended' => 'nullable|boolean']);
        $workspace->update(['plan_override' => $data['plan_override'] ?: null, 'suspended' => $request->boolean('suspended')]);

        return back()->with('status', __(':name updated.', ['name' => $workspace->name]));
    }

    public function enter(Request $request, Workspace $workspace)
    {
        $workspace->members()->syncWithoutDetaching([$request->user()->id => ['role' => 'admin']]);
        $request->user()->forceFill(['current_workspace_id' => $workspace->id])->save();

        return redirect()->route('dashboard')->with('status', __('You joined :name as admin (support access).', ['name' => $workspace->name]));
    }
}
