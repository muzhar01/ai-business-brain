<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WidgetResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_has_translated_retry_strings(): void
    {
        $ws = $this->tenant()[1];
        foreach (['en' => 'Retry', 'ar' => 'إعادة المحاولة', 'ur' => 'دوبارہ کوشش کریں'] as $l => $v) {
            $this->getJson("/widget/{$ws->widget_key}/config?locale={$l}")->assertOk()
                ->assertJsonPath('strings.retry', $v)->assertJsonStructure(['strings' => ['offline_wait']]);
        }
    }

    public function test_script_handles_timeout_retry_and_offline(): void
    {
        $js = view('widget.script')->render();
        foreach (['AbortController', '20000', 'r.status >= 500', 'navigator.onLine', "'online'", 'tr.retry', "rb.type = 'button'", 'n < 1', 'margin-inline-start'] as $needle) {
            $this->assertStringContainsString($needle, $js);
        }
        $this->assertStringNotContainsString('innerHTML = tr', $js);
    }
}
