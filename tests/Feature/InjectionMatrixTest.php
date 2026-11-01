<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Dataset;
use App\Models\DatasetRow;
use App\Models\Import;
use App\Models\Lead;
use App\Models\PendingAction;
use App\Models\Task;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Stored XSS on every page that renders tenant data, and SQL injection on every search box. */
class InjectionMatrixTest extends TestCase
{
    use RefreshDatabase;

    public const XSS = [
        '<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '<svg/onload=alert(1)>', '"><script>alert(1)</script>',
        "'><img src=x onerror=alert(1)>", '<iframe src=javascript:alert(1)>', '<body onload=alert(1)>', '<a href="javascript:alert(1)">x</a>',
        '<details open ontoggle=alert(1)>', '<math><mtext></form><form><mglyph><style></math><img src onerror=alert(1)>',
        '{{ 7*7 }}<b>bold</b>', '<input autofocus onfocus=alert(1)>', '<video><source onerror=alert(1)>', '<marquee onstart=alert(1)>',
        '</textarea><script>alert(1)</script>', '<object data=javascript:alert(1)>', '<<script>script>alert(1)<</script>/script>',
        '<scr<script>ipt>alert(1)</script>', '<div style="background:url(javascript:alert(1))">', '"onmouseover="alert(1)',
    ];

    public static function xss(): iterable
    {
        $pages = ['leads', 'tasks', 'dataset', 'agents', 'imports', 'dashboard', 'approvals', 'import-map'];
        foreach ($pages as $page) {
            foreach (self::XSS as $i => $payload) {
                yield "{$page} #{$i}" => [$page, $payload];
            }
        }
    }

    #[DataProvider('xss')]
    public function test_payload_is_escaped(string $page, string $payload): void
    {
        [$user] = $this->tenant();
        $url = match ($page) {
            'leads' => tap('/leads', fn () => Lead::create(['name' => $payload, 'company' => $payload, 'notes' => $payload])),
            'tasks' => tap('/tasks', fn () => Task::create(['title' => $payload, 'description' => $payload, 'assignee' => $payload])),
            'dataset' => '/datasets/'.tap(Dataset::create(['name' => $payload, 'fields' => [['name' => 'f', 'type' => 'string']]]), fn ($d) => DatasetRow::create(['dataset_id' => $d->id, 'data' => ['f' => $payload]]))->id,
            'agents' => tap('/agents', fn () => Agent::create(['name' => $payload, 'description' => $payload])),
            'imports' => tap('/imports', fn () => Import::create(['name' => $payload, 'target' => 'leads'])),
            'dashboard' => tap('/dashboard', fn () => Conversation::create(['title' => $payload])),
            'approvals' => tap('/approvals', fn () => PendingAction::create(['tool' => 'send_email', 'arguments' => ['body' => $payload], 'status' => 'pending'])),
            'import-map' => '/imports/'.Import::create(['name' => $payload, 'target' => 'dataset', 'columns' => [$payload], 'mapping' => [['source' => $payload, 'target' => 'f', 'transform' => $payload, 'default' => $payload]]])->id.'/map',
        };
        Tenant::forget();

        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
        $this->assertStringNotContainsString($payload, $html, 'raw payload must never reach the page');
        $rendered = $page === 'approvals' ? trim(json_encode($payload, JSON_UNESCAPED_UNICODE), '"') : $payload; // shown as pretty JSON
        $this->assertStringContainsString(e($rendered), $html, 'escaped payload is rendered');
    }

    public const SQLI = [
        "' OR '1'='1", "' OR 1=1 --", '"; DROP TABLE brain_leads; --', "'; DELETE FROM users; --", "1' UNION SELECT password FROM users --",
        "%' AND 1=0 UNION SELECT 1,2,3 --", "\\'; --", "admin'/*", '1; SELECT pg_sleep(5)', "' AND SLEEP(5) --", '%', '_', '\\', "' OR ''='",
        "'||(SELECT 1)||'", '1 OR 1=1', "') OR ('a'='a", '%00', "' ; ATTACH DATABASE '/tmp/x' AS x; --", "\u{0000}' OR 1=1",
    ];

    public static function sqli(): iterable
    {
        foreach (['leads?q=', 'leads?status=', 'conversations-api?q=', 'dataset?q=', 'search-api?q=', 'admin?q=', 'privacy?subject='] as $target) {
            foreach (self::SQLI as $i => $payload) {
                yield "{$target} #{$i}" => [$target, $payload];
            }
        }
    }

    #[DataProvider('sqli')]
    public function test_search_is_injection_safe(string $target, string $payload): void
    {
        [$user, $ws] = $this->tenant();
        $user->forceFill(['is_admin' => true])->save();
        Lead::create(['name' => 'Only lead']);
        $ds = Dataset::create(['name' => 'D']);
        Tenant::forget();

        $q = urlencode($payload);
        $url = match ($target) {
            'leads?q=' => "/leads?q={$q}",
            'leads?status=' => "/leads?status={$q}",
            'conversations-api?q=' => "/app/api/conversations?q={$q}",
            'dataset?q=' => "/datasets/{$ds->id}?q={$q}",
            'search-api?q=' => "/app/api/search?q={$q}",
            'admin?q=' => "/admin?q={$q}",
            'privacy?subject=' => "/settings/privacy?subject={$q}",
        };
        $status = $this->actingAs($user)->get($url, ['Accept' => str_contains($url, '/app/api/') ? 'application/json' : 'text/html'])->status();

        $this->assertContains($status, [200, 422], "unexpected {$status}");
        $this->assertSame(1, Lead::withoutGlobalScopes()->count(), 'data intact');
        $this->assertSame(1, User::count());
    }
}
