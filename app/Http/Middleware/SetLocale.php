<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the interface language for the request (ADR 0001). Runs in the web group, ordered after
 * SetCurrentWorkspace (priority list) so the acting workspace's default is known; the tenant is only
 * ever the one SetCurrentWorkspace verified membership for.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(Locale::resolve(
            $request->user(),
            $request->hasSession() ? $request->session()->get('locale') : null,
            Tenant::current(),
            $request,
        ));

        $response = $next($request);
        $response->setVary('Accept-Language', false);

        return $response;
    }
}
