<?php

namespace Tests\Feature;

use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** API auth contract per endpoint, and security + accessibility basics per page. */
class ContractMatrixTest extends TestCase
{
    use RefreshDatabase;

    public const API = [
        ['get', 'providers'], ['get', 'agents'], ['post', 'chat'], ['get', 'conversations'], ['get', 'documents'], ['post', 'documents'],
        ['get', 'search?q=x'], ['get', 'actions'], ['get', 'leads'], ['get', 'tasks'], ['get', 'report'], ['post', 'imports'],
        ['post', 'exports'], ['get', 'datasets'], ['get', 'conversations/x'], ['get', 'imports/1'], ['get', 'exports/1'],
        ['get', 'datasets/1/rows'], ['post', 'datasets/1/rows'], ['post', 'messages/1/rate'],
    ];

    public static function api(): iterable
    {
        foreach (self::API as [$m, $p]) {
            foreach (['none' => 401, 'forged' => 401, 'suspended' => 403, 'free' => 402] as $state => $status) {
                yield "{$state} {$m} {$p}" => [$m, $p, $state, $status];
            }
            yield "valid {$m} {$p}" => [$m, $p, 'valid', null];
        }
    }

    #[DataProvider('api')]
    public function test_api_auth_contract(string $method, string $path, string $state, ?int $status): void
    {
        [, $ws, $token] = $this->tenant($state === 'free' ? 'free' : 'business');
        if ($state === 'suspended') {
            $ws->update(['suspended' => true]);
        }
        Tenant::forget();
        $headers = match ($state) {
            'none' => [],
            'forged' => ['Authorization' => 'Bearer brn_'.str_repeat('x', 40)],
            default => ['Authorization' => "Bearer {$token}"],
        };

        $response = $this->json($method, "/api/brain/{$path}", [], $headers);

        if ($status !== null) {
            $response->assertStatus($status);
        } else {
            $this->assertContains($response->status(), [200, 201, 202, 404, 422], "valid token got {$response->status()}");
        }
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public const PAGES = ['/dashboard', '/chat', '/knowledge', '/approvals', '/agents', '/agents/create', '/leads', '/tasks', '/datasets', '/imports', '/imports/create', '/exports',
        '/profile', '/profile/security', '/settings', '/settings/providers', '/settings/api', '/settings/webhooks', '/settings/channels', '/settings/team', '/settings/privacy', '/settings/data', '/billing', '/admin'];

    public static function pages(): iterable
    {
        foreach (self::PAGES as $p) {
            foreach (['frame', 'nosniff', 'csp', 'no-store', 'referrer', 'one-h1', 'title', 'main-landmark', 'skip-link', 'lang'] as $check) {
                yield "{$p} {$check}" => [$p, $check];
            }
        }
    }

    #[DataProvider('pages')]
    public function test_page_contract(string $path, string $check): void
    {
        [$user] = $this->tenant();
        $user->forceFill(['is_admin' => true])->save();
        Tenant::forget();
        $r = $this->actingAs($user)->get($path)->assertOk();
        $html = $r->getContent();

        match ($check) {
            'frame' => $r->assertHeader('X-Frame-Options', 'DENY'),
            'nosniff' => $r->assertHeader('X-Content-Type-Options', 'nosniff'),
            'csp' => $this->assertStringContainsString("object-src 'none'", (string) $r->headers->get('Content-Security-Policy')),
            'no-store' => $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control')),
            'referrer' => $r->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin'),
            'one-h1' => $this->assertSame(1, substr_count($html, '<h1'), 'exactly one h1'),
            'title' => $this->assertMatchesRegularExpression('/<title>[^<]{3,}<\/title>/', $html),
            'main-landmark' => $this->assertStringContainsString('<main', $html),
            'skip-link' => $this->assertStringContainsString('href="#main"', $html),
            'lang' => $this->assertStringContainsString('<html lang="en" dir="ltr">', $html),
        };
    }
}
