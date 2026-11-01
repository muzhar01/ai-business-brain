<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CspNonceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_inline_script_carries_a_nonce(): void
    {
        foreach (glob(base_path('resources/views/**/*.blade.php')) + glob(base_path('resources/views/*/*/*.blade.php')) as $f) {
            $this->assertDoesNotMatchRegularExpression('/<script>/', file_get_contents($f), $f);
        }
    }

    public function test_report_only_policy_uses_matching_fresh_nonce(): void
    {
        [$user] = $this->tenant();
        $this->actingAs($user);
        $a = $this->get(route('dashboard'));
        $b = $this->get(route('dashboard'));
        $csp = $a->headers->get('Content-Security-Policy-Report-Only');
        preg_match("/'nonce-([^']+)'/", $csp, $m);
        $this->assertNotEmpty($m[1]);
        $this->assertStringContainsString('nonce="'.$m[1].'"', $a->getContent());
        preg_match("/'nonce-([^']+)'/", $b->headers->get('Content-Security-Policy-Report-Only'), $m2);
        $this->assertNotSame($m[1], $m2[1]);
        $this->assertStringContainsString("'unsafe-inline'", $a->headers->get('Content-Security-Policy'));
    }
}
