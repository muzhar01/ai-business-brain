<?php

namespace App\Support;

/**
 * Text direction for a locale. Layouts put the result on <html dir>, so flexbox, grid,
 * tables and CSS logical properties flip automatically for Arabic, Persian, Urdu, Hebrew…
 * (RTL Styling 101: set dir on the root element, never rely on per-element overrides).
 */
final class Direction
{
    /** Primary language subtags written right-to-left. */
    public const RTL = ['ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'ks', 'ps', 'sd', 'ug', 'ur', 'yi'];

    public static function of(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $primary = strtolower(preg_split('/[-_]/', $locale)[0] ?? '');

        return in_array($primary, self::RTL, true) ? 'rtl' : 'ltr';
    }

    /** BCP 47 language tag for the lang attribute (en_US → en-US). */
    public static function lang(?string $locale = null): string
    {
        return str_replace('_', '-', $locale ?? app()->getLocale());
    }
}
