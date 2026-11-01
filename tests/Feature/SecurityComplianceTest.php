<?php

namespace Tests\Feature;

use App\Brain\LlmManager;
use App\Etl\SourceReader;
use App\Models\AuditLog;
use App\Models\AuthEvent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Task;
use App\Models\Usage;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use App\Security\Sealed;
use App\Security\TwoFactor;
use App\Tenancy\Tenant;
use App\Webhooks\Events;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Octane\Events\RequestReceived;
use Tests\TestCase;

class SecurityComplianceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['brain.providers.openai.api_key' => 'sk-platform', 'brain.embeddings.provider' => null, 'brain.generation.retries' => 0]);
    }

    protected function login(User $user, string $password = 'password')
    {
        return $this->post('/login', ['email' => $user->email, 'password' => $password]);
    }

    /* ---------- authentication ---------- */

    public function test_lockout_after_repeated_failures_and_events_logged(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < config('brain.security.lockout_attempts'); $i++) {
            $this->login($user, 'wrong')->assertSessionHasErrors('email');
        }
        $this->login($user)->assertSessionHasErrors('email'); // correct password but locked
        $this->assertGuest();
        $this->assertSame(config('brain.security.lockout_attempts'), AuthEvent::where('event', 'failed')->count());
        $this->assertTrue(AuthEvent::where('event', 'locked')->exists());
    }

    public function test_two_factor_full_flow_with_recovery_code_and_replay_protection(): void
    {
        [$user] = $this->tenant();
        Tenant::forget();
        $tf = app(TwoFactor::class);
        $tf->begin($user);
        $codes = $tf->confirm($user->fresh(), $tf->currentCode($user->fresh()));
        $this->assertCount(8, $codes);

        $this->login($user)->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->post('/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/two-factor', ['code' => $codes[0]])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        $this->login($user);
        $this->post('/two-factor', ['code' => $codes[0]])->assertSessionHasErrors('code'); // recovery codes are one-time
    }

    public function test_totp_code_cannot_be_replayed(): void
    {
        $user = User::factory()->create();
        $tf = app(TwoFactor::class);
        $tf->begin($user);
        $code = $tf->currentCode($user->fresh());
        $tf->confirm($user->fresh(), $code); // consumes the code
        $this->assertFalse($tf->verify($user->fresh(), $code));
    }

    public function test_password_policy_rejects_weak_passwords(): void
    {
        foreach (['short1A', 'alllowercase123', 'ALLUPPERCASE123', 'NoNumbersHereAtAll'] as $weak) {
            $this->post('/register', ['name' => 'X', 'email' => uniqid().'@x.co', 'company' => 'C', 'password' => $weak, 'password_confirmation' => $weak])->assertSessionHasErrors('password');
        }
        $this->assertSame(0, User::count());
    }

    public function test_security_headers_on_app_and_widget(): void
    {
        $this->get('/login')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", $this->get('/login')->headers->get('Content-Security-Policy'));
        $this->assertFalse($this->get('/widget.js')->headers->has('X-Frame-Options'));
    }

    public function test_idle_timeout_signs_user_out(): void
    {
        [$user, $ws] = $this->tenant();
        $ws->updateSettings(['session_timeout' => 10]);
        Tenant::forget();
        $this->actingAs($user)->withSession(['last_activity' => time() - 11 * 60])->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_workspace_can_require_two_factor(): void
    {
        [$user, $ws] = $this->tenant();
        $ws->updateSettings(['require_2fa' => true]);
        Tenant::forget();
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('profile.security'));
        Tenant::forget();
        $this->actingAs($user)->get('/profile/security')->assertOk();
    }

    /* ---------- HIPAA ---------- */

    public function test_hipaa_mode_restricts_providers_blocks_public_channels_and_logs_phi_access(): void
    {
        [$user, $ws, $token] = $this->tenant('enterprise');
        $ws->updateSettings(['hipaa_mode' => true]);
        $conv = Conversation::create(['title' => 'Patient']);
        $this->assertSame([], app(LlmManager::class)->available()); // openai has no BAA flag

        $ws->provider_keys = ['openai' => ['api_key' => 'sk-baa', 'baa' => true]];
        $ws->save();
        app(LlmManager::class)->flush();
        $this->assertSame('openai', app(LlmManager::class)->available()[0]['name']);
        Tenant::forget();

        $this->getJson("/widget/{$ws->widget_key}/config")->assertForbidden();
        Tenant::forget();
        $this->getJson("/api/brain/conversations/{$conv->uuid}", ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->assertTrue(AuditLog::where('action', 'phi.conversation_viewed')->exists());
    }

    public function test_hipaa_mode_requires_enterprise_plan(): void
    {
        [$user] = $this->tenant('business');
        Tenant::forget();
        $this->actingAs($user)->post('/settings/privacy', ['hipaa_mode' => 1])->assertStatus(402);
    }

    public function test_phi_is_encrypted_at_rest(): void
    {
        $this->tenant();
        $conv = Conversation::create(['title' => 't']);
        $conv->messages()->create(['role' => 'user', 'content' => 'Patient has diabetes']);
        Lead::create(['name' => 'P', 'notes' => 'SSN-like secret note']);

        $raw = DB::table('brain_messages')->value('content');
        $this->assertStringStartsWith(Sealed::PREFIX, $raw);
        $this->assertStringNotContainsString('diabetes', $raw);
        $this->assertStringNotContainsString('secret note', DB::table('brain_leads')->value('notes'));
        $this->assertSame('Patient has diabetes', $conv->messages()->first()->content);
    }

    public function test_encrypt_existing_seals_legacy_rows_idempotently(): void
    {
        [, $ws] = $this->tenant();
        $conv = Conversation::create(['title' => 't']);
        DB::table('brain_messages')->insert(['workspace_id' => $ws->id, 'conversation_id' => $conv->id, 'role' => 'user', 'content' => 'legacy plain', 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('brain:encrypt-existing')->assertSuccessful();
        $this->artisan('brain:encrypt-existing')->assertSuccessful();
        $raw = DB::table('brain_messages')->value('content');
        $this->assertStringStartsWith(Sealed::PREFIX, $raw);
        $this->assertSame('legacy plain', Message::first()->content);
    }

    /* ---------- SOC 2: audit ---------- */

    public function test_audit_log_is_hash_chained_and_tamper_evident(): void
    {
        [, $ws] = $this->tenant();
        foreach (range(1, 5) as $i) {
            AuditLog::record("test.{$i}", null, ['i' => $i]);
        }
        $this->assertNull(AuditLog::verifyChain($ws->id));
        $this->artisan('brain:audit:verify')->assertSuccessful();

        $victim = AuditLog::where('action', 'test.3')->first();
        DB::table('audit_logs')->where('id', $victim->id)->update(['meta' => json_encode(['i' => 999])]);
        $this->assertSame($victim->id, AuditLog::verifyChain($ws->id));
        $this->artisan('brain:audit:verify')->assertFailed();
    }

    public function test_audit_log_is_append_only_through_the_app(): void
    {
        $this->tenant();
        $log = AuditLog::record('x');
        $this->expectException(\LogicException::class);
        $log->delete();
    }

    /* ---------- CCPA ---------- */

    public function test_consumer_request_export_and_erase(): void
    {
        [$user] = $this->tenant();
        $lead = Lead::create(['name' => 'Sara', 'email' => 'sara@nova.pk']);
        Task::create(['title' => 'Call Sara', 'lead_id' => $lead->id]);
        $c = Conversation::create(['title' => 'hi', 'channel' => 'widget', 'external_ref' => 'web:v1']);
        $c->messages()->create(['role' => 'user', 'content' => 'my email is sara@nova.pk']);
        Lead::create(['name' => 'Other', 'email' => 'other@x.pk']);
        Tenant::forget();

        $this->actingAs($user)->post('/settings/privacy/export', ['subject' => 'sara@nova.pk'])->assertOk()
            ->assertJsonPath('leads.0.email', 'sara@nova.pk')->assertJsonCount(1, 'conversations');
        Tenant::forget();
        $this->actingAs($user)->post('/settings/privacy/erase', ['subject' => 'sara@nova.pk', 'confirm' => 'ERASE'])->assertRedirect();

        $this->assertSame(1, Lead::withoutGlobalScopes()->count());
        $this->assertSame(0, Task::withoutGlobalScopes()->count());
        $this->assertSame(0, Conversation::withoutGlobalScopes()->count());
        $this->assertTrue(AuditLog::where('action', 'privacy.dsr_erase')->exists());
        $this->assertStringNotContainsString('sara@nova.pk', json_encode(AuditLog::pluck('meta')));
    }

    public function test_user_can_export_and_delete_own_account(): void
    {
        $user = User::factory()->create(['password' => bcrypt('Str0ng-Passw0rd!')]);
        Workspace::createFor($user, 'Solo');
        Tenant::forget();
        $this->actingAs($user)->get('/profile/export')->assertOk()->assertJsonPath('user.email', $user->email);
        Tenant::forget();
        $this->actingAs($user)->delete('/profile', ['current_password' => 'Str0ng-Passw0rd!', 'confirm' => 'DELETE'])->assertRedirect('/login');
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame(0, Workspace::count());
    }

    public function test_owner_with_team_cannot_delete_account_without_transfer(): void
    {
        [$user, $ws] = $this->tenant();
        $user->forceFill(['password' => bcrypt('Str0ng-Passw0rd!')])->save();
        $ws->members()->attach(User::factory()->create()->id, ['role' => 'member']);
        Tenant::forget();
        $this->actingAs($user)->delete('/profile', ['current_password' => 'Str0ng-Passw0rd!', 'confirm' => 'DELETE'])->assertSessionHasErrors('confirm');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_retention_purges_old_conversations_only(): void
    {
        [, $ws] = $this->tenant();
        $ws->updateSettings(['retention_days' => 30]);
        $old = Conversation::create(['title' => 'old']);
        DB::table('brain_conversations')->where('id', $old->id)->update(['updated_at' => now()->subDays(31)]);
        Conversation::create(['title' => 'new']);
        Tenant::forget();
        $this->artisan('brain:retention')->assertSuccessful();
        $this->assertSame(['new'], Conversation::withoutGlobalScopes()->pluck('title')->all());
    }

    /* ---------- webhooks ---------- */

    public function test_events_are_signed_and_delivered(): void
    {
        [, $ws] = $this->tenant();
        $endpoint = WebhookEndpoint::create(['url' => 'https://hooks.example.com/x', 'secret' => 'whsec_test', 'events' => ['lead.created']]);
        Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);

        Lead::create(['name' => 'Webhook lead']);

        Http::assertSent(function ($r) {
            return $r->hasHeader('X-Brain-Event', 'lead.created') && Events::verify('whsec_test', $r->body(), $r->header('X-Brain-Signature')[0]) && $r['data']['name'] === 'Webhook lead';
        });
        $this->assertSame('delivered', WebhookDelivery::first()->status);
        Task::create(['title' => 'not subscribed']);
        $this->assertSame(1, WebhookDelivery::count());
    }

    public function test_failing_endpoint_records_failure_and_auto_disables(): void
    {
        $this->tenant();
        $endpoint = WebhookEndpoint::create(['url' => 'https://down.example.com/x', 'secret' => 's', 'events' => ['*'], 'failures' => 19]);
        Http::fake(['down.example.com/*' => Http::response('boom', 500)]);
        Lead::create(['name' => 'x']);
        $this->assertSame('failed', WebhookDelivery::first()->status);
        $this->assertFalse($endpoint->fresh()->active);
    }

    public function test_webhook_endpoint_rejects_internal_urls(): void
    {
        config(['brain.security.allow_private_urls' => false]);
        [$user] = $this->tenant();
        Tenant::forget();
        $this->actingAs($user)->post('/settings/webhooks', ['url' => 'http://169.254.169.254/latest', 'events' => ['*']])->assertSessionHasErrors('url');
        $this->assertSame(0, WebhookEndpoint::withoutGlobalScopes()->count());
    }

    public function test_url_import_is_ssrf_protected(): void
    {
        config(['brain.security.allow_private_urls' => false]);
        $this->tenant();
        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array(app(SourceReader::class)->rows('url', ['url' => 'http://127.0.0.1:6379/']));
    }

    /* ---------- WhatsApp ---------- */

    public function test_whatsapp_verification_signature_and_reply(): void
    {
        [, $ws] = $this->tenant();
        $ws->forceFill(['whatsapp' => ['phone_number_id' => '123', 'token' => 'tok', 'app_secret' => 'appsec', 'verify_token' => 'vt']])->save();
        Tenant::forget();

        $this->get("/channels/whatsapp/{$ws->uuid}?hub_mode=subscribe&hub_verify_token=vt&hub_challenge=42")->assertOk()->assertSee('42');
        $this->get("/channels/whatsapp/{$ws->uuid}?hub_mode=subscribe&hub_verify_token=bad&hub_challenge=42")->assertForbidden();

        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'We open at 9.']]]]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]]),
        ]);
        $body = json_encode(['entry' => [['changes' => [['value' => ['contacts' => [['profile' => ['name' => 'Ali']]], 'messages' => [['from' => '923001234567', 'type' => 'text', 'text' => ['body' => 'Hours?']]]]]]]]]);

        $this->call('POST', "/channels/whatsapp/{$ws->uuid}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], $body)->assertForbidden();
        Tenant::forget();
        $this->call('POST', "/channels/whatsapp/{$ws->uuid}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'appsec')], $body)->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com/v21.0/123/messages') && $r['text']['body'] === 'We open at 9.' && $r['to'] === '923001234567');
        $this->assertDatabaseHas('brain_conversations', ['channel' => 'whatsapp', 'external_ref' => 'wa:923001234567']);
    }

    public function test_health_endpoint(): void
    {
        $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('checks.database.ok', true);
    }

    public function test_octane_resets_tenant_between_requests(): void
    {
        [, $ws] = $this->tenant();
        $this->assertNotNull(Tenant::current());
        event(new RequestReceived(app(), app(), request()));
        $this->assertNull(Tenant::current());
    }

    public function test_usage_increments_are_atomic(): void
    {
        [, $ws] = $this->tenant();
        foreach (range(1, 50) as $i) {
            Usage::record($ws, ['messages' => 1, 'input_tokens' => 10]);
        }
        $u = Usage::current($ws);
        $this->assertSame(50, (int) $u->messages);
        $this->assertSame(500, (int) $u->input_tokens);
    }
}
