@extends('layouts.guest')
@section('title', __('Sign in'))
@section('content')
<h1>{{ __('Sign in') }}</h1>
@if($sso = \App\Security\Sso::enabled())
<div class="grid" style="gap:8px;margin:12px 0">
@foreach($sso as $key => $label)<a class="btn ghost" style="justify-content:center" href="{{ route('sso.redirect', $key) }}">{{ __('Continue with :provider', ['provider' => $label]) }}</a>@endforeach
</div><p class="muted" style="text-align:center">{{ __('or with email') }}</p>
@endif
<form method="post" action="/login">@csrf
  <label for="email">{{ __('Email') }}</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
  <label for="password">{{ __('Password') }}</label><input id="password" name="password" type="password" required autocomplete="current-password">
  <div class="row" style="margin-top:12px"><label style="margin:0;font-weight:400"><input type="checkbox" name="remember" style="width:auto"> {{ __('Remember me') }}</label><a class="right" href="{{ route('password.request') }}">{{ __('Forgot?') }}</a></div>
  <button class="btn" style="width:100%;margin-top:16px;justify-content:center">{{ __('Sign in') }}</button>
</form>
@if(config('saas.allow_registration'))<p class="muted" style="text-align:center">{{ __('New here?') }} <a href="/register">{{ __('Create your workspace') }}</a></p>@endif
@endsection
