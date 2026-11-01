<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** OWASP-recommended response headers incl. a nonce-free strict CSP for the app. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));
        view()->share('cspNonce', $nonce);
        $response = $next($request);
        $h = $response->headers;

        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $h->set('Cross-Origin-Opener-Policy', 'same-origin');
        $h->remove('X-Powered-By');

        if ($request->isSecure() || config('brain.security.force_https')) {
            $h->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
        }

        // The widget is embedded on customer sites; everything else must never be framed.
        $public = $request->is('widget.js', 'widget/*', 'channels/*', 'stripe/*');
        if (! $public) {
            $h->set('X-Frame-Options', 'DENY');
            if (Str::contains((string) $h->get('Content-Type'), 'text/html')) {
                $h->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'none'; form-action 'self' https://checkout.stripe.com https://billing.stripe.com; base-uri 'self'; object-src 'none'");
                // Ratchet toward a strict CSP: report-only until the inline on* handlers are gone (ADR 0030).
                $h->set('Content-Security-Policy-Report-Only', "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
            }
        }

        // Authenticated pages must not be cached by shared caches / back button.
        if ($request->user()) {
            $h->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
