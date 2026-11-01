<?php

namespace App\Http\Middleware;

use App\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $workspace = $user?->activeWorkspace();

        if (! $workspace) {
            return redirect()->route('workspaces.create');
        }
        if ($workspace->suspended && ! $user->is_admin) {
            abort(403, 'This workspace is suspended. Contact support.');
        }

        Tenant::set($workspace);
        $role = $user->roleIn($workspace);
        $request->attributes->set('workspace_role', $role);

        View::share(['workspace' => $workspace, 'role' => $role, 'plan' => $workspace->plan()]);

        return $next($request);
    }

    public function terminate(): void
    {
        Tenant::forget();
    }
}
