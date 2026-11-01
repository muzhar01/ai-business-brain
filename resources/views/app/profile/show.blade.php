@extends('layouts.app')
@section('title', __('Profile'))
@section('content')
<h1>{{ __('Your profile') }}</h1>
<div class="row" style="margin:10px 0 18px"><a class="btn sm" href="{{ route('profile') }}">{{ __('Profile') }}</a><a class="btn ghost sm" href="{{ route('profile.security') }}">{{ __('Security') }}</a></div>
<div class="grid g2">
<form method="post" action="{{ route('profile.update') }}" class="card">@csrf @method('patch')
<b>{{ __('Details') }}</b>
<label for="name">{{ __('Name') }}</label><input id="name" name="name" value="{{ auth()->user()->name }}" required autocomplete="name">
<label for="email">{{ __('Email') }}</label><input id="email" name="email" type="email" value="{{ auth()->user()->email }}" required autocomplete="email">
<button class="btn" style="margin-top:12px">{{ __('Save') }}</button></form>
<form method="post" action="{{ route('profile.password') }}" class="card">@csrf @method('put')
<b>{{ __('Password') }}</b>
<label for="cp">{{ __('Current password') }}</label><input id="cp" name="current_password" type="password" required autocomplete="current-password">
<label for="np">{{ __('New password') }}</label><input id="np" name="password" type="password" required autocomplete="new-password"><div class="hint">{{ __('At least :n characters with upper and lower case letters and a number. Breached passwords are rejected.', ['n' => config('brain.security.password_min')]) }}</div>
<label for="np2">{{ __('Confirm') }}</label><input id="np2" name="password_confirmation" type="password" required autocomplete="new-password">
<button class="btn" style="margin-top:12px">{{ __('Change password') }}</button></form>
</div>
<div class="card" id="language"><b>{{ __('Language') }}</b>
@include('partials.locale-switcher', ['compact' => false])
</div>
<div class="card"><b>{{ __('Your data') }}</b><p class="muted">{{ __('Download everything we hold about you (CCPA right to know), or delete your account (right to delete).') }}</p>
<a class="btn ghost" href="{{ route('profile.export') }}">{{ __('Download my data') }}</a>
<details style="margin-top:14px"><summary class="st-failed">{{ __('Delete my account') }}</summary>
<form method="post" action="{{ route('profile.destroy') }}" class="row" style="margin-top:10px">@csrf @method('delete')
<input name="current_password" type="password" placeholder="{{ __('Password') }}" aria-label="{{ __('Password') }}" required autocomplete="current-password" style="max-width:220px"><input name="confirm" placeholder="{{ __('Type DELETE') }}" aria-label="{{ __('Type DELETE') }}" required autocomplete="off" dir="ltr" style="max-width:160px"><button class="btn danger">{{ __('Delete forever') }}</button></form></details></div>
@endsection
