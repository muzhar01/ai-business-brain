@extends('layouts.guest')
@section('title', __('Reset password'))
@section('content')
<h1>{{ __('Reset password') }}</h1>
<form method="post" action="{{ route('password.email') }}">@csrf
  <label for="email">{{ __('Email') }}</label><input id="email" name="email" type="email" required autocomplete="email">
  <button class="btn" style="width:100%;margin-top:16px;justify-content:center">{{ __('Send reset link') }}</button>
</form>
<p class="muted" style="text-align:center"><a href="{{ route('login') }}">{{ __('Back to sign in') }}</a></p>
@endsection
