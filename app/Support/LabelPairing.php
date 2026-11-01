<?php

namespace App\Support;

/**
 * Blade precompiler (ADR 0021): turns `<label>Text</label><input|select|textarea …>` (adjacent, no `for`, control has no
 * id) into a programmatically associated pair (WCAG 1.3.1 / 4.1.2). Ids are random per render so repeated partials
 * and loops never collide.
 */
final class LabelPairing
{
    public static function apply(string $template): string
    {
        $n = 0;

        return preg_replace_callback(
            '/<label((?:\s(?![^>]*\sfor=)[^>]*)?)>((?:(?!<\/label>|<input|<select|<textarea).)*)<\/label>(\s*)<(input|select|textarea)\b((?:(?!\sid=)[^>])*)>/s',
            function (array $m) use (&$n) {
                $v = '$__lp'.(++$n);
                $id = "{{ {$v} = 'f'.\\Illuminate\\Support\\Str::random(8) }}";

                return "<label for=\"{$id}\"{$m[1]}>{$m[2]}</label>{$m[3]}<{$m[4]} id=\"{{ {$v} }}\"{$m[5]}>";
            },
            $template
        ) ?? $template;
    }
}
