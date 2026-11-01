@extends('layouts.app')
@section('title', $import->name)
@section('content')
<div class="row"><div><h1>{{ $import->name }}</h1><p class="sub">{{ __($import->source_type) }} <span class="dir-arrow" aria-hidden="true">→</span> {{ $import->target }} · <span class="pill st-{{ $import->status }}">{{ __($import->status) }}</span> @if($import->schedule)· {{ __('repeats :schedule', ['schedule' => __($import->schedule)]) }} @endif</p></div>
<div class="right row"><a class="btn ghost" href="{{ route('imports.map', $import) }}">{{ __('Edit mapping') }}</a>
<form method="post" action="{{ route('imports.rerun', $import) }}">@csrf<button class="btn ghost">{{ __('Run again') }}</button></form>
<form method="post" action="{{ route('imports.destroy', $import) }}" onsubmit="return confirm(@js(__('Delete this import job?')))">@csrf @method('delete')<button class="btn ghost">{{ __('Delete') }}</button></form></div></div>
@if(in_array($import->status, ['queued','running']))<meta http-equiv="refresh" content="4">@endif
<div class="grid g4">
 <div class="card stat"><div class="l">{{ __('Rows read') }}</div><div class="n">{{ number_format($import->total_rows) }}</div></div>
 <div class="card stat"><div class="l">{{ __('Created') }}</div><div class="n st-done">{{ number_format($import->created_rows) }}</div></div>
 <div class="card stat"><div class="l">{{ __('Updated / skipped') }}</div><div class="n">{{ number_format($import->updated_rows) }} / {{ number_format($import->skipped_rows) }}</div></div>
 <div class="card stat"><div class="l">{{ __('failed') }}</div><div class="n st-failed">{{ number_format($import->failed_rows) }}</div></div>
</div>
@if($import->errors)
<div class="card"><b>{{ __('Errors') }}</b> <span class="muted">({{ __('first :count', ['count' => count($import->errors)]) }})</span>
<div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('Import errors') }}"><table style="margin-top:8px"><tr><th>{{ __('Row') }}</th><th>{{ __('Problem') }}</th></tr>@foreach($import->errors as $e)<tr><td>{{ $e['row'] ?? '—' }}</td><td>{{ implode('; ', $e['errors']) }}</td></tr>@endforeach</table></div></div>
@endif
@if($import->target === 'dataset' && $import->dataset_id)<a class="btn" href="{{ route('datasets.show', $import->dataset_id) }}">{{ __('Open dataset') }} <span class="dir-arrow" aria-hidden="true">→</span></a>@endif
@endsection
