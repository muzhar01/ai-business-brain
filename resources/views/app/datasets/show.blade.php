@extends('layouts.app')
@section('title', $dataset->name)
@section('content')
<div class="row"><div><h1>{{ $dataset->name }}</h1><p class="sub"><span class="mono">{{ $dataset->slug }}</span> · {{ number_format($dataset->row_count) }} {{ __('rows') }}</p></div>
<div class="right row"><a class="btn ghost" href="{{ route('imports.create', ['target' => 'dataset']) }}">{{ __('Import more') }} <span class="dir-arrow" aria-hidden="true">→</span></a>
<form method="post" action="{{ route('datasets.destroy', $dataset) }}" onsubmit="return confirm(@js(__('Delete dataset and all rows?')))">@csrf @method('delete')<button class="btn danger">{{ __('Delete') }}</button></form></div></div>
<form method="post" action="{{ route('datasets.update', $dataset) }}" class="card">@csrf @method('patch')
<div class="grid g2"><div><label for="ds-name">{{ __('Name') }}</label><input id="ds-name" name="name" value="{{ $dataset->name }}"></div><div><label for="ds-desc">{{ __('Description (helps the brain know when to use it)') }}</label><input id="ds-desc" name="description" value="{{ $dataset->description }}" placeholder="{{ __('Current retail prices and stock per SKU') }}"></div></div>
<button class="btn ghost sm" style="margin-top:10px">{{ __('Save') }}</button></form>
<div class="card" style="overflow:auto"><form class="row" style="margin-bottom:10px"><input name="q" value="{{ request('q') }}" placeholder="{{ __('Search rows') }}" aria-label="{{ __('Search rows') }}" style="max-width:320px"></form>
@php($cols = $dataset->fieldNames())
<div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('Dataset rows') }}"><table><tr><th>#</th>@foreach($cols as $c)<th>{{ $c }}</th>@endforeach</tr>
@forelse($rows as $r)<tr><td class="muted">{{ $r->id }}</td>@foreach($cols as $c)<td>{{ is_array($r->data[$c] ?? null) ? json_encode($r->data[$c]) : ($r->data[$c] ?? '') }}</td>@endforeach</tr>@empty<tr><td class="muted">{{ __('No rows.') }}</td></tr>@endforelse
</table></div>{{ $rows->links() }}</div>
@endsection
