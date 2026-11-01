<?php

namespace App\Etl;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * TRANSFORM: pipe-separated steps applied to a mapped value.
 *   trim | lower | upper | title | slug | strip_html | number | integer | boolean
 *   date | datetime | phone | email | digits | nullify | replace:a,b | prefix:x | suffix:x
 *   map:won=Won,lost=Lost (value lookup) | truncate:100 | split:,:0 (take nth part)
 * Sources can be a column ("Email") or a template combining columns ("{First} {Last}").
 */
class Transformer
{
    public const STEPS = ['trim', 'lower', 'upper', 'title', 'slug', 'strip_html', 'number', 'integer', 'boolean', 'date', 'datetime', 'phone', 'email', 'digits', 'nullify', 'replace:a,b', 'prefix:x', 'suffix:x', 'map:a=b,c=d', 'truncate:n', 'split:,:0'];

    public static function source(array $row, ?string $source): mixed
    {
        if ($source === null || $source === '') {
            return null;
        }
        if (str_contains($source, '{')) {
            return trim(preg_replace('/\s+/', ' ', preg_replace_callback('/\{([^}]+)\}/', fn ($m) => trim((string) ($row[$m[1]] ?? '')), $source)));
        }

        return $row[$source] ?? null;
    }

    public static function apply(mixed $value, ?string $pipeline): mixed
    {
        foreach (array_filter(array_map('trim', explode('|', (string) $pipeline))) as $step) {
            [$name, $arg] = array_pad(explode(':', $step, 2), 2, null);
            $value = self::step($value, $name, $arg);
        }

        return $value;
    }

    protected static function step(mixed $v, string $name, ?string $arg): mixed
    {
        if ($v === null && ! in_array($name, ['nullify'], true)) {
            return null;
        }
        $s = is_scalar($v) ? (string) $v : json_encode($v);

        return match ($name) {
            'trim' => trim($s),
            'lower' => mb_strtolower($s),
            'upper' => mb_strtoupper($s),
            'title' => Str::title(mb_strtolower($s)),
            'slug' => Str::slug($s),
            'strip_html' => trim(html_entity_decode(strip_tags($s))),
            'number' => self::number($s),
            'integer' => ($n = self::number($s)) === null ? null : (int) round($n),
            'boolean' => in_array(mb_strtolower(trim($s)), ['1', 'true', 'yes', 'y', 'on', 'x'], true),
            'date' => self::date($s, 'Y-m-d'),
            'datetime' => self::date($s, 'Y-m-d H:i:s'),
            'phone' => ($p = preg_replace('/[^\d+]/', '', $s)) === '' ? null : $p,
            'digits' => preg_replace('/\D/', '', $s),
            'email' => filter_var($e = mb_strtolower(trim($s)), FILTER_VALIDATE_EMAIL) ? $e : null,
            'nullify' => trim($s) === '' || in_array(mb_strtolower(trim($s)), ['null', 'n/a', 'na', '-', 'none'], true) ? null : $v,
            'replace' => str_replace(explode(',', (string) $arg, 2)[0], explode(',', (string) $arg, 2)[1] ?? '', $s),
            'prefix' => $arg.$s,
            'suffix' => $s.$arg,
            'truncate' => mb_substr($s, 0, (int) $arg),
            'split' => self::split($s, (string) $arg),
            'map' => self::lookup($s, (string) $arg),
            default => $v,
        };
    }

    protected static function number(string $s): ?float
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        $clean = preg_replace('/[^\d.,\-]/', '', $s);
        // "1.234,56" (EU) vs "1,234.56" (US)
        if (preg_match('/,\d{1,2}$/', $clean) && str_contains($clean, '.')) {
            $clean = str_replace(['.', ','], ['', '.'], $clean);
        } elseif (preg_match('/^\-?\d+,\d{1,2}$/', $clean)) {
            $clean = str_replace(',', '.', $clean);
        } else {
            $clean = str_replace(',', '', $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }

    protected static function date(string $s, string $format): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        if (is_numeric($s) && (float) $s > 20000 && (float) $s < 80000) { // Excel serial date
            return Carbon::create(1899, 12, 30)->addDays((int) $s)->format($format);
        }
        try {
            return Carbon::parse(str_replace('/', '-', $s))->format($format);
        } catch (\Throwable) {
            return null;
        }
    }

    protected static function split(string $s, string $arg): ?string
    {
        $sep = $arg === '' ? ',' : substr($arg, 0, 1);
        $index = (int) (explode(':', $arg)[1] ?? 0);

        return trim(explode($sep, $s)[$index] ?? '') ?: null;
    }

    protected static function lookup(string $s, string $arg): string
    {
        foreach (explode(',', $arg) as $pair) {
            [$from, $to] = array_pad(explode('=', $pair, 2), 2, '');
            if (mb_strtolower(trim($from)) === mb_strtolower(trim($s))) {
                return trim($to);
            }
        }

        return $s;
    }
}
