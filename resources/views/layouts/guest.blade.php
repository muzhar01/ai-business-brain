<!DOCTYPE html>
<html lang="{{ \App\Support\Direction::lang() }}" dir="{{ \App\Support\Direction::of() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title') · {{ config('saas.name') }}</title><link rel="stylesheet" href="{{ asset('css/app.css') }}"></head>
<body><main class="auth" id="main">
<div class="brand" style="justify-content:center;font-size:20px;margin-bottom:18px">🧠 {{ config('saas.name') }}</div>
@if(session('status'))<div class="flash" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="flash err" role="alert">{{ $errors->first() }}</div>@endif
<div class="card">@yield('content')</div>
@include('partials.locale-switcher', ['compact' => true])
</main></body></html>
