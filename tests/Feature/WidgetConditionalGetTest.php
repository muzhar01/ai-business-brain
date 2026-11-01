<?php

namespace Tests\Feature;

use Tests\TestCase;

class WidgetConditionalGetTest extends TestCase
{
    public function test_script_has_etag_and_cache_headers(): void
    {
        $r = $this->get('/widget.js')->assertOk();
        $this->assertNotEmpty($r->headers->get('ETag'));
        $this->assertStringContainsString('stale-while-revalidate', $r->headers->get('Cache-Control'));
        $this->assertSame('cross-origin', $r->headers->get('Cross-Origin-Resource-Policy'));
        $this->assertStringContainsString('javascript', $r->headers->get('Content-Type'));
    }

    public function test_matching_etag_returns_304_without_body(): void
    {
        $etag = $this->get('/widget.js')->headers->get('ETag');
        foreach ([$etag, 'W/'.$etag, '"zzz", '.$etag] as $h) {
            $r = $this->get('/widget.js', ['If-None-Match' => $h]);
            $r->assertStatus(304);
            $this->assertSame('', $r->getContent());
        }
    }

    public function test_other_etag_returns_full_body(): void
    {
        $this->get('/widget.js', ['If-None-Match' => '"nope"'])->assertOk();
    }
}
