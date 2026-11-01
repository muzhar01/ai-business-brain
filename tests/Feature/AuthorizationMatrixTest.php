<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Dataset;
use App\Models\PendingAction;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Every page × every actor: guest, member, admin, owner, super admin. */
class AuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    public const MEMBER_PAGES = ['/dashboard', '/chat', '/knowledge', '/approvals', '/agents', '/agents/create', '/leads', '/tasks', '/datasets', '/imports', '/imports/create', '/exports', '/profile', '/profile/security'];

    public const ADMIN_PAGES = ['/settings', '/settings/providers', '/settings/api', '/settings/webhooks', '/settings/channels', '/settings/team', '/settings/privacy', '/settings/data', '/billing'];

    public const SUPER_PAGES = ['/admin'];

    public static function matrix(): iterable
    {
        foreach (['guest', 'member', 'admin', 'owner', 'super'] as $actor) {
            foreach (self::MEMBER_PAGES as $p) {
                yield "{$actor} {$p}" => [$actor, $p, $actor === 'guest' ? 302 : 200];
            }
            foreach (self::ADMIN_PAGES as $p) {
                yield "{$actor} {$p}" => [$actor, $p, match ($actor) {
                    'guest' => 302, 'member' => 403, default => 200
                }];
            }
            foreach (self::SUPER_PAGES as $p) {
                yield "{$actor} {$p}" => [$actor, $p, match ($actor) {
                    'guest' => 302, 'super' => 200, default => 403
                }];
            }
        }
    }

    #[DataProvider('matrix')]
    public function test_access(string $actor, string $path, int $status): void
    {
        [$owner, $ws] = $this->tenant();
        $user = match ($actor) {
            'guest' => null,
            'owner' => $owner,
            'super' => tap($owner, fn ($u) => $u->forceFill(['is_admin' => true])->save()),
            default => tap(User::factory()->create(), fn ($u) => $ws->members()->attach($u->id, ['role' => $actor])),
        };
        Tenant::forget();

        $response = $user ? $this->actingAs($user)->get($path) : $this->get($path);
        $response->assertStatus($status);
        if ($status === 302) {
            $response->assertRedirect('/login');
        }
    }

    public static function writes(): iterable
    {
        $routes = [
            ['post', '/settings', ['name' => 'X']],
            ['post', '/settings/providers', ['provider' => 'openai', 'api_key' => 'x']],
            ['post', '/settings/api', ['name' => 'x']],
            ['post', '/settings/team/invite', ['email' => 'a@b.co', 'role' => 'admin']],
            ['post', '/settings/privacy', ['retention_days' => 1]],
            ['post', '/settings/webhooks', ['url' => 'https://1.1.1.1/x', 'events' => ['*']]],
            ['post', '/settings/channels/briefing', ['briefing_hour' => 8]],
            ['post', '/settings/channels/widget/rotate', []],
            ['post', '/settings/data/restore', []],
            ['get', '/settings/data/backup', []],
            ['get', '/billing/checkout/pro', []],
            ['delete', '/settings/workspace', ['confirm' => 'x']],
        ];
        foreach ($routes as [$m, $p, $d]) {
            yield "member {$m} {$p}" => [$m, $p, $d];
        }
    }

    #[DataProvider('writes')]
    public function test_members_cannot_change_workspace(string $method, string $path, array $data): void
    {
        [, $ws] = $this->tenant();
        $member = User::factory()->create();
        $ws->members()->attach($member->id, ['role' => 'member']);
        Tenant::forget();

        $this->actingAs($member)->{$method}($path, $data)->assertForbidden();
    }

    public function test_admin_cannot_delete_workspace_only_owner(): void
    {
        [, $ws] = $this->tenant();
        $admin = User::factory()->create();
        $ws->members()->attach($admin->id, ['role' => 'admin']);
        Tenant::forget();
        $this->actingAs($admin)->delete('/settings/workspace', ['confirm' => $ws->name])->assertForbidden();
        $this->assertDatabaseHas('workspaces', ['id' => $ws->id]);
    }

    public function test_member_cannot_approve_actions(): void
    {
        [, $ws] = $this->tenant();
        $action = PendingAction::create(['tool' => 'send_email', 'arguments' => ['to' => 'a@b.co'], 'status' => 'pending']);
        $member = User::factory()->create();
        $ws->members()->attach($member->id, ['role' => 'member']);
        Tenant::forget();
        $this->actingAs($member)->postJson("/app/api/actions/{$action->id}/approve")->assertForbidden();
        $this->assertSame('pending', $action->fresh()->status);
    }

    public function test_users_cannot_switch_into_foreign_workspace(): void
    {
        [$a] = $this->tenant();
        [, $b] = $this->tenant();
        Tenant::forget();
        $this->actingAs($a)->post('/workspaces/switch', ['workspace_id' => $b->id])->assertForbidden();
    }

    public function test_detail_pages_render_for_members(): void
    {
        [, $ws] = $this->tenant();
        $agent = Agent::first();
        $ds = Dataset::create(['name' => 'P']);
        $member = User::factory()->create();
        $ws->members()->attach($member->id, ['role' => 'member']);
        Tenant::forget();
        $this->actingAs($member)->get("/agents/{$agent->id}/edit")->assertOk();
        Tenant::forget();
        $this->actingAs($member)->get("/datasets/{$ds->id}")->assertOk();
    }
}
