<?php

namespace Tests\Feature;

use Tests\TestCase;

class ForcedColorsTest extends TestCase
{
    private function block(): string
    {
        $css = file_get_contents(public_path('css/app.css'));
        $this->assertSame(1, preg_match('/@media \(forced-colors:active\)\{\n(.*?)\n\}/s', $css, $m));

        return $m[1];
    }

    public function test_state_selectors_are_covered_with_system_colours(): void
    {
        $b = $this->block();
        foreach (['.btn{', '.cmdk-list [aria-selected=true]', 'mark.hit', '.msg{', '.pill{', ':focus-visible'] as $s) {
            $this->assertStringContainsString($s, $b);
        }
        foreach (['Highlight', 'HighlightText', 'CanvasText', 'ButtonText', 'GrayText'] as $k) {
            $this->assertStringContainsString($k, $b);
        }
    }

    public function test_block_uses_no_hex_colours_and_never_opts_out(): void
    {
        $b = $this->block();
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $b);
        $css = file_get_contents(public_path('css/app.css'));
        $this->assertStringNotContainsString('forced-color-adjust:none', $css);
    }

    public function test_block_uses_logical_properties_only(): void
    {
        $this->assertDoesNotMatchRegularExpression('/-(left|right)\b|(left|right):/', $this->block());
    }
}
