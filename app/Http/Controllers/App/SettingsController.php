<?php

namespace App\Http\Controllers\App;

use App\Brain\Data\Message;
use App\Brain\LlmManager;
use App\Channels\Telegram;
use App\Etl\WorkspaceBackup;
use App\Http\Controllers\Controller;
use App\Mail\WorkspaceInvitation;
use App\Models\Agent;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Workspace;
use App\Saas\Quota;
use App\Security\UrlGuard;
use App\Support\Locale;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    /* ---------- General ---------- */
    public function general(LlmManager $llm)
    {
        return view('app.settings.general', ['providers' => $llm->available(), 'timezones' => \DateTimeZone::listIdentifiers()]);
    }

    public function saveGeneral(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'business_description' => 'nullable|string|max:2000',
            'tone' => 'nullable|string|max:200',
            'instructions' => 'nullable|string|max:20000',
            'default_provider' => 'nullable|string|max:40',
            'embedding_provider' => 'nullable|string|max:40',
            'timezone' => ['nullable', Rule::in(\DateTimeZone::listIdentifiers())],
            'language' => ['nullable', 'string', Rule::in(array_keys(Locale::supported()))],
            'webhook_url' => 'nullable|url|max:500',
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $ws = Tenant::current();
        $ws->update(['name' => $data['name']]);
        $ws->updateSettings(collect($data)->except('name')->all() + [
            'business_name' => $data['name'],
            'require_approval' => $request->boolean('require_approval'),
        ]);
        AuditLog::record('settings.updated');

        return back()->with('status', __('Settings saved.'));
    }

    /* ---------- AI providers (BYOK) ---------- */
    public function providers(LlmManager $llm)
    {
        return view('app.settings.providers', [
            'catalog' => collect(config('brain.providers'))->except('fake'),
            'keys' => collect(Tenant::current()->provider_keys ?? [])->map(fn ($k) => array_merge($k, ['api_key' => isset($k['api_key']) ? Str::mask($k['api_key'], '•', 4, -4) : null])),
            'available' => $llm->available(),
        ]);
    }

    public function saveProvider(Request $request, LlmManager $llm)
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/'],
            'api_key' => 'nullable|string|max:500',
            'model' => 'nullable|string|max:120',
            'embedding_model' => 'nullable|string|max:120',
            'base_url' => 'nullable|url|max:300',
            'baa' => 'nullable|boolean',
            'driver' => 'nullable|in:openai,anthropic,gemini',
            'remove' => 'nullable|boolean',
        ]);
        $ws = Tenant::current();
        $keys = $ws->provider_keys ?? [];

        if (! $request->boolean('remove') && filled($data['base_url'] ?? null)) {
            try {
                UrlGuard::assertPublic($data['base_url']);
            } catch (\InvalidArgumentException $e) {
                return back()->withErrors(['base_url' => __('That address points to a private, internal or unreachable network.')])->withInput();
            }
        }

        if ($request->boolean('remove')) {
            unset($keys[$data['provider']]);
        } else {
            $existing = $keys[$data['provider']] ?? [];
            $keys[$data['provider']] = array_filter([
                'api_key' => $data['api_key'] ?: ($existing['api_key'] ?? null),
                'model' => $data['model'] ?? null,
                'embedding_model' => $data['embedding_model'] ?? null,
                'base_url' => $data['base_url'] ?? null,
                'driver' => config("brain.providers.{$data['provider']}") ? null : ($data['driver'] ?? 'openai'),
                'baa' => $request->boolean('baa') ?: null, // you have a signed BAA with this vendor (HIPAA mode)
            ]);
        }
        $ws->provider_keys = $keys;
        $ws->save();
        $llm->flush();
        AuditLog::record('provider.updated', null, ['provider' => $data['provider'], 'removed' => $request->boolean('remove')]);

        return back()->with('status', __('Provider saved.'));
    }

    public function testProvider(Request $request, LlmManager $llm)
    {
        $name = $request->validate(['provider' => 'required|string'])['provider'];
        try {
            $t = microtime(true);
            $r = $llm->provider($name)->chat([Message::user('Reply with the single word: pong')], [], ['max_tokens' => 256]);

            return back()->with('status', __(':name works: ":reply" in :ms ms', ['name' => $name, 'reply' => Str::limit(trim((string) $r->content), 30), 'ms' => round((microtime(true) - $t) * 1000)]));
        } catch (\Throwable $e) {
            return back()->withErrors(['provider' => __(':name failed: :error', ['name' => $name, 'error' => $e->getMessage()])]);
        }
    }

    /* ---------- API tokens ---------- */
    public function api()
    {
        return view('app.settings.api', ['tokens' => ApiToken::where('workspace_id', Tenant::id())->latest()->get()]);
    }

    public function createToken(Request $request)
    {
        Quota::feature(Tenant::current(), 'api');
        $name = $request->validate(['name' => 'required|string|max:80'])['name'];
        [$token, $plain] = ApiToken::issue(Tenant::current(), $name, $request->user());
        AuditLog::record('api_token.created', $token, ['name' => $name]);

        return back()->with('secret', $plain);
    }

    public function revokeToken(int $id)
    {
        $token = ApiToken::where('workspace_id', Tenant::id())->findOrFail($id);
        AuditLog::record('api_token.revoked', $token, ['name' => $token->name]);
        $token->delete();

        return back()->with('status', __('Token revoked.'));
    }

    /* ---------- Channels: widget, telegram, briefings ---------- */
    public function channels()
    {
        return view('app.settings.channels', ['widgetAgent' => Agent::where('widget_enabled', true)->first()]);
    }

    public function saveBriefing(Request $request)
    {
        $data = $request->validate(['briefing_emails' => 'nullable|string|max:1000', 'briefing_hour' => 'required|integer|between:0,23']);
        if ($request->boolean('briefing_enabled')) {
            Quota::feature(Tenant::current(), 'briefings');
        }
        Tenant::current()->updateSettings($data + ['briefing_enabled' => $request->boolean('briefing_enabled')]);

        return back()->with('status', __('Briefing settings saved.'));
    }

    public function sendBriefingNow()
    {
        Quota::feature(Tenant::current(), 'briefings');
        $ws = Tenant::current();
        $ws->updateSettings(['briefing_enabled' => true]);
        Artisan::call('brain:briefings', ['--force' => true]);

        return back()->with('status', __('Briefing generated and sent to :emails.', ['emails' => $ws->setting('briefing_emails')]));
    }

    public function saveTelegram(Request $request, Telegram $telegram)
    {
        Quota::feature(Tenant::current(), 'telegram');
        $data = $request->validate(['telegram_bot_token' => 'nullable|string|max:200']);
        $ws = Tenant::current();

        if (blank($data['telegram_bot_token'])) {
            if ($ws->telegram_bot_token) {
                $telegram->api('deleteWebhook');
            }
            $ws->forceFill(['telegram_bot_token' => null, 'telegram_secret' => null])->save();

            return back()->with('status', __('Telegram disconnected.'));
        }

        $ws->forceFill(['telegram_bot_token' => $data['telegram_bot_token']])->save();
        $result = $telegram->connect(route('channels.telegram', $ws->uuid));

        return ($result['ok'] ?? false)
            ? back()->with('status', __('Telegram bot connected. Message your bot to try it.'))
            : back()->withErrors(['telegram' => __('Telegram said: :error (your app must be reachable over HTTPS).', ['error' => $result['description'] ?? __('unknown error')])]);
    }

    public function saveWhatsApp(Request $request)
    {
        Quota::feature(Tenant::current(), 'whatsapp');
        $data = $request->validate([
            'phone_number_id' => 'nullable|string|max:40',
            'token' => 'nullable|string|max:600',
            'app_secret' => 'nullable|string|max:200',
        ]);
        $ws = Tenant::current();
        if (blank($data['phone_number_id'])) {
            $ws->forceFill(['whatsapp' => null])->save();

            return back()->with('status', __('WhatsApp disconnected.'));
        }
        $current = $ws->whatsapp ?? [];
        $ws->forceFill(['whatsapp' => [
            'phone_number_id' => $data['phone_number_id'],
            'token' => $data['token'] ?: ($current['token'] ?? null),
            'app_secret' => $data['app_secret'] ?: ($current['app_secret'] ?? null),
            'verify_token' => $current['verify_token'] ?? Str::random(32),
        ]])->save();
        AuditLog::record('channel.whatsapp_updated');

        return back()->with('status', __('WhatsApp saved. Add the callback URL and verify token in Meta → WhatsApp → Configuration.'));
    }

    public function saveSso(Request $request)
    {
        $data = $request->validate([
            'sso_domain' => ['nullable', 'string', 'max:191', 'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/i'],
            'sso_default_role' => 'required|in:member,admin',
        ]);
        $domain = Str::lower((string) ($data['sso_domain'] ?? ''));
        if ($domain !== '' && in_array($domain, ['gmail.com', 'outlook.com', 'hotmail.com', 'yahoo.com', 'icloud.com', 'proton.me', 'live.com'], true)) {
            return back()->withErrors(['sso_domain' => __('Use your company domain, not a public email provider.')]);
        }
        $taken = $domain !== '' && Workspace::where('id', '!=', Tenant::id())->get()->contains(fn ($w) => $w->setting('sso_domain') === $domain);
        if ($taken) {
            return back()->withErrors(['sso_domain' => __('That domain is already claimed by another workspace.')]);
        }
        Tenant::current()->updateSettings(['sso_domain' => $domain ?: null, 'sso_default_role' => $data['sso_default_role'], 'enforce_sso' => $request->boolean('enforce_sso')]);
        AuditLog::record('sso.updated', null, ['domain' => $domain, 'enforce' => $request->boolean('enforce_sso')]);

        return back()->with('status', __('Single sign-on saved.'));
    }

    public function saveSlack(Request $request)
    {
        Quota::feature(Tenant::current(), 'slack');
        $data = $request->validate(['bot_token' => 'nullable|string|max:300', 'signing_secret' => 'nullable|string|max:200', 'disconnect' => 'nullable|boolean']);
        $ws = Tenant::current();
        if ($request->boolean('disconnect')) {
            $ws->forceFill(['slack' => null])->save();

            return back()->with('status', __('Slack disconnected.'));
        }
        $current = $ws->slack ?? [];
        $ws->forceFill(['slack' => [
            'bot_token' => $data['bot_token'] ?: ($current['bot_token'] ?? null),
            'signing_secret' => $data['signing_secret'] ?: ($current['signing_secret'] ?? null),
        ]])->save();
        AuditLog::record('channel.slack_updated');

        return back()->with('status', __('Slack saved. Paste the Request URL into your Slack app → Event Subscriptions.'));
    }

    public function rotateWidgetKey()
    {
        Tenant::current()->forceFill(['widget_key' => 'wk_'.Str::random(32)])->save();

        return back()->with('status', __('Widget key rotated. Update the embed code on your site.'));
    }

    /* ---------- Team ---------- */
    public function team()
    {
        $ws = Tenant::current();

        return view('app.settings.team', [
            'members' => $ws->members()->orderBy('name')->get(),
            'invitations' => $ws->invitations()->whereNull('accepted_at')->latest()->get(),
        ]);
    }

    public function invite(Request $request)
    {
        $ws = Tenant::current();
        $data = $request->validate(['email' => 'required|email|max:191', 'role' => 'required|in:admin,member']);
        abort_if($ws->members()->where('email', $data['email'])->exists(), 422, 'Already a member.');
        Quota::ensure($ws, 'members');

        $invitation = $ws->invitations()->create($data + ['token' => Str::random(48), 'invited_by' => $request->user()->id, 'expires_at' => now()->addDays(7)]);
        Mail::to($data['email'])->send((new WorkspaceInvitation($invitation->load('workspace')))->locale(app()->getLocale()));
        AuditLog::record('member.invited', $invitation, ['email' => $data['email'], 'role' => $data['role']]);

        return back()->with('status', __('Invitation sent to :email. Link: :link', ['email' => $data['email'], 'link' => route('invitations.accept', $invitation->token)]));
    }

    public function cancelInvite(int $id)
    {
        Tenant::current()->invitations()->findOrFail($id)->delete();

        return back()->with('status', __('Invitation cancelled.'));
    }

    public function updateMember(Request $request, int $userId)
    {
        $ws = Tenant::current();
        abort_if($userId === $ws->owner_id, 422, 'The owner\'s role cannot change.');
        $member = $ws->members()->findOrFail($userId);

        if ($request->boolean('remove')) {
            $ws->members()->detach($userId);
            AuditLog::record('member.removed', $member);

            return back()->with('status', __('Member removed.'));
        }

        $role = $request->validate(['role' => 'required|in:admin,member'])['role'];
        $ws->members()->updateExistingPivot($userId, ['role' => $role]);
        AuditLog::record('member.role_changed', $member, ['role' => $role]);

        return back()->with('status', __('Role updated.'));
    }

    /* ---------- Data: backup / restore / audit ---------- */
    public function data()
    {
        $ws = Tenant::current();

        return view('app.settings.data', [
            'logs' => $ws->plan()->has('audit_log') ? AuditLog::with('user')->where('workspace_id', $ws->id)->latest('id')->paginate(40) : null,
        ]);
    }

    public function backup(WorkspaceBackup $backup)
    {
        $path = $backup->export(Tenant::current());
        AuditLog::record('workspace.backup');

        return response()->download($path)->deleteFileAfterSend();
    }

    public function restore(Request $request, WorkspaceBackup $backup)
    {
        $request->validate(['backup' => 'required|file|max:512000|extensions:zip']);
        $ws = $backup->import($request->file('backup')->getRealPath(), $request->user());

        return redirect()->route('dashboard')->with('status', __('Backup restored as new workspace ":name". Switch to it from the sidebar.', ['name' => $ws->name]));
    }

    public function destroyWorkspace(Request $request)
    {
        $ws = Tenant::current();
        $request->validate(['confirm' => 'required|in:'.$ws->name]);
        if ($ws->subscribed('default')) {
            $ws->subscription('default')->cancelNow();
        }
        $request->user()->forceFill(['current_workspace_id' => null])->save();
        $ws->delete();

        return redirect()->route('dashboard')->with('status', __('Workspace deleted.'));
    }
}
