@extends('layouts.app')
@section('title', __('Security'))
@section('content')
<h1>{{ __('Security') }}</h1>
<div class="row" style="margin:10px 0 18px"><a class="btn ghost sm" href="{{ route('profile') }}">{{ __('Profile') }}</a><a class="btn sm" href="{{ route('profile.security') }}">{{ __('Security') }}</a></div>
@if(session('recovery_codes'))
<div class="card" role="alert"><b>{{ __('Save these recovery codes now.') }}</b> {{ __('Each works once if you lose your phone. They won\'t be shown again.') }}
<pre dir="ltr">{{ implode("\n", session('recovery_codes')) }}</pre></div>
@endif
<div class="card"><div class="row"><b>{{ __('Two-factor authentication') }}</b>
@if(auth()->user()->two_factor_confirmed_at)<span class="pill ok">{{ __('On since :date', ['date' => auth()->user()->two_factor_confirmed_at->toFormattedDateString()]) }}</span>@else<span class="pill warn">{{ __('Off') }}</span>@endif</div>
@if(auth()->user()->two_factor_confirmed_at)
 <form method="post" action="{{ route('profile.2fa.disable') }}" class="row" style="margin-top:12px">@csrf @method('delete')<input name="current_password" type="password" placeholder="{{ __('Password to turn off') }}" aria-label="{{ __('Password to turn off') }}" autocomplete="current-password" required style="max-width:260px"><button class="btn ghost">{{ __('Turn off') }}</button></form>
@elseif($qr)
 <p class="muted">{{ __('Scan with Google Authenticator, 1Password, Authy or Microsoft Authenticator.') }}</p>
 <div class="row"><div style="background:#fff;padding:8px;border-radius:8px" role="img" aria-label="{{ __('QR code') }}">{!! $qr !!}</div><div><div class="hint">{{ __('Or enter this key') }}</div><code dir="ltr">{{ $secret }}</code></div></div>
 <form method="post" action="{{ route('profile.2fa.confirm') }}" class="row" style="margin-top:12px">@csrf<input name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="{{ __('6-digit code') }}" required style="max-width:200px" aria-label="{{ __('Authentication code') }}" dir="ltr"><button class="btn">{{ __('Confirm') }}</button></form>
@else
 <p class="muted">{{ __('Protect your account with a code from your phone at every sign-in.') }}</p>
 <form method="post" action="{{ route('profile.2fa.enable') }}" class="row">@csrf<input name="current_password" type="password" placeholder="{{ __('Your password') }}" required autocomplete="current-password" style="max-width:260px" aria-label="{{ __('Password') }}"><button class="btn">{{ __('Set up') }}</button></form>
@endif
</div>
<div class="card"><b>{{ __('Recent sign-in activity') }}</b>
<div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('Security activity') }}"><table style="margin-top:8px"><tr><th>{{ __('When') }}</th><th>{{ __('Event') }}</th><th>{{ __('IP') }}</th><th>{{ __('Device') }}</th></tr>
@forelse($events as $e)<tr><td class="muted">{{ $e->created_at?->diffForHumans() }}</td><td><span class="pill {{ in_array($e->event, ['failed','2fa_failed','locked']) ? 'bad' : '' }}">{{ \Illuminate\Support\Facades\Lang::has('event.'.$e->event) ? __('event.'.$e->event) : $e->event }}</span></td><td class="mono">{{ $e->ip }}</td><td class="muted">{{ \Illuminate\Support\Str::limit($e->user_agent, 60) }}</td></tr>@empty<tr><td colspan="4" class="muted">{{ __('No activity yet.') }}</td></tr>@endforelse</table></div></div>
@endsection
