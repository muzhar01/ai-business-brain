<?php

namespace App\Support;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;

/**
 * Interface language (ADR 0001). The allow-list lives in config('app.supported_locales');
 * nothing outside it ever reaches App::setLocale(), so no request value can pick a lang file path.
 * Direction (rtl/ltr, <html lang>) keeps being derived from the app locale by Direction.
 */
final class Locale
{
    /** @return array<string, string> code => name in its own language */
    public static function supported(): array
    {
        return config('app.supported_locales', ['en' => 'English']);
    }

    public static function isSupported(mixed $locale): bool
    {
        return is_string($locale) && array_key_exists($locale, self::supported());
    }

    /** Normalise a tag (ur-PK, AR, ar_SA) to a supported code, or null. */
    public static function match(mixed $tag): ?string
    {
        if (! is_string($tag) || $tag === '' || strlen($tag) > 35) {
            return null;
        }
        $tag = strtolower(str_replace('_', '-', trim($tag)));
        if (self::isSupported($tag)) {
            return $tag;
        }
        $primary = explode('-', $tag)[0];

        return self::isSupported($primary) ? $primary : null;
    }

    /** First browser language we support, honouring q-values (Symfony sorts them). */
    public static function fromAcceptLanguage(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            if ($code = self::match($language)) {
                return $code;
            }
        }

        return null;
    }

    /** app.fallback_locale, not app.locale: App::setLocale() overwrites app.locale per request (long-lived workers, tests). */
    public static function fallback(): string
    {
        return self::match(config('app.fallback_locale')) ?? 'en';
    }

    /** User preference → session choice → workspace default → Accept-Language → config. */
    public static function resolve(?User $user, mixed $session, ?Workspace $workspace, ?Request $request = null): string
    {
        return self::match($user?->locale)
            ?? self::match($session)
            ?? self::match($workspace?->setting('language'))
            ?? ($request ? self::fromAcceptLanguage($request) : null)
            ?? self::fallback();
    }
}
