@extends('layouts.app')
@section('title', __('Channels'))
@section('content')
@include('partials.settings-tabs')
<div class="card"><div class="row"><b>🌐 {{ __('Website chat widget') }}</b>@unless($plan->has('widget'))<span class="pill warn">{{ __('Pro') }}</span>@endunless</div>
<p class="muted">{{ __('Paste before the closing body tag on your site. Visitors get answers from your knowledge and datasets, and leave their details as leads.') }} {{ __('Answering agent:') }} <b>{{ $widgetAgent?->name ?? __('default agent') }}</b> (<a href="{{ route('agents.index') }}">{{ __('change') }}</a>).</p>
<pre dir="ltr">&lt;script src="{{ route('widget.script') }}" data-key="{{ $workspace->widget_key }}" defer&gt;&lt;/script&gt;</pre>
<form method="post" action="{{ route('settings.widget.rotate') }}">@csrf<button class="btn ghost sm">{{ __('Rotate key') }}</button></form></div>

<form method="post" action="{{ route('settings.telegram') }}" class="card">@csrf
<div class="row"><b>✈️ {{ __('Telegram bot') }}</b>@unless($plan->has('telegram'))<span class="pill warn">{{ __('Pro') }}</span>@endunless @if($workspace->telegram_bot_token)<span class="pill ok">{{ __('connected') }}</span>@endif</div>
<p class="muted">{{ __('Create a bot with @BotFather, paste its token. Leave empty to disconnect.') }}</p>
<div class="row"><input name="telegram_bot_token" type="password" dir="ltr" aria-label="{{ __('Telegram bot token') }}" placeholder="{{ $workspace->telegram_bot_token ? __('•••••• connected') : '123456:ABC-DEF…' }}" style="max-width:420px"><button class="btn">{{ __('Save') }}</button></div></form>

<form method="post" action="{{ route('settings.slack') }}" class="card">@csrf
<div class="row"><b>💬 {{ __('Slack') }}</b>@unless($plan->has('slack'))<span class="pill warn">{{ __('Pro') }}</span>@endunless @if($workspace->slack)<span class="pill ok">{{ __('configured') }}</span>@endif @if($workspace->hipaa())<span class="pill bad">{{ __('off in HIPAA mode') }}</span>@endif</div>
<p class="muted">{{ __('Create a Slack app with scopes app_mentions:read, chat:write, im:history.') }} {{ __('Event Subscriptions, then Request URL:') }} <code dir="ltr">{{ route('channels.slack', $workspace->uuid) }}</code>. {{ __('Events: app_mention and message.im.') }}</p>
<div class="grid g2"><div><label for="sbt">{{ __('Bot token (xoxb-…)') }}</label><input id="sbt" name="bot_token" type="password" placeholder="{{ $workspace->slack ? __('leave blank to keep') : '' }}"></div>
<div><label for="sss">{{ __('Signing secret') }}</label><input id="sss" name="signing_secret" type="password" placeholder="{{ $workspace->slack ? __('leave blank to keep') : '' }}"></div></div>
<div class="row" style="margin-top:12px"><button class="btn">{{ __('Save') }}</button>@if($workspace->slack)<button class="btn ghost" name="disconnect" value="1">{{ __('Disconnect') }}</button>@endif</div></form>

<form method="post" action="{{ route('settings.whatsapp') }}" class="card">@csrf
<div class="row"><b>🟢 {{ __('WhatsApp Business') }}</b>@unless($plan->has('whatsapp'))<span class="pill warn">{{ __('Pro') }}</span>@endunless @if($workspace->whatsapp)<span class="pill ok">{{ __('configured') }}</span>@endif @if($workspace->hipaa())<span class="pill bad">{{ __('off in HIPAA mode') }}</span>@endif</div>
<p class="muted">{{ __('Meta Cloud API.') }} {{ __('Callback URL:') }} <code dir="ltr">{{ route('channels.whatsapp', $workspace->uuid) }}</code> @if($workspace->whatsapp) · {{ __('Verify token:') }} <code dir="ltr">{{ $workspace->whatsapp['verify_token'] ?? '' }}</code>@endif</p>
<div class="grid g3"><div><label for="wpn">{{ __('Phone number ID') }}</label><input id="wpn" name="phone_number_id" value="{{ $workspace->whatsapp['phone_number_id'] ?? '' }}"></div>
<div><label for="wtk">{{ __('Access token') }}</label><input id="wtk" name="token" type="password" placeholder="{{ $workspace->whatsapp ? __('leave blank to keep') : '' }}"></div>
<div><label for="was">{{ __('App secret (signature check)') }}</label><input id="was" name="app_secret" type="password" placeholder="{{ $workspace->whatsapp ? __('leave blank to keep') : '' }}"></div></div>
<button class="btn" style="margin-top:12px">{{ __('Save') }}</button></form>

<form method="post" action="{{ route('settings.briefing') }}" class="card">@csrf
<div class="row"><b>☀️ {{ __('Daily AI briefing') }}</b>@unless($plan->has('briefings'))<span class="pill warn">{{ __('Pro') }}</span>@endunless</div>
<p class="muted">{{ __('Every morning: key numbers, overdue tasks, hot leads and three actions for the day.') }}</p>
<label style="font-weight:400"><input type="checkbox" name="briefing_enabled" value="1" style="width:auto" @checked($workspace->setting('briefing_enabled'))> {{ __('Enabled') }}</label>
<div class="grid g2"><div><label for="bem">{{ __('Send to (comma separated)') }}</label><input id="bem" dir="ltr" name="briefing_emails" value="{{ $workspace->setting('briefing_emails') }}" placeholder="{{ 'ceo@company.com, sales@company.com' }}"></div>
<div><label for="bhr">{{ __('Hour (:tz)', ['tz' => $workspace->setting('timezone')]) }}</label><input id="bhr" type="number" min="0" max="23" name="briefing_hour" value="{{ $workspace->setting('briefing_hour', 8) }}"></div></div>
<div class="row" style="margin-top:12px"><button class="btn">{{ __('Save') }}</button><button class="btn ghost" formaction="{{ route('settings.briefing.send') }}">{{ __('Send one now') }}</button></div></form>
@endsection
