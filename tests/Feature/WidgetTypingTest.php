<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WidgetTypingTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_exposes_translated_typing_string(): void
    {
        $ws = $this->tenant()[1];
        foreach (['en' => 'Assistant is typing…', 'ar' => 'المساعد يكتب…', 'ur' => 'اسسٹنٹ ٹائپ کر رہا ہے…'] as $l => $v) {
            $this->getJson("/widget/{$ws->widget_key}/config?locale={$l}")->assertOk()->assertJsonPath('strings.typing', $v);
        }
    }

    public function test_script_has_accessible_indicator_and_mobile_sheet(): void
    {
        $js = view('widget.script')->render();
        foreach (['aria-busy', 'prefers-reduced-motion', 'max-width:480px', '100dvh', "e.key === 'Escape'", 'sb.disabled = true', 'tr.typing'] as $needle) {
            $this->assertStringContainsString($needle, $js);
        }
        $this->assertStringNotContainsString("add('…'", $js);
    }
}
