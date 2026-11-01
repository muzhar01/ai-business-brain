@extends('layouts.guest')
@section('title', __('New workspace'))
@section('content')
<h1>{{ __('New workspace') }}</h1>
<p class="sub">{{ __('One workspace per business or client. Each has its own data, team, agents and billing.') }}</p>
<form method="post" action="{{ route('workspaces.store') }}">@csrf
  <label for="name">{{ __('Company name') }}</label><input id="name" name="name" required autofocus autocomplete="organization">
  <button class="btn" style="width:100%;margin-top:16px;justify-content:center">{{ __('Create') }}</button>
</form>
@endsection
