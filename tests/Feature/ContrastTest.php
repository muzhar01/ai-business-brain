<?php

namespace Tests\Feature;

use Tests\TestCase;

/** WCAG 1.4.3: text tokens meet 4.5:1 on their surfaces in both themes (ADR 0022). */
class ContrastTest extends TestCase
{
    private function lum(string $h): float
    {
        $h = ltrim($h, '#');
        $c = array_map(fn ($i) => hexdec(substr($h, $i, 2)) / 255, [0, 2, 4]);
        $c = array_map(fn ($x) => $x <= 0.03928 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4, $c);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private function ratio(string $a, string $b): float
    {
        [$x, $y] = [$this->lum($a), $this->lum($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    private function tokens(string $css, string $selectorStart): array
    {
        $this->assertSame(1, preg_match('/'.preg_quote($selectorStart, '/').'\{([^}]*)\}/', $css, $m), $selectorStart);
        preg_match_all('/--([a-z0-9-]+):(#[0-9a-fA-F]{3,6})/', $m[1], $t);

        return array_combine($t[1], $t[2]);
    }

    private function full(string $h): string
    {
        return strlen($h) === 4 ? '#'.$h[1].$h[1].$h[2].$h[2].$h[3].$h[3] : $h;
    }

    public function test_light_theme_text_tokens_meet_aa(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        $t = array_map(fn ($h) => $this->full($h), $this->tokens($css, 'html[data-theme=light]'));
        foreach (['text', 'muted', 'acc-t', 'ok-t', 'warn-t', 'bad-t'] as $fg) {
            foreach (['bg', 'panel', 'panel2'] as $bg) {
                $this->assertGreaterThanOrEqual(4.5, $this->ratio($t[$fg], $t[$bg]), "light $fg on $bg");
            }
        }
    }

    public function test_dark_theme_text_tokens_meet_aa(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        $t = array_map(fn ($h) => $this->full($h), $this->tokens($css, ':root'));
        foreach (['text', 'muted', 'acc', 'ok', 'warn', 'bad'] as $fg) {
            foreach (['bg', 'panel', 'panel2'] as $bg) {
                $this->assertGreaterThanOrEqual(4.5, $this->ratio($t[$fg], $t[$bg]), "dark $fg on $bg");
            }
        }
    }

    public function test_light_overrides_apply_in_both_light_paths_and_danger_button_is_dark_text(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        $this->assertSame(2, substr_count($css, '--acc-t:#3454d1'));
        $this->assertStringContainsString('.btn.danger{background:var(--bad);color:#0b0d12}', $css);
        $this->assertGreaterThanOrEqual(4.5, $this->ratio('#f2555a', '#0b0d12'));
    }

    public function test_hardcoded_foreground_background_pairs_meet_aa(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        $pairs = [
            ['.skip', '#000000', '#7c9cff'],
            ['.cmdk-list [aria-selected=true]', '#0b0f1a', '#7c9cff'],
            ['mark.hit.cur', '#111111', '#ffd75e'],
            ['.btn', '#0b0d12', '#7c9cff'],
        ];
        foreach ($pairs as [$sel, $fg, $bg]) {
            $this->assertStringContainsString($sel, $css, $sel);
            $this->assertGreaterThanOrEqual(4.5, $this->ratio($fg, $bg), "$sel $fg on $bg");
        }
        $this->assertStringContainsString('--acc:#7c9cff', $css);
    }
}
