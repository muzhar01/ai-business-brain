@extends('layouts.app')
@section('title', __('Privacy & compliance'))
@section('content')
@include('partials.settings-tabs')
<form method="post" action="{{ route('settings.privacy') }}" class="card">@csrf
<b>{{ __('Security & compliance policy') }}</b>
<div class="grid g3">
 <div><label for="rd">{{ __('Delete conversations after (days)') }}</label><input id="rd" name="retention_days" type="number" min="0" value="{{ $workspace->setting('retention_days') }}"><div class="hint">{{ __('0 = keep forever') }}</div></div>
 <div><label for="st">{{ __('Sign out idle users after (minutes)') }}</label><input id="st" name="session_timeout" type="number" min="0" value="{{ $workspace->setting('session_timeout') }}"><div class="hint">{{ __('0 = off. HIPAA mode forces 15 or less.') }}</div></div>
 <div><label for="pn">{{ __('Notice shown to website visitors') }}</label><input id="pn" name="privacy_notice" value="{{ $workspace->setting('privacy_notice') }}" placeholder="{{ __('We store chats to answer you. See our privacy policy.') }}"></div>
</div>
<label style="font-weight:400;margin-top:12px"><input type="checkbox" name="require_2fa" value="1" style="width:auto" @checked($workspace->setting('require_2fa'))> {{ __('Require two-factor authentication for every member') }}</label>
<label style="font-weight:400"><input type="checkbox" name="hipaa_mode" value="1" style="width:auto" @checked($workspace->setting('hipaa_mode')) @disabled(! $plan->has('hipaa'))> {{ __('HIPAA mode') }} @unless($plan->has('hipaa'))<span class="pill warn">{{ __('Enterprise') }}</span>@endunless</label>
<div class="hint">{{ __('HIPAA mode: forces 2FA and a 15-minute idle timeout, logs every view of conversation content, turns off the public widget/Telegram/WhatsApp, and allows only AI providers you have a BAA with (:providers, or custom providers flagged BAA). You still need signed BAAs and your own HIPAA program.', ['providers' => implode(', ', $hipaaProviders) ?: __('none configured')]) }}</div>
<button class="btn" style="margin-top:14px">{{ __('Save policy') }}</button></form>

<div class="card"><b>{{ __('Consumer data requests (CCPA / GDPR)') }}</b><p class="muted">{{ __('Find everything held about one of your customers by email, phone, name or channel id, then export it or erase it. Requests are audit-logged.') }}</p>
<form class="row"><input name="subject" value="{{ $subject }}" dir="ltr" placeholder="{{ 'sara@nova.pk or +923001234567' }}" style="max-width:360px" aria-label="{{ __('Customer identifier') }}"><button class="btn ghost">{{ __('Find') }}</button></form>
@if($found)
 <p style="margin-top:12px">{{ __('Found :leads leads, :tasks tasks, :conversations conversations.', ['leads' => $found['leads']->count(), 'tasks' => $found['tasks']->count(), 'conversations' => $found['conversations']->count()]) }}</p>
 <div class="row">
 <form method="post" action="{{ route('settings.privacy.export') }}">@csrf<input type="hidden" name="subject" value="{{ $subject }}"><button class="btn">{{ __('Export (right to know)') }}</button></form>
 <form method="post" action="{{ route('settings.privacy.erase') }}" class="row" onsubmit='return confirm(@js(__('Permanently erase this person\'s data?')))'>@csrf<input type="hidden" name="subject" value="{{ $subject }}"><input name="confirm" placeholder="{{ __('Type ERASE') }}" aria-label="{{ __('Type ERASE') }}" dir="ltr" style="max-width:140px" required><button class="btn danger">{{ __('Erase (right to delete)') }}</button></form>
 </div>
@endif
</div>
<div class="card"><b>{{ __('What the platform does for you') }}</b>
<ul class="muted" style="margin:8px 0 0 18px">
<li>{{ __('Message content and lead notes encrypted at rest (AES-256, per-field)') }}</li><li>{{ __('TLS-only cookies, strict security headers, CSRF and SSRF protection') }}</li>
<li>{{ __('Tamper-evident (hash-chained) audit log, verified nightly') }}</li><li>{{ __('Login lockout, breached-password check, 2FA, sign-in history') }}</li>
<li>{{ __('Encrypted daily backups, retention enforcement, per-tenant isolation') }}</li><li>{{ __('We never sell personal information') }}</li></ul></div>
@endsection
