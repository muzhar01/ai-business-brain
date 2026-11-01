<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnforceSecurityPolicy;
use App\Http\Middleware\RequireFeature;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentWorkspace;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SuperAdmin;
use App\Support\Locale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\App;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.token' => AuthenticateApiToken::class,
            'workspace' => SetCurrentWorkspace::class,
            'role' => RequireRole::class,
            'feature' => RequireFeature::class,
            'superadmin' => SuperAdmin::class,
            'security.policy' => EnforceSecurityPolicy::class,
        ]);
        $middleware->append(SecurityHeaders::class);
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*') === '*' ? '*' : explode(',', env('TRUSTED_PROXIES')));
        // The tenant must be known before route-model binding so lookups are scoped.
        $middleware->prependToPriorityList(SubstituteBindings::class, AuthenticateApiToken::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, SetCurrentWorkspace::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnforceSecurityPolicy::class);
        // Interface language (ADR 0001): web group, but after the tenant is known so its default applies.
        $middleware->web(append: [SetLocale::class]);
        $middleware->prependToPriorityList(EnforceSecurityPolicy::class, SetLocale::class);
        $middleware->validateCsrfTokens(except: ['stripe/*', 'channels/*', 'widget/*']);
        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Unmatched routes skip the web group (no SetLocale): still show error pages in the browser's language.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->route()) {
                App::setLocale(Locale::fromAcceptLanguage($request) ?? Locale::fallback());
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'widget/*') || $request->expectsJson(),
        );
    })->create();
