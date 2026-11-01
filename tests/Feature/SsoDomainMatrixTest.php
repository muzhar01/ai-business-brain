<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\StreamingMatrixTest;

class SsoDomainMatrixTest extends TestCase
{
    use RefreshDatabase;

    public static function domains(): array
    {
        return StreamingMatrixTest::domains();
    }

    #[DataProvider('domains')]
    public function test_sso_domain_validation(string $domain, bool $ok): void
    {
        [$user, $ws] = $this->tenant();
        $res = $this->actingAs($user)->post('/settings/sso', ['sso_domain' => $domain, 'sso_default_role' => 'member']);
        $ok ? $res->assertSessionHasNoErrors() : $res->assertSessionHasErrors('sso_domain');
        $this->assertSame($ok && $domain !== '' ? strtolower($domain) : null, $ws->fresh()->setting('sso_domain'));
    }
}
