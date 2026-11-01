<?php

namespace App\Http\Middleware;

use App\Saas\Quota;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        Quota::feature(Tenant::current(), $feature);

        return $next($request);
    }
}
