<?php

namespace App\Http\Middleware;

use App\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-workspace security policy (SOC 2 CC6 / HIPAA §164.312):
 *  - idle session timeout (automatic logoff)
 *  - mandatory two-factor authentication
 */
class EnforceSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $ws = Tenant::current();
        if (! $user || ! $ws) {
            return $next($request);
        }

        $timeout = (int) ($ws->setting('hipaa_mode') ? min(15, (int) $ws->setting('session_timeout', 15) ?: 15) : $ws->setting('session_timeout', 0));
        $last = (int) $request->session()->get('last_activity', time());
        if ($timeout > 0 && time() - $last > $timeout * 60) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect('/login')->with('status', __('You were signed out after :minutes minutes of inactivity.', ['minutes' => $timeout]));
        }
        $request->session()->put('last_activity', time());

        $mustHave2fa = $ws->setting('require_2fa') || $ws->setting('hipaa_mode');
        if ($mustHave2fa && ! $user->two_factor_confirmed_at && ! $request->routeIs('profile*', 'logout', 'workspaces.switch')) {
            return redirect()->route('profile.security')->with('status', __('Your workspace requires two-factor authentication. Set it up to continue.'));
        }

        return $next($request);
    }
}
