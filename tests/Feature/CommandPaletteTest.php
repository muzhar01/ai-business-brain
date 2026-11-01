<?php

namespace Tests\Feature;

use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommandPaletteTest extends TestCase
{
    use RefreshDatabase;

    private function partial(): string
    {
        return file_get_contents(resource_path('views/partials/command-palette.blade.php'));
    }

    public function test_layout_includes_palette_and_trigger(): void
    {
        $v = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString("@include('partials.command-palette')", $v);
        $this->assertStringContainsString('data-cmdk-open', $v);
        $this->assertStringContainsString('aria-haspopup="dialog"', $v);
    }

    public function test_combobox_aria_pattern(): void
    {
        $p = $this->partial();
        foreach (['role="combobox"', 'aria-controls="cmdk-list"', 'aria-activedescendant', 'role="listbox"', "setAttribute('role', 'option')", 'aria-selected', '<dialog', 'role="status"'] as $s) {
            $this->assertStringContainsString($s, $p);
        }
    }

    public function test_keyboard_and_safety(): void
    {
        $p = $this->partial();
        $this->assertStringContainsString('metaKey || e.ctrlKey', $p);
        $this->assertStringContainsString('isComposing', $p);
        $this->assertStringContainsString('ArrowDown', $p);
        $this->assertStringNotContainsString('innerHTML', $p);
    }

    public function test_palette_renders_on_app_pages_and_strings_translated(): void
    {
        [$user] = $this->tenant();
        Tenant::forget();
        foreach (['/dashboard', '/chat', '/tasks'] as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'id="cmdk"'));
            Tenant::forget();
        }
        foreach (['en', 'ur', 'ar'] as $l) {
            $d = json_decode(file_get_contents(lang_path("$l.json")), true);
            foreach (['Command palette', 'Jump to a page…', 'Pages', 'No matching pages'] as $k) {
                $this->assertArrayHasKey($k, $d);
            }
        }
    }

    public function test_css_uses_logical_properties_and_dvh(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        $this->assertStringContainsString('.cmdk{', $css);
        $this->assertStringContainsString('70dvh', $css);
        $this->assertStringContainsString('.cmdk::backdrop', $css);
    }
}
