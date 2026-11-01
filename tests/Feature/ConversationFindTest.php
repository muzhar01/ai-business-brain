<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConversationFindTest extends TestCase
{
    private function v(): string
    {
        return file_get_contents(resource_path('views/app/chat.blade.php'));
    }

    public function test_find_bar_is_accessible_and_hidden_by_default(): void
    {
        $v = $this->v();
        $this->assertStringContainsString('id="findbar" class="row" role="search" hidden', $v);
        $this->assertStringContainsString('aria-controls="findbar"', $v);
        $this->assertStringContainsString('aria-expanded="false"', $v);
        $this->assertStringContainsString('role="status" aria-live="polite"', $v);
        $this->assertStringContainsString("e.key === 'Escape'", $v);
        $this->assertStringContainsString('e.isComposing', $v);
    }

    public function test_matches_are_built_without_innerhtml(): void
    {
        $v = $this->v();
        $this->assertMatchesRegularExpression('/m\.textContent = t\.slice/', $v);
        $fn = substr($v, strpos($v, 'function runFind'), 900);
        $this->assertStringNotContainsString('innerHTML', $fn);
    }

    public function test_strings_translated_and_css_present(): void
    {
        foreach (['en', 'ur', 'ar'] as $l) {
            $j = json_decode(file_get_contents(base_path("lang/$l.json")), true);
            foreach (['Find', 'Find in conversation', 'Previous match', 'Next match', ':n of :total', 'No matches'] as $k) {
                $this->assertArrayHasKey($k, $j, "$l missing $k");
            }
        }
        $css = file_get_contents(public_path('css/app.css'));
        $this->assertStringContainsString('mark.hit.cur', $css);
        $this->assertStringContainsString('forced-colors:active', $css);
    }

    public function test_jump_to_date_is_wired_and_translated(): void
    {
        $v = $this->v();
        $this->assertStringContainsString('<input id="jumpd" type="date" onchange="jumpDate()">', $v);
        $this->assertStringContainsString('for="jumpd"', $v);
        $this->assertStringContainsString('d.dataset.ts = ts', $v);
        $this->assertStringContainsString('m.created_at', $v);
        $fn = substr($v, strpos($v, 'function jumpDate'), 700);
        $this->assertStringNotContainsString('innerHTML', $fn);
        foreach (['en', 'ur', 'ar'] as $l) {
            $j = json_decode(file_get_contents(base_path("lang/$l.json")), true);
            foreach (['Jump to date', 'No messages on or after that date', 'Jumped to :date'] as $k) {
                $this->assertArrayHasKey($k, $j, "$l missing $k");
            }
        }
    }
}
