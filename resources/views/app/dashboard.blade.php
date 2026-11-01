@extends('layouts.app')
@section('title', __('Dashboard'))
@section('content')
<h1>{{ $workspace->name }}</h1>
<p class="sub">{{ __(':plan plan', ['plan' => $plan->name()]) }} @if($workspace->onGenericTrial()) · {{ __('trial ends :when', ['when' => $workspace->trial_ends_at->diffForHumans()]) }} @endif</p>

<div class="grid g4">
  <div class="card stat"><div class="l">{{ __('Open pipeline') }}</div><div class="n">{{ number_format($stats['pipeline']) }}</div><div class="muted">{{ trans_choice(':count leads', $stats['leads']) }} · {{ __('won :amount', ['amount' => number_format($stats['won'])]) }}</div></div>
  <div class="card stat"><div class="l">{{ __('Open tasks') }}</div><div class="n">{{ $stats['open_tasks'] }}</div><div class="muted">{{ __(':count overdue', ['count' => $stats['overdue']]) }}</div></div>
  <div class="card stat"><div class="l">{{ __('Conversations') }}</div><div class="n">{{ $stats['conversations'] }}</div><div class="muted">{{ trans_choice(':count documents', $stats['documents']) }}</div></div>
  <div class="card stat"><div class="l">{{ __('Awaiting approval') }}</div><div class="n">{{ $stats['pending'] }}</div><div class="muted"><a href="{{ route('approvals') }}">{{ __('Review') }} <span class="dir-arrow" aria-hidden="true">→</span></a></div></div>
</div>

<div class="grid g2">
  <div class="card">
    <div class="row"><b>{{ __('Questions per day') }}</b><span class="right muted">{{ __('last 30 days') }} · {{ $days->sum() }}</span></div>
    @php($max = max(1, $days->max()))
    <div class="chart" style="margin-top:14px">@foreach($days as $d => $c)<i title="{{ $d }}: {{ $c }}" style="height:{{ max(2, $c / $max * 100) }}%"></i>@endforeach</div>
  </div>
  <div class="card">
    <b>{{ __('Plan usage this month') }}</b>
    @foreach($quota as $k => $q)
      <div style="margin-top:10px"><div class="row"><span>{{ __(ucfirst(str_replace('_',' ',$k))) }}</span><span class="right muted">{{ number_format($q['used']) }} / {{ $q['max'] < 0 ? '∞' : number_format($q['max']) }}</span></div>
      <div class="bar"><i style="width:{{ $q['max'] > 0 ? min(100, $q['used'] / $q['max'] * 100) : 0 }}%"></i></div></div>
    @endforeach
  </div>
</div>

<div class="grid g3">
  <div class="card"><b>{{ __('Top actions used') }}</b>
    @forelse($tools as $t => $c)<div class="row" style="margin-top:8px"><span class="mono">{{ $t }}</span><span class="right">{{ $c }}</span></div>@empty<p class="muted">{{ __('No tool calls yet.') }}</p>@endforelse
  </div>
  <div class="card"><b>{{ __('Channels') }}</b>
    @forelse($channels as $ch => $c)<div class="row" style="margin-top:8px"><span>{{ ucfirst($ch) }}</span><span class="right">{{ $c }}</span></div>@empty<p class="muted">{{ __('No conversations yet.') }}</p>@endforelse
  </div>
  <div class="card"><b>{{ __('Answer quality') }}</b>
    <div class="row" style="margin-top:8px"><span>👍 {{ __('Helpful') }}</span><span class="right">{{ (int) ($ratings->up ?? 0) }}</span></div>
    <div class="row" style="margin-top:8px"><span>👎 {{ __('Not helpful') }}</span><span class="right">{{ (int) ($ratings->down ?? 0) }}</span></div>
  </div>
</div>

<div class="card"><div class="row"><b>{{ __('Recent conversations') }}</b><a class="right" href="{{ route('chat') }}">{{ __('Open chat') }} <span class="dir-arrow" aria-hidden="true">→</span></a></div>
<div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('Recent conversations') }}"><table style="margin-top:8px">@forelse($recent as $c)<tr><td>{{ $c->title }}</td><td><span class="pill">{{ $c->channel }}</span></td><td class="muted">{{ $c->updated_at->diffForHumans() }}</td></tr>@empty<tr><td class="muted">{{ __('Nothing yet. Ask your first question in Chat.') }}</td></tr>@endforelse</table></div></div>
@endsection
