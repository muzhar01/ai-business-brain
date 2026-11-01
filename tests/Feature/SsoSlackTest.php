<?php

namespace Tests\Feature;

use App\Channels\Slack;
use App\Jobs\HandleSlackEvent;
use App\Models\Conversation;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Security\Sso;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SsoSlackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.client_id' => 'gid', 'services.google.client_secret' => 'gs',
            'services.github.client_id' => 'hid', 'services.github.client_secret' => 'hs',
            'services.microsoft.client_id' => null,
            'brain.providers.openai.api_key' => 'sk-test', 'brain.embeddings.provider' => null, 'brain.generation.retries' => 0,
        ]);
    }

    protected function identity(string $id, string $email, bool $verified = true, string $name = 'Sara Khan'): SocialiteUser
    {
        return (new SocialiteUser)->setRaw(['email_verified' => $verified])->map(['id' => $id, 'email' => $email, 'name' => $name]);
    }

    protected function fakeProvider(SocialiteUser $user): void
    {
        $driver = Mockery::mock(AbstractProvider::class);
        $driver->shouldReceive('user')->andReturn($user);
        $driver->shouldReceive('scopes')->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn(redirect('https://accounts.example/authorize'));
        Socialite::shouldReceive('driver')->andReturn($driver);
    }

    // ---------------- SSO ----------------

    public function test_only_configured_providers_are_enabled_and_shown(): void
    {
        $this->assertSame(['google' => 'Google', 'github' => 'GitHub'], Sso::enabled());
        $this->get('/login')->assertOk()->assertSee('Continue with Google')->assertSee('Continue with GitHub')->assertDontSee('Continue with Microsoft');
        $this->get('/auth/microsoft/redirect')->assertNotFound();
        $this->get('/auth/okta/redirect')->assertNotFound();
    }

    public function test_redirect_goes_to_provider(): void
    {
        $this->fakeProvider($this->identity('1', 'a@b.com'));
        $this->get('/auth/google/redirect')->assertRedirect('https://accounts.example/authorize');
        $this->get('/auth/github/redirect')->assertRedirect('https://accounts.example/authorize');
    }

    public function test_new_user_gets_own_workspace(): void
    {
        $this->fakeProvider($this->identity('g-1', 'Sara@Startup.io'));
        $this->get('/auth/google/callback')->assertRedirect();
        $user = User::where('email', 'sara@startup.io')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('owner', $user->workspaces()->first()->pivot->role);
        $this->assertDatabaseHas('social_accounts', ['provider' => 'google', 'provider_id' => 'g-1', 'user_id' => $user->id]);
    }

    public function test_domain_claim_auto_joins_workspace_with_default_role(): void
    {
        $owner = User::factory()->create();
        $ws = Workspace::createFor($owner, 'Acme');
        $ws->updateSettings(['sso_domain' => 'acme.com', 'sso_default_role' => 'member']);
        Tenant::forget();

        $this->fakeProvider($this->identity('g-2', 'bob@acme.com'));
        $this->get('/auth/google/callback');
        $bob = User::where('email', 'bob@acme.com')->firstOrFail();
        $this->assertSame('member', $ws->members()->whereKey($bob->id)->first()->pivot->role);
        $this->assertSame($ws->id, $bob->current_workspace_id);
        $this->assertSame(1, Workspace::count() - 0 - ($owner->workspaces()->count() - 1)); // no new workspace for bob
    }

    public function test_existing_user_is_linked_by_verified_email_and_reused(): void
    {
        $user = User::factory()->create(['email' => 'lee@x.com']);
        Workspace::createFor($user, 'X');
        Tenant::forget();
        $this->fakeProvider($this->identity('g-3', 'lee@x.com'));
        $this->get('/auth/google/callback');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, SocialAccount::count());

        auth()->logout();
        $this->get('/auth/google/callback');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, SocialAccount::count(), 'second login reuses the link');
        $this->assertSame(1, User::count());
    }

    public function test_unverified_google_email_is_refused(): void
    {
        User::factory()->create(['email' => 'victim@x.com']);
        $this->fakeProvider($this->identity('g-evil', 'victim@x.com', verified: false));
        $this->get('/auth/google/callback')->assertForbidden();
        $this->assertGuest();
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_missing_email_is_refused(): void
    {
        $this->fakeProvider($this->identity('h-1', ''));
        $this->get('/auth/github/callback')->assertForbidden();
    }

    public function test_provider_failure_returns_to_login(): void
    {
        $driver = Mockery::mock(AbstractProvider::class);
        $driver->shouldReceive('user')->andThrow(new InvalidStateException);
        Socialite::shouldReceive('driver')->andReturn($driver);
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_sso_respects_two_factor(): void
    {
        $user = User::factory()->create(['email' => 'tf@x.com']);
        $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        Workspace::createFor($user, 'X');
        Tenant::forget();
        $this->fakeProvider($this->identity('g-4', 'tf@x.com'));
        $this->get('/auth/google/callback')->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
    }

    public function test_enforced_sso_blocks_member_password_login_but_not_owner(): void
    {
        $owner = User::factory()->create();
        $ws = Workspace::createFor($owner, 'Acme');
        $member = User::factory()->create();
        $ws->members()->attach($member->id, ['role' => 'member']);
        $ws->updateSettings(['enforce_sso' => true]);
        Tenant::forget();

        $this->post('/login', ['email' => $member->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $owner->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($owner);
    }

    public function test_sso_only_user_cannot_password_login(): void
    {
        $this->fakeProvider($this->identity('g-5', 'np@x.com'));
        $this->get('/auth/google/callback');
        auth()->logout();
        $this->post('/login', ['email' => 'np@x.com', 'password' => ''])->assertSessionHasErrors();
        $this->post('/login', ['email' => 'np@x.com', 'password' => 'anything-at-all'])->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_sso_settings_save_and_audit(): void
    {
        [$user, $ws] = $this->tenant();
        $this->actingAs($user)->post('/settings/sso', ['sso_domain' => 'Acme.COM', 'sso_default_role' => 'admin', 'enforce_sso' => '1'])->assertSessionHasNoErrors();
        $ws->refresh();
        $this->assertSame('acme.com', $ws->setting('sso_domain'));
        $this->assertSame('admin', $ws->setting('sso_default_role'));
        $this->assertTrue($ws->setting('enforce_sso'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'sso.updated']);
        $this->actingAs($user)->get('/settings/team')->assertOk()->assertSee('Single sign-on');
    }

    public function test_sso_domain_cannot_be_claimed_twice(): void
    {
        $other = Workspace::createFor(User::factory()->create(), 'Other');
        $other->updateSettings(['sso_domain' => 'acme.com']);
        [$user] = $this->tenant();
        $this->actingAs($user)->post('/settings/sso', ['sso_domain' => 'acme.com', 'sso_default_role' => 'member'])->assertSessionHasErrors('sso_domain');
    }

    public function test_members_cannot_change_sso(): void
    {
        [, $ws] = $this->tenant();
        $m = User::factory()->create();
        $ws->members()->attach($m->id, ['role' => 'member']);
        $m->forceFill(['current_workspace_id' => $ws->id])->save();
        $this->actingAs($m)->post('/settings/sso', ['sso_domain' => 'evil.com', 'sso_default_role' => 'admin'])->assertForbidden();
    }

    // ---------------- Slack ----------------

    protected function slackWorkspace(string $plan = 'business'): Workspace
    {
        [, $ws] = $this->tenant($plan);
        $ws->forceFill(['slack' => ['bot_token' => 'xoxb-1', 'signing_secret' => 'shh']])->save();
        Tenant::forget();

        return $ws;
    }

    protected function slackPost(Workspace $ws, array $payload, ?string $secret = 'shh', ?int $ts = null, array $extra = [])
    {
        $body = json_encode($payload);
        $ts ??= time();
        $sig = 'v0='.hash_hmac('sha256', "v0:{$ts}:{$body}", (string) $secret);

        return $this->call('POST', "/channels/slack/{$ws->uuid}", [], [], [], array_merge([
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_SLACK_REQUEST_TIMESTAMP' => (string) $ts, 'HTTP_X_SLACK_SIGNATURE' => $sig,
        ], $extra), $body);
    }

    public function test_slack_url_verification(): void
    {
        $ws = $this->slackWorkspace();
        $this->slackPost($ws, ['type' => 'url_verification', 'challenge' => 'abc'])->assertOk()->assertJson(['challenge' => 'abc']);
    }

    public function test_slack_rejects_bad_signature_and_stale_timestamp(): void
    {
        $ws = $this->slackWorkspace();
        $this->slackPost($ws, ['type' => 'url_verification', 'challenge' => 'x'], 'wrong')->assertForbidden();
        $this->slackPost($ws, ['type' => 'url_verification', 'challenge' => 'x'], 'shh', time() - 600)->assertForbidden();
        $this->post('/channels/slack/'.$ws->uuid, ['type' => 'url_verification'])->assertForbidden();
        $this->post('/channels/slack/nope', [])->assertNotFound();
    }

    public function test_slack_mention_is_answered_in_thread(): void
    {
        $ws = $this->slackWorkspace();
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Revenue is up 12%.']]]]),
            'slack.com/*' => Http::response(['ok' => true]),
        ]);
        $this->slackPost($ws, ['type' => 'event_callback', 'event' => ['type' => 'app_mention', 'text' => '<@U0BOT> how is revenue?', 'channel' => 'C1', 'ts' => '111.1', 'user' => 'U9']])->assertNoContent();

        Http::assertSent(fn ($r) => $r->url() === 'https://slack.com/api/chat.postMessage'
            && $r['channel'] === 'C1' && $r['thread_ts'] === '111.1' && $r['text'] === 'Revenue is up 12%.'
            && $r->hasHeader('Authorization', 'Bearer xoxb-1'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'openai') && collect($r['messages'])->last()['content'] === 'how is revenue?');
        $this->assertDatabaseHas('brain_conversations', ['channel' => 'slack', 'external_ref' => 'slack:C1:111.1', 'workspace_id' => $ws->id]);
    }

    public function test_slack_thread_continues_same_conversation(): void
    {
        $ws = $this->slackWorkspace();
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]), 'slack.com/*' => Http::response(['ok' => true])]);
        $this->slackPost($ws, ['type' => 'event_callback', 'event' => ['type' => 'app_mention', 'text' => 'one', 'channel' => 'C1', 'ts' => '5.0']]);
        $this->slackPost($ws, ['type' => 'event_callback', 'event' => ['type' => 'app_mention', 'text' => 'two', 'channel' => 'C1', 'ts' => '6.0', 'thread_ts' => '5.0']]);
        $this->assertSame(1, Conversation::withoutGlobalScopes()->where('channel', 'slack')->count());
    }

    public function test_slack_dm_replies_without_thread(): void
    {
        $ws = $this->slackWorkspace();
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Hi!']]]]), 'slack.com/*' => Http::response(['ok' => true])]);
        $this->slackPost($ws, ['type' => 'event_callback', 'event' => ['type' => 'message', 'channel_type' => 'im', 'text' => 'hello', 'channel' => 'D1', 'ts' => '1.0']]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'slack.com') && ! isset($r['thread_ts']) && $r['channel'] === 'D1');
    }

    public static function ignoredEvents(): array
    {
        return [
            'bot message' => [['type' => 'app_mention', 'bot_id' => 'B1', 'text' => 'x', 'channel' => 'C', 'ts' => '1']],
            'edit' => [['type' => 'message', 'subtype' => 'message_changed', 'channel_type' => 'im', 'text' => 'x', 'channel' => 'D', 'ts' => '1']],
            'channel chatter' => [['type' => 'message', 'channel_type' => 'channel', 'text' => 'x', 'channel' => 'C', 'ts' => '1']],
            'empty mention' => [['type' => 'app_mention', 'text' => '<@U0BOT>', 'channel' => 'C', 'ts' => '1']],
            'reaction' => [['type' => 'reaction_added', 'channel' => 'C', 'ts' => '1']],
        ];
    }

    #[DataProvider('ignoredEvents')]
    public function test_slack_ignores_noise(array $event): void
    {
        $ws = $this->slackWorkspace();
        Http::fake();
        $this->slackPost($ws, ['type' => 'event_callback', 'event' => $event])->assertNoContent();
        Http::assertNothingSent();
    }

    public function test_slack_retries_are_acknowledged_not_reprocessed(): void
    {
        $ws = $this->slackWorkspace();
        Queue::fake();
        $this->slackPost($ws, ['type' => 'event_callback', 'event' => ['type' => 'app_mention', 'text' => 'x', 'channel' => 'C', 'ts' => '1']], extra: ['HTTP_X_SLACK_RETRY_NUM' => '1'])->assertNoContent();
        Queue::assertNothingPushed();
        $this->slackPost($ws, ['type' => 'event_callback', 'event' => ['type' => 'app_mention', 'text' => 'x', 'channel' => 'C', 'ts' => '1']]);
        Queue::assertPushedOn('channels', HandleSlackEvent::class);
    }

    public function test_slack_disabled_on_free_plan_and_in_hipaa_mode(): void
    {
        Queue::fake();
        $free = $this->slackWorkspace('free');
        $this->slackPost($free, ['type' => 'event_callback', 'event' => ['type' => 'app_mention', 'text' => 'x', 'channel' => 'C', 'ts' => '1']])->assertNoContent();

        $hipaa = $this->slackWorkspace('enterprise');
        $hipaa->updateSettings(['hipaa_mode' => true]);
        $this->slackPost($hipaa, ['type' => 'event_callback', 'event' => ['type' => 'app_mention', 'text' => 'x', 'channel' => 'C', 'ts' => '1']])->assertNoContent();
        Queue::assertNothingPushed();
    }

    public function test_slack_settings_save_encrypt_keep_and_disconnect(): void
    {
        [$user, $ws] = $this->tenant();
        $this->actingAs($user)->post('/settings/channels/slack', ['bot_token' => 'xoxb-9', 'signing_secret' => 'sec'])->assertSessionHasNoErrors();
        $raw = \DB::table('workspaces')->where('id', $ws->id)->value('slack');
        $this->assertStringNotContainsString('xoxb-9', $raw);
        $this->actingAs($user)->post('/settings/channels/slack', ['bot_token' => '', 'signing_secret' => 'new']);
        $this->assertSame(['bot_token' => 'xoxb-9', 'signing_secret' => 'new'], $ws->fresh()->slack);
        $this->actingAs($user)->get('/settings/channels')->assertOk()->assertSee(route('channels.slack', $ws->uuid));
        $this->actingAs($user)->post('/settings/channels/slack', ['disconnect' => 1]);
        $this->assertNull($ws->fresh()->slack);
    }

    public function test_slack_settings_require_paid_plan(): void
    {
        [$user] = $this->tenant('free');
        $this->actingAs($user)->post('/settings/channels/slack', ['bot_token' => 'x', 'signing_secret' => 'y'])->assertStatus(402);
    }

    public function test_slack_secrets_never_serialized(): void
    {
        $ws = $this->slackWorkspace();
        $this->assertArrayNotHasKey('slack', $ws->toArray());
    }

    // ---------------- Pulse ----------------

    public function test_pulse_dashboard_is_super_admin_only(): void
    {
        $user = User::factory()->create();
        $this->assertFalse(Gate::forUser($user)->allows('viewPulse'));
        $user->forceFill(['is_admin' => true])->save();
        $this->assertTrue(Gate::forUser($user->fresh())->allows('viewPulse'));
    }
}
