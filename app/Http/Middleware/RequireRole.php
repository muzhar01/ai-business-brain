<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** role:admin -> owner or admin; role:owner -> owner only. */
class RequireRole
{
    public function handle(Request $request, Closure $next, string $role = 'admin'): Response
    {
        $current = $request->attributes->get('workspace_role');
        $allowed = $role === 'owner' ? ['owner'] : ['owner', 'admin'];

        abort_unless(in_array($current, $allowed, true) || $request->user()?->is_admin, 403, 'Only workspace '.($role === 'owner' ? 'owners' : 'admins').' can do this.');

        return $next($request);
    }
}
