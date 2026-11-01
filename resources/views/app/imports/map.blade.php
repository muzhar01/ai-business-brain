@extends('layouts.app')
@section('title', __('Map columns'))
@section('content')
<h1>{{ $import->name }}</h1>
<p class="sub">{{ __('Step 2 of 3: map source columns to :target fields. Source can be a column or a template like {First} {Last}. Transforms chain with |.', ['target' => $import->target]) }}</p>

<div class="card" style="overflow:auto"><b>{{ __('Sample from source') }}</b>
<div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('Source preview') }}"><table style="margin-top:8px"><tr>@foreach($import->columns ?? [] as $c)<th>{{ $c }}</th>@endforeach</tr>
@foreach($sample as $row)<tr>@foreach($import->columns ?? [] as $c)<td>{{ \Illuminate\Support\Str::limit(is_scalar($row[$c] ?? null) ? $row[$c] : json_encode($row[$c] ?? null), 40) }}</td>@endforeach</tr>@endforeach</table></div></div>

<form method="post" action="{{ route('imports.saveMap', $import) }}" class="card">@csrf
<datalist id="cols">@foreach($import->columns ?? [] as $c)<option value="{{ $c }}">@endforeach</datalist>
<datalist id="steps">@foreach($steps as $s)<option value="{{ $s }}">@endforeach<option value="trim|nullify"><option value="trim|lower|email"><option value="trim|number"><option value="trim|date"></datalist>
<div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('Field mapping') }}"><table><tr>@if($import->target === 'dataset')<th>{{ __('Include') }}</th>@endif<th>{{ __('Source column / template') }}</th><th><span class="dir-arrow" aria-hidden="true">→</span> {{ __('Target field') }}</th><th>{{ __('Transform') }}</th><th>{{ __('Default') }}</th></tr>
@foreach($import->mapping ?? [] as $i => $m)
<tr>
 @if($import->target === 'dataset')<td><input type="hidden" name="mapping[{{ $i }}][include]" value="0"><input type="checkbox" name="mapping[{{ $i }}][include]" value="1" checked style="width:auto" aria-label="{{ __('Include') }}"></td>@endif
 <td><input name="mapping[{{ $i }}][source]" value="{{ $m['source'] }}" list="cols" placeholder="{{ __('— not mapped —') }}" aria-label="{{ __('Source column / template') }}"></td>
 <td>@if($import->target === 'dataset')<input name="mapping[{{ $i }}][target]" value="{{ $m['target'] }}" aria-label="{{ __('Target field') }}">@else<input type="hidden" name="mapping[{{ $i }}][target]" value="{{ $m['target'] }}"><b>{{ $m['target'] }}</b>@if(!empty($fields[$m['target']]['required'])) <span class="pill warn">{{ __('required') }}</span>@endif @if(!empty($fields[$m['target']]['enum']))<div class="hint">{{ implode(' / ', $fields[$m['target']]['enum']) }}</div>@endif @endif</td>
 <td><input name="mapping[{{ $i }}][transform]" value="{{ $m['transform'] }}" list="steps" aria-label="{{ __('Transform') }}"></td>
 <td><input name="mapping[{{ $i }}][default]" value="{{ $m['default'] }}" aria-label="{{ __('Default') }}"></td>
</tr>
@endforeach
@if($import->target === 'dataset')
<tr><td><input type="hidden" name="mapping[999][include]" value="1">+</td><td><input name="mapping[999][source]" list="cols" placeholder="{{ __('add a computed field, e.g. {price} PKR') }}" aria-label="{{ __('Source column / template') }}"></td><td><input name="mapping[999][target]" placeholder="{{ __('field name') }}" aria-label="{{ __('Target field') }}"></td><td><input name="mapping[999][transform]" list="steps" aria-label="{{ __('Transform') }}"></td><td><input name="mapping[999][default]" aria-label="{{ __('Default') }}"></td></tr>
@endif
</table></div>

<div class="grid g3" style="margin-top:12px">
 <div><label for="mp-mode">{{ __('When a row already exists') }}</label><select id="mp-mode" name="mode">@foreach(['upsert' => __('Update it, else create (upsert)'), 'create' => __('Always create new'), 'skip' => __('Skip it, create only new'), 'update' => __('Update only, never create')] as $k => $l)<option value="{{ $k }}" @selected($import->option('mode','upsert') === $k)>{{ $l }}</option>@endforeach</select></div>
 <div><label for="mp-match">{{ __('Match existing rows on') }}</label>@if($import->target === 'dataset')<input id="mp-match" name="match_on" value="{{ $import->option('match_on') }}" placeholder="{{ __('unique field, e.g. sku') }}">@else<select id="mp-match" name="match_on">@foreach($matchOn as $f)<option @selected($import->option('match_on') === $f)>{{ $f }}</option>@endforeach</select>@endif</div>
 <div><label for="mp-sch">{{ __('Repeat (URL / database sources)') }}</label><select id="mp-sch" name="schedule" @disabled($import->source_type === 'upload')><option value="">{{ __('Run once') }}</option>@foreach(['hourly','daily','weekly'] as $s)<option value="{{ $s }}" @selected($import->schedule === $s)>{{ __($s) }}</option>@endforeach</select></div>
</div>
<div class="row" style="margin-top:16px">
 <button class="btn ghost" name="action" value="automap">✨ {{ __('Auto-map again (AI)') }}</button>
 <button class="btn ghost" name="action" value="preview">{{ __('Preview 20 rows') }}</button>
 <button class="btn right" name="action" value="run">{{ __('Run import') }} <span class="dir-arrow" aria-hidden="true">→</span></button>
</div>
</form>

@if($preview)
<div class="card" style="overflow:auto"><b>{{ __('Preview (nothing saved)') }}</b> <span class="muted">{{ __(':rows mapped rows · :errors rows with errors', ['rows' => count($preview['preview']), 'errors' => count($preview['errors'])]) }}</span>
@if($preview['preview'])@php($pc = array_keys($preview['preview'][0]))
<div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('Transformed preview') }}"><table style="margin-top:8px"><tr>@foreach($pc as $c)<th>{{ $c }}</th>@endforeach</tr>@foreach($preview['preview'] as $r)<tr>@foreach($pc as $c)<td>{{ is_bool($r[$c] ?? null) ? ($r[$c] ? 'true' : 'false') : ($r[$c] ?? '') }}</td>@endforeach</tr>@endforeach</table></div>@endif
@foreach($preview['errors'] as $e)<div class="hint st-failed">{{ __('Row :row: :errors', ['row' => $e['row'], 'errors' => implode('; ', $e['errors'])]) }}</div>@endforeach
</div>
@endif
@endsection
