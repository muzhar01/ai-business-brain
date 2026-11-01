@extends('layouts.app')
@section('title', __('API'))
@section('content')
@include('partials.settings-tabs')
@unless($plan->has('api'))<div class="flash err">{{ __('API access is on Pro and Business.') }} <a href="{{ route('billing') }}">{{ __('Upgrade') }}</a></div>@endunless
<form method="post" action="{{ route('settings.api') }}" class="card row">@csrf<input name="name" aria-label="{{ __('Token name') }}" placeholder="{{ __('Token name, e.g. Website backend') }}" style="max-width:360px" required><button class="btn">{{ __('Create token') }}</button></form>
<div class="card"><div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('API tokens') }}"><table><tr><th>{{ __('Name') }}</th><th>{{ __('Token') }}</th><th>{{ __('Last used') }}</th><th></th></tr>
@forelse($tokens as $t)<tr><td>{{ $t->name }}</td><td class="mono">{{ $t->prefix }}…</td><td class="muted">{{ $t->last_used_at?->diffForHumans() ?? __('never') }}</td>
<td><form method="post" action="{{ route('settings.api.revoke', $t->id) }}">@csrf @method('delete')<button class="btn ghost sm">{{ __('Revoke') }}</button></form></td></tr>
@empty<tr><td colspan="4" class="muted">{{ __('No tokens.') }}</td></tr>@endforelse</table></div></div>
<div class="card"><b>{{ __('Quick start') }}</b>
<pre dir="ltr">curl -X POST {{ url('/api/brain/chat') }} \
  -H "Authorization: Bearer brn_YOUR_TOKEN" -H "Content-Type: application/json" \
  -d '{"message":"What did we sell this week?"}'

# Push rows into a dataset (upsert on sku)
curl -X POST {{ url('/api/brain/datasets/1/rows') }} -H "Authorization: Bearer brn_…" \
  -H "Content-Type: application/json" -d '{"key":"sku","rows":[{"sku":"A1","stock":12}]}'

# Import a file with auto-mapping
curl -X POST {{ url('/api/brain/imports') }} -H "Authorization: Bearer brn_…" -F target=leads -F file=@leads.xlsx</pre>
<p class="muted">{{ __('Endpoints: chat, conversations, documents, search, actions, leads, tasks, report, imports, exports, datasets. See README.') }}</p></div>
@endsection
