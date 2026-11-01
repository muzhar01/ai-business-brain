<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** "Authorization: Bearer brn_..." (or X-Brain-Key) -> the token's workspace becomes the tenant. */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = ApiToken::findByPlain($request->bearerToken() ?? $request->header('X-Brain-Key'));

        if (! $token || ! $token->workspace) {
            return response()->json(['message' => 'Invalid or missing API token. Create one in Settings > API.'], 401);
        }
        if ($token->workspace->suspended) {
            return response()->json(['message' => 'Workspace suspended.'], 403);
        }
        if (! $token->workspace->plan()->has('api')) {
            return response()->json(['message' => 'Your plan does not include API access. Upgrade to unlock it.'], 402);
        }

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        Tenant::set($token->workspace);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }

    public function terminate(): void
    {
        Tenant::forget();
    }
}
