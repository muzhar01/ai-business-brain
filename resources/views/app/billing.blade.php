@extends('layouts.app')
@section('title', __('Billing'))
@section('content')
<h1>{{ __('Billing') }}</h1>
<p class="sub">{{ __('Current plan:') }} <b>{{ __($plan->name()) }}</b>
 @if($workspace->plan_override) ({{ __('set by support') }}) @elseif($subscription?->onGracePeriod()) · {{ __('cancels :date', ['date' => $subscription->ends_at->toFormattedDateString()]) }} @elseif($workspace->onGenericTrial()) · {{ __('Pro trial until :date', ['date' => $workspace->trial_ends_at->toFormattedDateString()]) }} @endif
 @if($subscription && $stripeReady) · <a href="{{ route('billing.portal') }}">{{ __('Manage payment & invoices') }}</a>@endif</p>
@if(request('checkout') === 'success')<div class="flash" role="status">{{ __('Payment received. Your plan updates in a few seconds.') }}</div>@endif
@unless($stripeReady)<div class="flash err" role="alert">{{ __('Stripe is not configured yet (STRIPE_KEY / STRIPE_SECRET / STRIPE_PRICE_* in .env).') }}</div>@endunless
<div class="grid g3">
@foreach($plans as $p)
<div class="card plan {{ $p->key === $plan->key ? 'cur' : '' }}">
 <b>{{ __($p->name()) }}</b><div class="price" dir="ltr">${{ $p->config['price'] }}<span class="muted" style="font-size:14px">{{ __('/mo') }}</span></div>
 <div class="muted" style="margin:8px 0">
  {{ __(':n AI messages/mo', ['n' => number_format($p->limit('messages'))]) }}<br>{{ __(':docs documents · :members members', ['docs' => number_format($p->limit('documents')), 'members' => $p->limit('members')]) }}<br>{{ __(':agents agents · :datasets datasets', ['agents' => $p->limit('agents'), 'datasets' => $p->limit('datasets')]) }}<br>{{ __(':n import rows/mo', ['n' => number_format($p->limit('import_rows'))]) }}<br>
  {{ $p->allowsPlatformKeys() ? __('Built-in AI models included') : __('Bring your own AI key') }}<br>
  @foreach($p->config['features'] as $f) ✓ {{ __('feature.'.$f) }}<br>@endforeach
 </div>
 @if($p->key === $plan->key)<span class="pill ok">{{ __('Current') }}</span>
 @elseif($p->config['stripe_price'])<a class="btn" href="{{ route('billing.checkout', $p->key) }}">{{ $p->config['price'] > $plan->config['price'] ? __('Upgrade') : __('Switch') }}</a>
 @endif
</div>
@endforeach
</div>
<div class="card"><b>{{ __('Usage this month') }}</b>
@foreach($quota as $k => $q)<div class="row" style="margin-top:8px"><span style="width:140px">{{ __('quota.'.$k) }}</span><div class="bar" role="progressbar" aria-label="{{ __('quota.'.$k) }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) ($q['max'] > 0 ? min(100, $q['used'] / $q['max'] * 100) : 0) }}" style="flex:1;margin:0"><i style="width:{{ $q['max'] > 0 ? min(100, $q['used'] / $q['max'] * 100) : 0 }}%"></i></div><span class="muted" dir="ltr" style="width:120px;text-align:end">{{ number_format($q['used']) }} / {{ $q['max'] < 0 ? '∞' : number_format($q['max']) }}</span></div>@endforeach
</div>
@endsection
