@extends('layouts.app')
@section('title', __('Webhooks'))
@section('content')
@include('partials.settings-tabs')
@unless($plan->has('webhooks'))<div class="flash err">{{ __('Event webhooks are on Pro and above.') }} <a href="{{ route('billing') }}">{{ __('Upgrade') }}</a></div>@endunless
<form method="post" action="{{ route('settings.webhooks') }}" class="card">@csrf
<b>{{ __('Send events to your systems') }}</b>
<label for="wu">{{ __('Endpoint URL') }}</label><input id="wu" dir="ltr" name="url" placeholder="{{ 'https://example.com/hooks/brain' }}" required>
<div class="hint" id="wev">{{ __('Events') }}</div>
<div class="grid g4" role="group" aria-labelledby="wev"><label style="font-weight:400;margin:0"><input type="checkbox" name="events[]" value="*" style="width:auto"> {{ __('All events') }}</label>
@foreach($events as $e)<label style="font-weight:400;margin:0"><input type="checkbox" name="events[]" value="{{ $e }}" style="width:auto"> <span class="mono">{{ $e }}</span></label>@endforeach</div>
<button class="btn" style="margin-top:12px">{{ __('Add endpoint') }}</button></form>
<div class="card"><div class="row"><b>{{ __('Endpoints') }}</b><form class="right" method="post" action="{{ route('settings.webhooks.ping') }}">@csrf<button class="btn ghost sm">{{ __('Send test event') }}</button></form></div>
<div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('Webhook endpoints') }}"><table style="margin-top:8px"><tr><th>{{ __('URL') }}</th><th>{{ __('Events') }}</th><th>{{ __('Status') }}</th><th></th></tr>
@forelse($endpoints as $e)<tr><td class="mono">{{ $e->url }}</td><td class="muted">{{ implode(', ', $e->events) }}</td><td><span class="pill {{ $e->active ? 'ok' : 'bad' }}">{{ $e->active ? __('active') : __('disabled') }}</span>@if($e->failures)<div class="hint">{{ __(':n recent failures', ['n' => $e->failures]) }}</div>@endif</td>
<td class="row"><form method="post" action="{{ route('settings.webhooks.toggle', $e) }}">@csrf<button class="btn ghost sm">{{ $e->active ? __('Disable') : __('Enable') }}</button></form>
<form method="post" action="{{ route('settings.webhooks.destroy', $e) }}">@csrf @method('delete')<button class="btn ghost sm">{{ __('Delete') }}</button></form></td></tr>
@empty<tr><td colspan="4" class="muted">{{ __('No endpoints.') }}</td></tr>@endforelse</table></div>
<details style="margin-top:12px"><summary class="muted">{{ __('Verify signatures') }}</summary><pre dir="ltr">// header X-Brain-Signature: t=1700000000,v1=hex
$expected = hash_hmac('sha256', $t.'.'.$rawBody, $secret);
hash_equals($expected, $v1) && abs(time() - $t) &lt; 300;</pre></details></div>
<div class="card"><b>{{ __('Recent deliveries') }}</b>
<div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('Webhook deliveries') }}"><table style="margin-top:8px"><tr><th>{{ __('Event') }}</th><th>{{ __('Endpoint') }}</th><th>{{ __('Status') }}</th><th>{{ __('Attempts') }}</th><th>{{ __('Code') }}</th><th>{{ __('When') }}</th><th></th></tr>
@forelse($deliveries as $d)<tr><td class="mono">{{ $d->event }}</td><td class="muted">{{ \Illuminate\Support\Str::limit($d->endpoint?->url, 40) }}</td><td><span class="pill st-{{ $d->status === 'delivered' ? 'done' : $d->status }}">{{ __($d->status) }}</span></td><td>{{ $d->attempts }}</td><td>{{ $d->response_code }}</td><td class="muted">{{ $d->created_at->diffForHumans() }}</td>
<td>@if($d->status === 'failed')<form method="post" action="{{ route('settings.webhooks.retry', $d) }}">@csrf<button class="btn ghost sm">{{ __('Retry') }}</button></form>@endif</td></tr>
@empty<tr><td colspan="7" class="muted">{{ __('No deliveries yet.') }}</td></tr>@endforelse</table></div></div>
@endsection
