<?php

namespace Tests\Feature;

use Tests\TestCase;

class ShortcutSheetTest extends TestCase
{
    private function p(): string
    {
        return file_get_contents(resource_path('views/partials/shortcut-sheet.blade.php'));
    }

    public function test_layout_includes_sheet(): void
    {
        $this->assertStringContainsString("@include('partials.shortcut-sheet')", file_get_contents(resource_path('views/layouts/app.blade.php')));
    }

    public function test_dialog_is_labelled_and_closable(): void
    {
        $p = $this->p();
        foreach (['<dialog', 'aria-labelledby="shortcuts-title"', 'method="dialog"', '<dl'] as $s) {
            $this->assertStringContainsString($s, $p);
        }
    }

    public function test_ignores_typing_and_modifiers_and_ime(): void
    {
        $p = $this->p();
        foreach (['TEXTAREA', 'isContentEditable', 'isComposing', 'e.ctrlKey', 'dialog[open]'] as $s) {
            $this->assertStringContainsString($s, $p);
        }
        $this->assertStringNotContainsString('innerHTML', $p);
    }

    public function test_css_logical_and_translations(): void
    {
        $this->assertStringContainsString('.shortcuts-close{', file_get_contents(public_path('css/app.css')));
        foreach (['en', 'ur', 'ar'] as $l) {
            $j = json_decode(file_get_contents(lang_path("$l.json")), true);
            foreach (['Keyboard shortcuts', 'Show this sheet', 'Close a dialog'] as $k) {
                $this->assertNotEmpty($j[$k] ?? '', "$l:$k");
            }
        }
    }
}
