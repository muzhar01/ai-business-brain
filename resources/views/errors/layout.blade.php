<!DOCTYPE html>
<html lang="{{ \App\Support\Direction::lang() }}" dir="{{ \App\Support\Direction::of() }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>@yield('code') · @yield('title') · {{ config('app.name') }}</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}"></head>
<body><main id="main" class="auth" role="main">
<div class="card" style="text-align:center">
<p class="muted" style="font-size:48px;margin:0">@yield('code')</p>
<h1>@yield('title')</h1>
<p class="muted">@yield('message')</p>
<p><a class="btn" href="{{ url('/') }}">{{ __('Go home') }}</a></p>
</div></main></body></html>
