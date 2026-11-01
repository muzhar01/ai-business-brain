@extends('layouts.app')
@section('title', __('AI providers'))
@section('content')
@include('partials.settings-tabs')
<p class="sub">{{ __('Bring your own keys (stored encrypted).') }} @if($plan->allowsPlatformKeys()) {{ __('Your plan can also use the platform\'s built-in models.') }} @else {{ __('Your plan needs your own key for at least one provider.') }} @endif</p>
<div class="card"><b>{{ __('Ready to use') }}</b>
<div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('AI providers') }}"><table style="margin-top:8px"><tr><th>{{ __('Provider') }}</th><th>{{ __('Model') }}</th><th>{{ __('Embeddings') }}</th><th>{{ __('Key') }}</th><th></th></tr>
@forelse($available as $p)<tr><td>{{ $p['name'] }} @if($p['default'])<span class="pill ok">{{ __('default') }}</span>@endif</td><td class="mono">{{ $p['model'] }}</td><td>{{ $p['embeddings'] ? __('yes') : '—' }}</td><td><span class="pill">{{ $p['source'] === 'workspace' ? __('your key') : __('platform') }}</span></td>
<td><form method="post" action="{{ route('settings.providers.test') }}">@csrf<input type="hidden" name="provider" value="{{ $p['name'] }}"><button class="btn ghost sm">{{ __('Test') }}</button></form></td></tr>
@empty<tr><td colspan="5" class="muted">{{ __('None yet. Add a key below.') }}</td></tr>@endforelse</table></div></div>

<div class="grid g2">
@foreach($catalog as $name => $c)
<form method="post" action="{{ route('settings.providers') }}" class="card">@csrf<input type="hidden" name="provider" value="{{ $name }}">
<div class="row"><b>{{ ucfirst($name) }}</b><span class="pill">{{ $c['driver'] }}</span>@isset($keys[$name])<span class="pill ok">{{ __('your key: :key', ['key' => $keys[$name]['api_key']]) }}</span>@endisset</div>
<div class="grid g2"><div><label for="k-{{ $name }}">{{ __('API key') }}</label><input id="k-{{ $name }}" dir="ltr" name="api_key" type="password" placeholder="{{ isset($keys[$name]) ? __('leave blank to keep') : __('paste key') }}"></div>
<div><label for="m-{{ $name }}">{{ __('Model') }}</label><input id="m-{{ $name }}" dir="ltr" name="model" value="{{ $keys[$name]['model'] ?? '' }}" placeholder="{{ $c['model'] ?? '' }}"></div>
@if(in_array($name, ['ollama','lmstudio','azure']))<div style="grid-column:1/-1"><label for="b-{{ $name }}">{{ __('Base URL') }}</label><input id="b-{{ $name }}" dir="ltr" name="base_url" value="{{ $keys[$name]['base_url'] ?? '' }}" placeholder="{{ $c['base_url'] ?? '' }}"></div>@endif</div>
<label style="font-weight:400"><input type="checkbox" name="baa" value="1" style="width:auto" @checked(!empty($keys[$name]['baa']))> {{ __('We have a signed BAA with this vendor (HIPAA mode)') }}</label>
<div class="row" style="margin-top:10px"><button class="btn sm">{{ __('Save') }}</button>@isset($keys[$name])<button class="btn ghost sm" name="remove" value="1">{{ __('Remove') }}</button>@endisset</div>
</form>
@endforeach
<form method="post" action="{{ route('settings.providers') }}" class="card">@csrf
<b>{{ __('Custom OpenAI-compatible endpoint') }}</b><p class="hint">{{ __('Fireworks, vLLM, LiteLLM, a private gateway…') }}</p>
<div class="grid g2"><div><label for="cn">{{ __('Name') }}</label><input id="cn" dir="ltr" name="provider" placeholder="{{ 'fireworks' }}" required></div><div><label for="cd">{{ __('Driver') }}</label><select id="cd" name="driver"><option value="openai">{{ __('OpenAI-compatible') }}</option><option value="anthropic">{{ 'Anthropic' }}</option><option value="gemini">{{ 'Gemini' }}</option></select></div>
<div><label for="cb">{{ __('Base URL') }}</label><input id="cb" dir="ltr" name="base_url" placeholder="{{ 'https://api.fireworks.ai/inference/v1' }}" required></div><div><label for="ck">{{ __('API key') }}</label><input id="ck" dir="ltr" name="api_key" type="password" required></div>
<div><label for="cm">{{ __('Model') }}</label><input id="cm" dir="ltr" name="model" required></div><div><label for="ce">{{ __('Embedding model') }}</label><input id="ce" dir="ltr" name="embedding_model"></div></div>
<label style="font-weight:400"><input type="checkbox" name="baa" value="1" style="width:auto"> {{ __('Covered by a BAA (HIPAA mode)') }}</label>
<button class="btn sm" style="margin-top:10px">{{ __('Add') }}</button></form>
</div>
@endsection
