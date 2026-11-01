@extends('layouts.app')
@section('title', __('Datasets'))
@section('content')
<div class="row"><div><h1>{{ __('Datasets') }}</h1><p class="sub">{!! str_replace(':tool', '<span class="mono">query_dataset</span>', e(__('Any table your business runs on: products, stock, price lists, orders, branches. The brain queries them with :tool.'))) !!}</p></div><a class="btn right" href="{{ route('imports.create', ['target' => 'dataset']) }}">+ {{ __('Import a dataset') }}</a></div>
<div class="grid g3">
@forelse($datasets as $d)
<a class="card" href="{{ route('datasets.show', $d) }}" style="color:inherit;text-decoration:none"><b>{{ $d->name }}</b> <span class="mono">{{ $d->slug }}</span><div class="stat"><div class="n">{{ number_format($d->row_count) }}</div><div class="l">{{ __('rows') }}</div></div><div class="hint">{{ implode(', ', array_slice($d->fieldNames(), 0, 8)) }}</div></a>
@empty<div class="card muted">{{ __('No datasets yet. Import a spreadsheet, a JSON API or a database query.') }}</div>@endforelse
</div>
@endsection
