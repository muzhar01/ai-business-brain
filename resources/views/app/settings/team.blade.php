@extends('layouts.app')
@section('title', __('Team'))
@section('content')
@include('partials.settings-tabs')
<form method="post" action="{{ route('settings.team.invite') }}" class="card row">@csrf
<input name="email" type="email" dir="ltr" aria-label="{{ __('Email') }}" placeholder="{{ 'colleague@company.com' }}" style="max-width:320px" required><select name="role" aria-label="{{ __('Role') }}" style="max-width:160px"><option value="member">{{ __('Member') }}</option><option value="admin">{{ __('Admin') }}</option></select><button class="btn">{{ __('Invite') }}</button>
<span class="muted right">{{ __('Admins manage settings, billing and approvals. Members chat and work with data.') }}</span></form>
<div class="card"><div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('Team members') }}"><table><tr><th>{{ __('Name') }}</th><th>{{ __('Email') }}</th><th>{{ __('Role') }}</th><th></th></tr>
@foreach($members as $m)<tr><td>{{ $m->name }}</td><td>{{ $m->email }}</td><td>
@if($m->pivot->role === 'owner')<span class="pill ok">{{ __('owner') }}</span>@else
<form method="post" action="{{ route('settings.team.update', $m->id) }}">@csrf @method('patch')<select name="role" aria-label="{{ __('Role') }}" onchange="this.form.submit()" style="padding:4px 8px"><option value="member" @selected($m->pivot->role==='member')>{{ __('member') }}</option><option value="admin" @selected($m->pivot->role==='admin')>{{ __('admin') }}</option></select></form>@endif</td>
<td>@if($m->pivot->role !== 'owner')<form method="post" action="{{ route('settings.team.update', $m->id) }}" onsubmit="return confirm(@js(__('Remove member?')))">@csrf @method('patch')<input type="hidden" name="remove" value="1"><button class="btn ghost sm">{{ __('Remove') }}</button></form>@endif</td></tr>@endforeach
@foreach($invitations as $i)<tr><td class="muted">{{ __('invited') }}</td><td>{{ $i->email }}</td><td><span class="pill warn">{{ __($i->role) }}</span></td><td><form method="post" action="{{ route('settings.team.cancel', $i->id) }}">@csrf @method('delete')<button class="btn ghost sm">{{ __('Cancel') }}</button></form></td></tr>@endforeach
</table></div></div>
<form method="post" action="{{ route('settings.sso') }}" class="card">@csrf
<b>{{ __('Single sign-on') }}</b>
<p class="muted">{{ __('People who sign in with :providers using an address at your domain join this workspace automatically.', ['providers' => implode(', ', \App\Security\Sso::enabled()) ?: __('Google, Microsoft or GitHub (configure keys in .env)')]) }}</p>
<div class="grid g3"><div><label for="ssod">{{ __('Company email domain') }}</label><input id="ssod" dir="ltr" name="sso_domain" value="{{ $workspace->setting('sso_domain') }}" placeholder="{{ 'acme.com' }}"></div>
<div><label for="ssor">{{ __('Role for new people') }}</label><select id="ssor" name="sso_default_role"><option value="member" @selected($workspace->setting('sso_default_role')==='member')>{{ __('member') }}</option><option value="admin" @selected($workspace->setting('sso_default_role')==='admin')>{{ __('admin') }}</option></select></div></div>
<label style="font-weight:400;margin-top:10px"><input type="checkbox" name="enforce_sso" value="1" style="width:auto" @checked($workspace->setting('enforce_sso'))> {{ __('Require SSO (turn off password sign-in for members; the owner keeps a password as a backup)') }}</label>
<button class="btn" style="margin-top:12px">{{ __('Save') }}</button></form>
@endsection
