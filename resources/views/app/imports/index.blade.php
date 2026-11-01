@extends('layouts.app')
@section('title', __('Import / ETL'))
@section('content')
<div class="row"><div><h1>{{ __('Import / ETL') }}</h1><p class="sub">{{ __('Extract from files, URLs/APIs or databases, map and transform columns, then load into leads, tasks, datasets or knowledge. Schedule syncs to keep data fresh.') }}</p></div><a class="btn right" href="{{ route('imports.create') }}">{{ __('New import') }}</a></div>
<div class="card"><div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('Imports') }}"><table><tr><th>{{ __('Name') }}</th><th>{{ __('Source') }}</th><th>{{ __('Target') }}</th><th>{{ __('Status') }}</th><th>{{ __('Result') }}</th><th>{{ __('Schedule') }}</th><th>{{ __('Last run') }}</th></tr>
@forelse($imports as $i)
<tr><td><a href="{{ $i->status === 'draft' ? route('imports.map', $i) : route('imports.show', $i) }}">{{ $i->name }}</a></td><td><span class="pill">{{ __($i->source_type) }}</span></td><td>{{ $i->target }}</td>
<td><span class="pill st-{{ $i->status }}">{{ __($i->status) }}</span></td>
<td class="muted">{{ __('+:created new · :updated updated · :failed failed', ['created' => $i->created_rows, 'updated' => $i->updated_rows, 'failed' => $i->failed_rows]) }}</td><td>{{ $i->schedule ? __($i->schedule) : '—' }}</td><td class="muted">{{ $i->last_run_at?->diffForHumans() ?? '—' }}</td></tr>
@empty<tr><td colspan="7" class="muted">{{ __('No imports yet.') }}</td></tr>@endforelse</table></div>{{ $imports->links() }}</div>
<div class="card"><b>{{ __('Templates') }}</b> <span class="muted">{{ __('Start from a ready CSV:') }}</span>
@foreach(['leads','tasks','knowledge'] as $t) <a class="btn ghost sm" href="{{ route('imports.template', $t) }}">{{ $t.'.csv' }}</a> @endforeach</div>
@endsection
