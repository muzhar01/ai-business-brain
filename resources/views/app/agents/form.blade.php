@extends('layouts.app')
@section('title', $agent->exists ? __('Edit agent') : __('New agent'))
@section('content')
<h1>{{ $agent->exists ? __('Edit :name', ['name' => $agent->name]) : __('New agent') }}</h1>
<form method="post" action="{{ $agent->exists ? route('agents.update', $agent) : route('agents.store') }}" class="card">@csrf @if($agent->exists) @method('put') @endif
<div class="grid g2">
  <div><label>{{ __('Name') }}</label><input name="name" value="{{ old('name', $agent->name) }}" required placeholder="{{ __('Sales Assistant') }}"></div>
  <div><label>{{ __('Description') }}</label><input name="description" value="{{ old('description', $agent->description) }}"></div>
</div>
<label>{{ __('Role & instructions') }}</label><textarea name="instructions" rows="7" placeholder="{{ __('You qualify inbound leads. Always ask for budget and timeline, then save them with create_lead…') }}">{{ old('instructions', $agent->instructions) }}</textarea>
<div class="grid g4">
  <div><label>{{ __('Provider') }}</label><select name="provider"><option value="">{{ __('Workspace default') }}</option>@foreach($providers as $p)<option value="{{ $p['name'] }}" @selected($agent->provider === $p['name'])>{{ $p['name'] }}</option>@endforeach</select></div>
  <div><label>{{ __('Model override') }}</label><input name="model" value="{{ old('model', $agent->model) }}" placeholder="{{ __('e.g. gpt-5') }}"></div>
  <div><label>{{ __('Temperature') }}</label><input name="temperature" type="number" step="0.1" min="0" max="2" value="{{ old('temperature', $agent->temperature) }}" placeholder="{{ __('model default') }}"></div>
  <div><label>{{ __('Knowledge collection') }}</label><input name="collection" value="{{ old('collection', $agent->collection) }}" placeholder="{{ __('all') }}"></div>
</div>
<label>{{ __('Allowed actions') }}</label>
<div class="row"><label style="font-weight:400;margin:0"><input type="radio" name="tools_mode" value="all" style="width:auto" @checked(! is_array($agent->tools))> {{ __('All') }}</label>
<label style="font-weight:400;margin:0"><input type="radio" name="tools_mode" value="custom" style="width:auto" @checked(is_array($agent->tools))> {{ __('Only these:') }}</label></div>
<div class="grid g3" style="margin-top:8px">@foreach($tools as $name => $tool)<label style="font-weight:400;margin:0"><input type="checkbox" name="tools[]" value="{{ $name }}" style="width:auto" @checked(is_array($agent->tools) && in_array($name, $agent->tools))> <span class="mono">{{ $name }}</span>@if($tool->requiresApproval()) <span class="pill warn">{{ __('approval') }}</span>@endif</label>@endforeach</div>
<h2>{{ __('Public channels') }}</h2>
<label style="font-weight:400"><input type="checkbox" name="widget_enabled" value="1" style="width:auto" @checked($agent->widget_enabled)> {{ __('Answer website widget and Telegram visitors with this agent (only safe actions: knowledge, datasets, lead capture)') }}</label>
<div class="grid g2"><div><label>{{ __('Greeting') }}</label><input name="widget_greeting" value="{{ old('widget_greeting', $agent->widget_greeting) }}" placeholder="{{ __('Hi! How can I help?') }}"></div><div><label>{{ __('Widget colour') }}</label><input name="widget_color" type="color" value="{{ old('widget_color', $agent->widget_color ?: '#7c9cff') }}" style="height:42px"></div></div>
<label style="font-weight:400;margin-top:14px"><input type="checkbox" name="is_default" value="1" style="width:auto" @checked($agent->is_default)> {{ __('Default agent for new chats') }}</label>
<div class="row" style="margin-top:16px"><button class="btn">{{ __('Save agent') }}</button><a class="btn ghost" href="{{ route('agents.index') }}">{{ __('Cancel') }}</a></div>
</form>
@endsection
