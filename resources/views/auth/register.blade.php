@extends('layouts.guest')
@section('title', __('Create account'))
@section('content')
<h1>{{ $invitation ? __('Join :workspace', ['workspace' => $invitation->workspace->name]) : __('Start free') }}</h1>
<p class="sub">{{ $invitation ? __('Create your account to accept the invitation.') : __(':days-day Pro trial. No card needed.', ['days' => config('saas.trial_days')]) }}</p>
<form method="post" action="/register">@csrf
  <label for="name">{{ __('Your name') }}</label><input id="name" name="name" value="{{ old('name') }}" required autocomplete="name">
  <label for="email">{{ __('Work email') }}</label><input id="email" name="email" type="email" value="{{ old('email', $invitation?->email) }}" required autocomplete="email">
  @unless($invitation)<label for="company">{{ __('Company name') }}</label><input id="company" name="company" value="{{ old('company') }}" required autocomplete="organization">@endunless
  <label for="password">{{ __('Password') }}</label><input id="password" name="password" type="password" required autocomplete="new-password">
  <label for="password_confirmation">{{ __('Confirm password') }}</label><input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
  <button class="btn" style="width:100%;margin-top:16px;justify-content:center">{{ __('Create account') }}</button>
</form>
<p class="muted" style="text-align:center">{{ __('Have an account?') }} <a href="/login">{{ __('Sign in') }}</a></p>
@endsection
