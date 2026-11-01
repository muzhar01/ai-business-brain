@extends('layouts.app')
@section('title', __('Leads'))
@section('content')
<div class="row"><div><h1>{{ __('Leads') }}</h1><p class="sub">{{ __('Your CRM. The brain adds and updates these from chat, the widget and imports.') }}</p></div>
<div class="right row"><a class="btn ghost" href="{{ route('imports.create', ['target' => 'leads']) }}">{{ __('Import') }}</a><a class="btn ghost" href="{{ route('exports.index') }}">{{ __('Export') }}</a></div></div>
<div class="row" style="margin-bottom:14px">
  <a class="pill {{ request('status') ? '' : 'ok' }}" href="{{ route('leads.index') }}">{{ __('All') }}</a>
  @foreach($statuses as $s)<a class="pill {{ request('status') === $s ? 'ok' : '' }}" href="?status={{ $s }}">{{ __($s) }} {{ $counts[$s] ?? 0 }}</a>@endforeach
  <form class="right"><input name="q" value="{{ request('q') }}" placeholder="{{ __('Search…') }}" aria-label="{{ __('Search') }}"></form>
</div>
<details class="card"><summary><b>{{ __('+ Add lead') }}</b></summary>
<form method="post" action="{{ route('leads.store') }}" class="grid g4" style="margin-top:10px">@csrf
<input name="name" placeholder="{{ __('Name *') }}" aria-label="{{ __('Name') }}" required><input name="email" placeholder="{{ __('Email') }}" aria-label="{{ __('Email') }}"><input name="phone" placeholder="{{ __('Phone') }}" aria-label="{{ __('Phone') }}"><input name="company" placeholder="{{ __('Company') }}" aria-label="{{ __('Company') }}">
<select name="status" aria-label="{{ __('Status') }}">@foreach($statuses as $s)<option value="{{ $s }}">{{ __($s) }}</option>@endforeach</select><input name="value" type="number" step="0.01" placeholder="{{ __('Value') }}" aria-label="{{ __('Value') }}"><input name="source" placeholder="{{ __('Source') }}" aria-label="{{ __('Source') }}"><button class="btn">{{ __('Add') }}</button>
</form></details>
<div class="card"><div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('Leads') }}"><table><tr><th>{{ __('Name') }}</th><th>{{ __('Company') }}</th><th>{{ __('Contact') }}</th><th>{{ __('Status') }}</th><th>{{ __('Value') }}</th><th>{{ __('Updated') }}</th><th></th></tr>
@forelse($leads as $l)
<tr><td><b>{{ $l->name }}</b>@if($l->notes)<div class="hint">{{ \Illuminate\Support\Str::limit($l->notes, 90) }}</div>@endif</td><td>{{ $l->company }}</td><td>{{ $l->email }}<div class="hint">{{ $l->phone }}</div></td>
<td><form method="post" action="{{ route('leads.update', $l) }}">@csrf @method('patch')<select name="status" aria-label="{{ __('Status') }}" onchange="this.form.submit()" style="padding:4px 8px">@foreach($statuses as $s)<option value="{{ $s }}" @selected($l->status === $s)>{{ __($s) }}</option>@endforeach</select></form></td>
<td>{{ $l->value !== null ? number_format($l->value, 2) : '' }}</td><td class="muted">{{ $l->updated_at->diffForHumans() }}</td>
<td><form method="post" action="{{ route('leads.destroy', $l) }}" onsubmit="return confirm(@js(__('Delete lead?')))">@csrf @method('delete')<button class="btn ghost sm" aria-label="{{ __('Delete') }}">✕</button></form></td></tr>
@empty<tr><td colspan="7" class="muted">{{ __('No leads yet.') }}</td></tr>@endforelse</table></div>{{ $leads->links() }}</div>
@endsection
