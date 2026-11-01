@extends('layouts.app')
@section('title', __('New import'))
@section('content')
<h1>{{ __('New import') }}</h1>
<p class="sub">{{ __('Step 1 of 3: where is the data and where should it go?') }}</p>
<form method="post" action="{{ route('imports.store') }}" enctype="multipart/form-data" class="card">@csrf
<div class="grid g2">
  <div><label for="im-name">{{ __('Name') }}</label><input id="im-name" name="name" value="{{ old('name', __('Import :date', ['date' => \App\Support\Format::shortDate(now())])) }}" required></div>
  <div><label for="target">{{ __('Load into') }}</label><select name="target" id="target" onchange="tg()">@foreach($targets as $k => $t)<option value="{{ $k }}" @selected(old('target', $target) === $k)>{{ $t['label'] }}</option>@endforeach</select></div>
</div>
<div id="ds" class="grid g2">
  <div><label for="im-ds">{{ __('Existing dataset') }}</label><select id="im-ds" name="dataset_id"><option value="">{{ __('— create new —') }}</option>@foreach($datasets as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
  <div><label for="im-dsn">{{ __('New dataset name') }}</label><input id="im-dsn" name="dataset_name" value="{{ old('dataset_name') }}" placeholder="{{ __('Products') }}"></div>
</div>

<h2>{{ __('Source') }}</h2>
<div class="row" role="radiogroup" aria-label="{{ __('Source') }}">
 @foreach(['upload' => '📄 '.__('File'), 'url' => '🌐 '.__('URL / API').($plan->has('url_imports') ? '' : ' ('.__('Pro').')'), 'database' => '🗄 '.__('Database').($plan->has('database_imports') ? '' : ' ('.__('Business').')')] as $k => $l)
  <label style="font-weight:400;margin:0"><input type="radio" name="source_type" value="{{ $k }}" style="width:auto" onchange="st()" @checked(old('source_type','upload') === $k)> {{ $l }}</label>
 @endforeach
</div>
<div id="s-upload"><label for="im-file">{{ __('File (CSV, TSV, Excel .xlsx, JSON, NDJSON)') }}</label><input id="im-file" type="file" name="file"></div>
<div id="s-url" class="grid g2">
  <div><label for="im-url">{{ __('URL') }}</label><input id="im-url" name="url" value="{{ old('url') }}" placeholder="{{ 'https://api.example.com/products' }}"></div>
  <div><label for="im-tok">{{ __('Bearer token (optional)') }}</label><input id="im-tok" name="url_token" autocomplete="off" type="password"></div>
  <div><label for="im-fmt">{{ __('Format') }}</label><select id="im-fmt" name="url_format"><option value="">{{ __('Auto-detect') }}</option><option>{{ 'json' }}</option><option>{{ 'csv' }}</option><option>{{ 'tsv' }}</option><option>{{ 'ndjson' }}</option><option>{{ 'xlsx' }}</option></select></div>
  <div><label for="im-jp">{{ __('JSON path to records') }}</label><input id="im-jp" name="json_path" placeholder="{{ __('data.items (auto if blank)') }}"></div>
</div>
<div id="s-database" class="grid g3">
  <div><label for="im-drv">{{ __('Driver') }}</label><select id="im-drv" name="db_driver"><option value="mysql">{{ 'MySQL' }}</option><option value="mariadb">{{ 'MariaDB' }}</option><option value="pgsql">{{ 'PostgreSQL' }}</option><option value="sqlsrv">{{ 'SQL Server' }}</option></select></div>
  <div><label for="im-host">{{ __('Host') }}</label><input id="im-host" name="db_host"></div><div><label for="im-port">{{ __('Port') }}</label><input id="im-port" name="db_port" type="number"></div>
  <div><label for="im-db">{{ __('Database') }}</label><input id="im-db" name="db_database"></div><div><label for="im-user">{{ __('Username') }}</label><input id="im-user" name="db_username" autocomplete="off"></div><div><label for="im-pw">{{ __('Password') }}</label><input id="im-pw" name="db_password" autocomplete="off" type="password"></div>
  <div style="grid-column:1/-1"><label for="im-q">{{ __('Read-only SELECT query') }}</label><textarea id="im-q" name="db_query" dir="ltr" placeholder="{{ 'SELECT sku, name, price, stock FROM products WHERE active = 1' }}"></textarea><div class="hint">{{ __('Use a read-only database user. Credentials are stored encrypted.') }}</div></div>
</div>
<details style="margin-top:14px"><summary class="muted">{{ __('Advanced parsing options') }}</summary>
<div class="grid g3"><div><label for="im-hh">{{ __('First row is header') }}</label><select id="im-hh" name="has_header"><option value="1">{{ __('Yes') }}</option><option value="0">{{ __('No') }}</option></select></div>
<div><label for="im-del">{{ __('Delimiter') }}</label><input id="im-del" name="delimiter" placeholder="{{ __('auto (, ; tab |)') }}"></div><div><label for="im-sh">{{ __('Excel sheet #') }}</label><input id="im-sh" name="sheet" type="number" min="1" placeholder="1"></div></div></details>
<div class="row" style="margin-top:18px"><button class="btn">{{ __('Next: map columns') }} <span class="dir-arrow" aria-hidden="true">→</span></button></div>
</form>
@endsection
@push('scripts')<script nonce="{{ $cspNonce ?? '' }}">
function st(){const v=document.querySelector('[name=source_type]:checked').value;['upload','url','database'].forEach(k=>document.getElementById('s-'+k).style.display=k===v?'':'none')}
function tg(){document.getElementById('ds').style.display=document.getElementById('target').value==='dataset'?'':'none'}
st();tg();
</script>@endpush
