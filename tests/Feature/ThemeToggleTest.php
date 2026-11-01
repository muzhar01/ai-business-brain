<?php

namespace Tests\Feature;

use Tests\TestCase;

class ThemeToggleTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(public_path('css/app.css'));
    }

    public function test_css_supports_forced_and_system_light(): void
    {
        $css = $this->css();
        $this->assertStringContainsString('html[data-theme=light]{--acc-t:', $css);
        $this->assertStringContainsString(':root:not([data-theme=dark]){--acc-t:', $css);
        $this->assertStringContainsString('html[data-theme=dark]{color-scheme:dark}', $css);
    }

    public function test_layout_has_theme_switch_and_no_flash_script(): void
    {
        $v = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString("localStorage.getItem('brain-theme')", $v);
        $this->assertStringContainsString('id="themesw"', $v);
        $this->assertStringContainsString('aria-pressed', $v);
        $this->assertLessThan(strpos($v, 'rel="stylesheet"'), strpos($v, "getItem('brain-theme')"));
        foreach (['dark', 'light', 'system'] as $t) {
            $this->assertStringContainsString('data-theme="'.$t.'"', $v);
        }
    }

    public function test_switch_hidden_without_js(): void
    {
        $this->assertStringContainsString('.js-only{display:none}.js .js-only{display:flex}', $this->css());
    }
}
