@extends('layouts.app')
@section('title', __('Super admin'))
@section('content')
<h1>{{ __('Super admin') }}</h1>
<p class="sub">{{ __('Every tenant on the platform.') }} <a href="/pulse">{{ __('Live performance (Pulse)') }}</a> · <a href="/horizon">{{ __('Queues (Horizon)') }}</a></p>
<div class="grid g4">
 <div class="card stat"><div class="l">{{ __('Workspaces') }}</div><div class="n">{{ $totals['workspaces'] }}</div></div>
 <div class="card stat"><div class="l">{{ __('Users') }}</div><div class="n">{{ $totals['users'] }}</div></div>
 <div class="card stat"><div class="l">{{ __('AI messages this month') }}</div><div class="n">{{ number_format($totals['messages']) }}</div><div class="muted">{{ __(':n tokens', ['n' => number_format($totals['tokens'])]) }}</div></div>
 <div class="card stat"><div class="l">{{ __('MRR') }}</div><div class="n">${{ number_format($totals['mrr']) }}</div></div>
</div>
<div class="card"><form class="row" style="margin-bottom:10px"><input name="q" type="search" value="{{ request('q') }}" placeholder="{{ __('Search workspaces') }}" aria-label="{{ __('Search workspaces') }}" style="max-width:320px"></form>
<div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('Workspaces') }}"><table><tr><th>{{ __('Workspace') }}</th><th>{{ __('Owner') }}</th><th>{{ __('Members') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Messages') }}</th><th>{{ __('Tokens') }}</th><th>{{ __('Created') }}</th><th>{{ __('Manage') }}</th></tr>
@foreach($workspaces as $w)@php($u = $usage[$w->id] ?? null)
<tr><td><b>{{ $w->name }}</b> @if($w->suspended)<span class="pill bad">{{ __('suspended') }}</span>@endif</td><td>{{ $w->owner?->email }}</td><td>{{ $w->members_count }}</td><td><span class="pill">{{ __($w->plan()->name()) }}</span></td>
<td>{{ number_format($u->messages ?? 0) }}</td><td>{{ number_format(($u->input_tokens ?? 0) + ($u->output_tokens ?? 0)) }}</td><td class="muted">{{ \App\Support\Format::date($w->created_at) }}</td>
<td><form method="post" action="{{ route('admin.update', $w) }}" class="row">@csrf @method('patch')
<select name="plan_override" aria-label="{{ __('Plan') }}" style="padding:4px 8px;width:auto"><option value="">{{ __('Stripe / trial') }}</option>@foreach($plans as $p)<option value="{{ $p->key }}" @selected($w->plan_override === $p->key)>{{ __($p->name()) }}</option>@endforeach</select>
<label style="margin:0;font-weight:400"><input type="checkbox" name="suspended" value="1" style="width:auto" @checked($w->suspended)> {{ __('suspend') }}</label><button class="btn ghost sm">{{ __('Save') }}</button></form>
<form method="post" action="{{ route('admin.enter', $w) }}">@csrf<button class="btn ghost sm">{{ __('Enter') }}</button></form></td></tr>
@endforeach</table></div>{{ $workspaces->links() }}</div>
@endsection
