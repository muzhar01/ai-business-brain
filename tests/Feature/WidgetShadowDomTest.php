<?php

namespace Tests\Feature;

use Tests\TestCase;

class WidgetShadowDomTest extends TestCase
{
    protected function js(): string
    {
        return $this->get('/widget.js')->assertOk()->getContent();
    }

    public function test_widget_renders_inside_an_open_shadow_root(): void
    {
        $js = $this->js();
        $this->assertStringContainsString("attachShadow({ mode: 'open' })", $js);
        $this->assertStringContainsString(':host{all:initial}', $js);
        $this->assertStringContainsString('root.appendChild(w); root.appendChild(btn);', $js);
    }

    public function test_style_goes_into_the_root_and_falls_back_to_head(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('(host ? root : document.head).appendChild(st)', $js);
        $this->assertStringContainsString('if (host.attachShadow)', $js);
        $this->assertStringNotContainsString('document.head.appendChild(st);', $js);
    }

    public function test_adr_exists(): void
    {
        $this->assertFileExists(base_path('docs/adr/0027-widget-shadow-dom.md'));
    }
}
