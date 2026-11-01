<?php

namespace Tests\Feature;

use App\Brain\LlmManager;
use App\Mail\WorkspaceInvitation;
use App\Models\Agent;
use App\Models\Dataset;
use App\Models\Lead;
use App\Models\Usage;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SaasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['brain.providers.openai.api_key' => 'sk-platform', 'brain.embeddings.provider' => null, 'brain.knowledge.queue' => false, 'brain.generation.retries' => 0]);
    }

    public function test_register_creates_user_workspace_and_default_agent(): void
    {
        $this->post('/register', ['name' => 'Ali', 'email' => 'ali@acme.pk', 'company' => 'Acme Traders', 'password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'Str0ng-Passw0rd!'])
            ->assertRedirect('/dashboard');

        $user = User::firstWhere('email', 'ali@acme.pk');
        $this->assertTrue($user->is_admin); // first user = platform admin
        $ws = $user->activeWorkspace();
        $this->assertSame('Acme Traders', $ws->name);
        $this->assertSame('owner', $user->roleIn($ws));
        $this->assertSame('pro', $ws->plan()->key); // trial
        $this->assertDatabaseHas('brain_agents', ['workspace_id' => $ws->id, 'is_default' => true]);
    }

    public function test_tenants_are_fully_isolated(): void
    {
        [, $a, $tokenA] = $this->tenant();
        Lead::create(['name' => 'A secret customer']);
        [, $b, $tokenB] = $this->tenant();
        Lead::create(['name' => 'B customer']);
        Tenant::forget();

        $this->getJson('/api/brain/leads', ['Authorization' => "Bearer {$tokenA}"])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'A secret customer');

        $leadA = Lead::withoutGlobalScopes()->firstWhere('name', 'A secret customer');
        Tenant::forget();
        // B cannot touch A's records even by id (route-model binding is scoped)
        $this->getJson('/api/brain/leads', ['Authorization' => "Bearer {$tokenB}"])->assertJsonMissing(['name' => 'A secret customer']);
        Tenant::forget();
        $this->getJson("/api/brain/datasets/{$leadA->id}/rows", ['Authorization' => "Bearer {$tokenB}"])->assertNotFound();
    }

    public function test_message_quota_blocks_and_usage_is_recorded(): void
    {
        [, $ws, $token] = $this->tenant('pro');
        config(['saas.plans.pro.limits.messages' => 1]);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Hi']]], 'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3]])]);

        $this->postJson('/api/brain/chat', ['message' => 'one'], ['Authorization' => "Bearer {$token}"])->assertOk();
        Tenant::forget();
        $this->postJson('/api/brain/chat', ['message' => 'two'], ['Authorization' => "Bearer {$token}"])->assertStatus(402);

        $usage = Usage::current($ws);
        $this->assertSame(1, (int) $usage->messages);
        $this->assertSame(10, (int) $usage->input_tokens + (int) $usage->output_tokens);
    }

    public function test_free_plan_has_no_api_and_must_bring_own_key(): void
    {
        [, $ws, $token] = $this->tenant('free');
        $this->getJson('/api/brain/leads', ['Authorization' => "Bearer {$token}"])->assertStatus(402);

        Tenant::set($ws);
        $this->assertSame([], app(LlmManager::class)->available()); // platform key hidden on free

        $ws->provider_keys = ['anthropic' => ['api_key' => 'own-key']];
        $ws->save();
        app(LlmManager::class)->flush();
        $this->assertSame('anthropic', app(LlmManager::class)->defaultProvider());
        $this->assertSame('workspace', app(LlmManager::class)->available()[0]['source']);
    }

    public function test_byok_key_is_used_and_stored_encrypted(): void
    {
        [, $ws, $token] = $this->tenant();
        $ws->provider_keys = ['openai' => ['api_key' => 'sk-tenant-own', 'model' => 'gpt-5']];
        $ws->save();
        $this->assertStringNotContainsString('sk-tenant-own', \DB::table('workspaces')->where('id', $ws->id)->value('provider_keys'));

        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);
        Tenant::forget();
        $this->postJson('/api/brain/chat', ['message' => 'hi', 'provider' => 'openai'], ['Authorization' => "Bearer {$token}"])->assertOk();
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer sk-tenant-own') && $r['model'] === 'gpt-5');
    }

    public function test_invitation_flow_and_roles(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->tenant();
        Tenant::forget();

        $this->actingAs($owner)->post('/settings/team/invite', ['email' => 'sara@acme.pk', 'role' => 'member'])->assertRedirect();
        $invite = $ws->invitations()->first();
        Mail::assertSent(WorkspaceInvitation::class);
        auth()->logout();

        $this->get("/invitations/{$invite->token}")->assertRedirect('/register');
        $this->post('/register', ['name' => 'Sara', 'email' => 'sara@acme.pk', 'password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'Str0ng-Passw0rd!'])->assertRedirect('/dashboard');

        $sara = User::firstWhere('email', 'sara@acme.pk');
        $this->assertSame('member', $sara->roleIn($ws));
        Tenant::forget();
        $this->actingAs($sara)->get('/settings')->assertForbidden();
        $this->actingAs($sara)->get('/chat')->assertOk();
    }

    public function test_every_app_page_renders(): void
    {
        [$user, $ws] = $this->tenant();
        $user->forceFill(['is_admin' => true])->save();
        $agent = Agent::first();
        $dataset = Dataset::create(['name' => 'Products', 'fields' => [['name' => 'sku', 'type' => 'string']]]);
        Tenant::forget();

        foreach (['/dashboard', '/chat', '/knowledge', '/approvals', '/agents', '/agents/create', "/agents/{$agent->id}/edit", '/leads', '/tasks', '/datasets', "/datasets/{$dataset->id}",
            '/imports', '/imports/create', '/exports', '/settings', '/settings/providers', '/settings/api', '/settings/channels', '/settings/team', '/settings/data', '/billing', '/admin'] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
            Tenant::forget();
        }
        $this->get('/widget.js')->assertOk();
    }

    public function test_widget_chat_is_public_and_restricted(): void
    {
        [, $ws] = $this->tenant();
        Tenant::forget();
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'We open at 9.']]]])]);

        $this->getJson("/widget/{$ws->widget_key}/config")->assertOk()->assertJsonPath('name', $ws->name);
        Tenant::forget();
        $this->postJson("/widget/{$ws->widget_key}/chat", ['message' => 'When do you open?', 'visitor' => 'v123'])
            ->assertOk()->assertJsonPath('answer', 'We open at 9.');

        Http::assertSent(function ($r) {
            $tools = collect($r['tools'] ?? [])->pluck('function.name')->all();

            return ! in_array('send_email', $tools) && ! in_array('find_leads', $tools) && in_array('create_lead', $tools);
        });
        $this->assertDatabaseHas('brain_conversations', ['workspace_id' => $ws->id, 'channel' => 'widget']);
        Tenant::forget();
        $this->postJson('/widget/wk_wrong/chat', ['message' => 'x', 'visitor' => 'v'])->assertNotFound();
    }

    public function test_super_admin_can_override_plan_and_suspend(): void
    {
        [$admin] = $this->tenant();
        $admin->forceFill(['is_admin' => true])->save();
        $other = Workspace::createFor(User::factory()->create(), 'Client Co');
        Tenant::forget();

        $this->actingAs($admin)->patch("/admin/workspaces/{$other->id}", ['plan_override' => 'business', 'suspended' => 1])->assertRedirect();
        $other->refresh();
        $this->assertSame('business', $other->plan()->key);
        $this->assertTrue($other->suspended);
    }

    public function test_upgrade_from_single_tenant_moves_data_into_default_workspace(): void
    {
        // simulate a v1 install: drop SaaS layer, insert legacy data, migrate again
        // every migration after the v1 brain tables (counted, so new migrations don't break this)
        $after = collect(glob(database_path('migrations/*.php')))->map(fn ($f) => basename($f))->sort()->filter(fn ($f) => $f > '2026_10_24_000001_create_brain_tables.php')->count();
        $this->artisan('migrate:rollback', ['--step' => $after])->assertSuccessful();
        \DB::table('brain_leads')->insert(['name' => 'Legacy lead', 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('migrate')->assertSuccessful();

        $wsId = \DB::table('brain_leads')->where('name', 'Legacy lead')->value('workspace_id');
        $this->assertNotNull($wsId);
        $this->assertDatabaseHas('workspaces', ['id' => $wsId]);
    }
}
