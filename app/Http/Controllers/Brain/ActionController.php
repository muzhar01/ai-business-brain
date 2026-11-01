<?php

namespace App\Http\Controllers\Brain;

use App\Brain\Brain;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PendingAction;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;

class ActionController extends Controller
{
    public function index(Request $request)
    {
        return PendingAction::query()
            ->where('status', $request->query('status', 'pending'))
            ->with('conversation:id,uuid,title')
            ->latest()->paginate(50);
    }

    public function approve(Request $request, PendingAction $action, Brain $brain)
    {
        $this->authorizeDecision($request);
        $result = $brain->approve($action, $request->user()?->name ?? $request->input('decided_by', 'api'));
        AuditLog::record('action.approved', $action, ['tool' => $action->tool]);

        return $result;
    }

    public function reject(Request $request, PendingAction $action, Brain $brain)
    {
        $this->authorizeDecision($request);
        $result = $brain->reject($action, $request->user()?->name ?? $request->input('decided_by', 'api'));
        AuditLog::record('action.rejected', $action, ['tool' => $action->tool]);

        return $result;
    }

    /** Web users must be workspace admins; API tokens are trusted. */
    protected function authorizeDecision(Request $request): void
    {
        if ($user = $request->user()) {
            abort_unless($user->canManage(Tenant::current()) || $user->is_admin, 403, 'Only admins can approve actions.');
        }
    }
}
