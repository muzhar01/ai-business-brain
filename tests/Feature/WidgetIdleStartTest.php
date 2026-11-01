<?php

namespace Tests\Feature;

use Tests\TestCase;

class WidgetIdleStartTest extends TestCase
{
    protected function js(): string
    {
        return $this->get('/widget.js')->assertOk()->getContent();
    }

    public function test_boot_waits_for_load_and_idle(): void
    {
        $js = $this->js();
        $this->assertStringContainsString("document.readyState === 'complete'", $js);
        $this->assertStringContainsString("addEventListener('load', go)", $js);
        $this->assertStringContainsString('requestIdleCallback(boot, { timeout: 3000 })', $js);
        $this->assertStringContainsString('setTimeout(boot, 200)', $js);
    }

    public function test_config_failure_is_swallowed(): void
    {
        $js = $this->js();
        $this->assertStringContainsString("if (!r.ok) throw new Error('config')", $js);
        $this->assertStringContainsString('}).catch(function () {});', $js);
    }

    public function test_adr_exists(): void
    {
        $this->assertFileExists(base_path('docs/adr/0028-widget-idle-start.md'));
    }
}
