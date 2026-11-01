@extends('layouts.app')
@section('title', __('Export'))
@section('content')
<h1>{{ __('Export') }}</h1>
<p class="sub">{{ __('Take your data anywhere: CSV, Excel or JSON, with your own column mapping. Full workspace backup is in Settings, then Data.') }}</p>
<form method="post" action="{{ route('exports.store') }}" class="card">@csrf
<div class="grid g4">
 <div><label for="target">{{ __('What') }}</label><select name="target" id="target" onchange="document.getElementById('ds').style.display=this.value==='dataset'?'':'none'">@foreach($targets as $k => $t)<option value="{{ $k }}">{{ $t['label'] }}</option>@endforeach</select></div>
 <div id="ds" style="display:none"><label for="ex-ds">{{ __('Dataset') }}</label><select name="dataset_id" id="ex-ds">@foreach($datasets as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
 <div><label for="ex-fmt">{{ __('Format') }}</label><select name="format" id="ex-fmt"><option value="csv">{{ 'CSV' }}</option><option value="xlsx">{{ 'Excel (.xlsx)' }}</option><option value="json">{{ 'JSON' }}</option></select></div>
 <div><label for="ex-st">{{ __('Status filter') }}</label><input id="ex-st" name="status" placeholder="{{ __('e.g. won, open') }}"></div>
 <div><label for="ex-from">{{ __('From') }}</label><input id="ex-from" type="date" name="from"></div><div><label for="ex-to">{{ __('To') }}</label><input id="ex-to" type="date" name="to"></div>
</div>
<label for="ex-cols">{{ __('Column mapping (optional)') }}</label><textarea id="ex-cols" name="columns" placeholder="{{ "name as Customer\nemail as E-mail\nvalue as Deal value\ndata.sku as SKU" }}"></textarea>
<div class="hint">{{ __('One per line: field as Header. Leave empty for all fields. Datasets use data.field. Available:') }} @foreach($targets as $k => $t) <b>{{ $k }}</b>: {{ implode(', ', $t['fields']) ?: 'data.*' }}; @endforeach</div>
<button class="btn" style="margin-top:12px">{{ __('Export') }}</button>
</form>
@if($exports->contains(fn($e) => in_array($e->status, ['queued','running'])))<meta http-equiv="refresh" content="4">@endif
<div class="card"><div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('Exports') }}"><table><tr><th>{{ __('What') }}</th><th>{{ __('Format') }}</th><th>{{ __('Status') }}</th><th>{{ __('Rows') }}</th><th>{{ __('When') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></tr>
@forelse($exports as $e)<tr><td>{{ $e->target }}</td><td>{{ strtoupper($e->format) }}</td><td><span class="pill st-{{ $e->status }}">{{ __($e->status) }}</span>@if($e->error)<div class="hint">{{ $e->error }}</div>@endif</td><td>{{ $e->rows }}</td><td class="muted">{{ $e->created_at->diffForHumans() }}</td>
<td>@if($e->status === 'done')<a class="btn sm" href="{{ route('exports.download', $e) }}">{{ __('Download') }}</a>@endif</td></tr>
@empty<tr><td colspan="6" class="muted">{{ __('No exports yet.') }}</td></tr>@endforelse</table></div>{{ $exports->links() }}</div>
@endsection
