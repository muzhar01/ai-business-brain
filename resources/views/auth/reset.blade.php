@extends('layouts.guest')
@section('title', __('New password'))
@section('content')
<h1>{{ __('Choose a new password') }}</h1>
<form method="post" action="{{ route('password.update') }}">@csrf
  <input type="hidden" name="token" value="{{ $token }}">
  <label for="email">{{ __('Email') }}</label><input id="email" name="email" type="email" value="{{ $email }}" required autocomplete="email">
  <label for="password">{{ __('New password') }}</label><input id="password" name="password" type="password" required autocomplete="new-password">
  <label for="password_confirmation">{{ __('Confirm password') }}</label><input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
  <button class="btn" style="width:100%;margin-top:16px;justify-content:center">{{ __('Update password') }}</button>
</form>
@endsection
