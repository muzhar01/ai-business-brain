@extends('layouts.app')
@section('title', __('Settings'))
@section('content')
@include('partials.settings-tabs')
<form method="post" action="{{ route('settings.general') }}" class="card">@csrf
<div class="grid g2">
 <div><label for="s-name">{{ __('Business name') }}</label><input id="s-name" name="name" value="{{ $workspace->name }}" required></div>
 <div><label for="s-tone">{{ __('Tone of voice') }}</label><input id="s-tone" name="tone" value="{{ $workspace->setting('tone') }}"></div>
</div>
<label for="s-desc">{{ __('What the business does') }}</label><textarea id="s-desc" name="business_description" placeholder="{{ __('We sell solar systems to homes and SMEs in KP and Punjab…') }}">{{ $workspace->setting('business_description') }}</textarea>
<label for="s-inst">{{ __('Company-wide instructions') }}</label><textarea id="s-inst" name="instructions" rows="6" placeholder="{{ __('Prices are in PKR. Never promise delivery under 3 days. Escalate refunds over 50,000 to the manager.') }}">{{ $workspace->setting('instructions') }}</textarea>
<div class="grid g4">
 <div><label for="s-prov">{{ __('Default AI provider') }}</label><select id="s-prov" name="default_provider"><option value="">{{ __('Platform default') }}</option>@foreach($providers as $p)<option value="{{ $p['name'] }}" @selected($workspace->setting('default_provider') === $p['name'])>{{ $p['name'] }}</option>@endforeach</select></div>
 <div><label for="s-emb">{{ __('Embeddings provider') }}</label><select id="s-emb" name="embedding_provider"><option value="">{{ __('Platform default') }}</option>@foreach($providers as $p)@if($p['embeddings'])<option value="{{ $p['name'] }}" @selected($workspace->setting('embedding_provider') === $p['name'])>{{ $p['name'] }}</option>@endif @endforeach<option value="null" @selected($workspace->setting('embedding_provider') === 'null')>{{ __('None (keyword search)') }}</option></select></div>
 <div><label for="s-tz">{{ __('Timezone') }}</label><select id="s-tz" name="timezone">@foreach($timezones as $tz)<option @selected($workspace->setting('timezone') === $tz)>{{ $tz }}</option>@endforeach</select></div>
 <div><label for="s-color">{{ __('Brand colour') }}</label><input id="s-color" type="color" name="brand_color" value="{{ $workspace->setting('brand_color', '#7c9cff') }}" style="height:42px"></div>
</div>
<div class="grid g4">
 <div><label for="ws-language">{{ __('Default language') }}</label><select id="ws-language" name="language"><option value="">{{ __('Browser language') }}</option>@foreach(\App\Support\Locale::supported() as $code => $name)<option value="{{ $code }}" lang="{{ \App\Support\Direction::lang($code) }}" @selected($workspace->setting('language') === $code)>{{ $name }}</option>@endforeach</select><div class="hint">{{ __('For members who haven’t picked a language, and for the website widget.') }}</div></div>
</div>
<label for="s-hook">{{ __('Automation webhook (n8n, Zapier, Make…)') }}</label><input id="s-hook" name="webhook_url" dir="ltr" value="{{ $workspace->setting('webhook_url') }}" placeholder="{{ 'https://hooks.zapier.com/…' }}">
<label style="font-weight:400;margin-top:14px"><input type="checkbox" name="require_approval" value="1" style="width:auto" @checked($workspace->setting('require_approval'))> {{ __('Require human approval for emails and automations') }}</label>
<button class="btn" style="margin-top:14px">{{ __('Save') }}</button>
</form>
@if($role === 'owner')
<form method="post" action="{{ route('settings.destroy') }}" class="card" onsubmit="return confirm(@js(__('Delete this workspace and ALL its data forever?')))">@csrf @method('delete')
<b class="st-failed">{{ __('Danger zone') }}</b><p class="muted">{{ __('Deletes the workspace, its data and cancels its subscription. Download a backup first.') }}</p>
<div class="row"><input name="confirm" aria-label="{{ __('Confirm workspace name') }}" placeholder="{{ __('Type :name to confirm', ['name' => $workspace->name]) }}" style="max-width:340px"><button class="btn danger">{{ __('Delete workspace') }}</button></div></form>
@endif
@endsection
