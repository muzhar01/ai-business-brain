<?php

namespace Tests\Feature;

use Tests\TestCase;

class WidgetThemingTest extends TestCase
{
    private function js(): string
    {
        return file_get_contents(resource_path('views/widget/script.blade.php'));
    }

    public function test_text_colour_is_chosen_by_contrast_and_colour_validated(): void
    {
        $j = $this->js();
        $this->assertStringContainsString('/^#[0-9a-f]{6}$/i.test(cfg.color', $j);
        $this->assertStringContainsString("? '#fff' : '#000'", $j);
        $this->assertStringNotContainsString('color:#fff;border:0;font-size:24px', $j);
    }

    public function test_dark_and_forced_colors_blocks_exist(): void
    {
        $j = $this->js();
        $this->assertStringContainsString('prefers-color-scheme:dark', $j);
        $this->assertStringContainsString('forced-colors:active', $j);
        foreach (['CanvasText', 'ButtonText', 'GrayText', 'Highlight'] as $k) {
            $this->assertStringContainsString($k, $j);
        }
        $this->assertStringNotContainsString('forced-color-adjust:none', $j);
    }

    public function test_input_focus_is_visible_and_no_physical_sides_added(): void
    {
        $j = $this->js();
        $this->assertStringContainsString('#bw input:focus-visible', $j);
        $this->assertStringNotContainsString('outline:none', $j);
    }
}
