<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Locale-aware date output for views (ADR 0018). Carbon follows App::setLocale(), so isoFormat gives
 * month names in the active interface language; strftime-style format() strings stay English.
 */
final class Format
{
    /** 31 Oct 2026 / 30 أكتوبر 2026 */
    public static function date(?CarbonInterface $d): string
    {
        return $d ? $d->isoFormat('ll') : '';
    }

    /** Oct 31 (no year) for default names and chips. */
    public static function shortDate(?CarbonInterface $d): string
    {
        return $d ? $d->isoFormat('MMM D') : '';
    }

    /** Date plus 24 h time, for audit rows. */
    public static function dateTime(?CarbonInterface $d): string
    {
        return $d ? $d->isoFormat('MMM D, HH:mm') : '';
    }
}
