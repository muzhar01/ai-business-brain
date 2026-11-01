@extends('layouts.app')
@section('title', __('Agents'))
@section('content')
<div class="row"><div><h1>{{ __('Agents') }}</h1><p class="sub">{{ __('Specialised assistants with their own role, model, knowledge and allowed actions: Sales, Support, HR, Finance…') }}</p></div><a class="btn right" href="{{ route('agents.create') }}">{{ __('+ New agent') }}</a></div>
<div class="grid g3">
@foreach($agents as $a)
<div class="card"><div class="row"><b>{{ $a->name }}</b>@if($a->is_default)<span class="pill ok">{{ __('default') }}</span>@endif @if($a->widget_enabled)<span class="pill">{{ __('website / telegram') }}</span>@endif</div>
<p class="muted">{{ $a->description ?: __('No description') }}</p>
<div class="hint">{{ $a->provider ?: __('workspace default model') }}{{ $a->model ? ' · '.$a->model : '' }} · {{ is_array($a->tools) ? trans_choice(':count actions', count($a->tools)) : __('all actions') }}{{ $a->collection ? ' · '.__('knowledge: :name', ['name' => $a->collection]) : '' }}</div>
<div class="row" style="margin-top:12px"><a class="btn ghost sm" href="{{ route('agents.edit', $a) }}">{{ __('Edit') }}</a>
<form method="post" action="{{ route('agents.destroy', $a) }}" onsubmit="return confirm(@js(__('Delete agent?')))">@csrf @method('delete')<button class="btn ghost sm">{{ __('Delete') }}</button></form></div></div>
@endforeach
</div>
@endsection
