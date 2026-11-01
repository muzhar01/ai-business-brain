@extends('layouts.guest')
@section('title', __('Two-factor check'))
@section('content')
<h1>{{ __('Two-factor check') }}</h1>
<p class="sub">{{ __('Enter the 6-digit code from your authenticator app, or a recovery code.') }}</p>
<form method="post" action="{{ route('two-factor.challenge') }}">@csrf
  <label for="code">{{ __('Code') }}</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus dir="ltr">
  <button class="btn" style="width:100%;margin-top:16px;justify-content:center">{{ __('Verify') }}</button>
</form>
@endsection
