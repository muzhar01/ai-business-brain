@extends('layouts.app')
@section('title', __('Data & audit'))
@section('content')
@include('partials.settings-tabs')
<div class="grid g2">
<div class="card"><b>📦 {{ __('Full backup') }}</b><p class="muted">{{ __('One portable .zip with every agent, document, chunk, conversation, lead, task and dataset. Restore it on any server or database (MySQL ⇄ Postgres ⇄ SQLite). API keys are never included.') }}</p>
<a class="btn" href="{{ route('settings.backup') }}">{{ __('Download backup') }}</a></div>
<form method="post" action="{{ route('settings.restore') }}" enctype="multipart/form-data" class="card">@csrf<b>♻️ {{ __('Restore / migrate in') }}</b><p class="muted">{{ __('Upload a backup to create a new workspace from it. Use this to move from self-hosted to cloud, between servers, or to clone a template workspace.') }}</p>
<div class="row"><input type="file" name="backup" aria-label="{{ __('Backup file') }}" accept=".zip" required style="max-width:300px"><button class="btn ghost">{{ __('Restore') }}</button></div></form>
</div>
<div class="card"><b>🧾 {{ __('Audit log') }}</b>
@if($logs)
<div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('Audit log') }}"><table style="margin-top:8px"><tr><th>{{ __('When') }}</th><th>{{ __('Who') }}</th><th>{{ __('Action') }}</th><th>{{ __('Subject') }}</th><th>{{ __('Details') }}</th><th>{{ __('IP') }}</th></tr>
@forelse($logs as $l)<tr><td class="muted">{{ \App\Support\Format::dateTime($l->created_at) }}</td><td>{{ $l->user?->name ?? __('system / API') }}</td><td class="mono">{{ $l->action }}</td><td>{{ $l->subject_type }} {{ $l->subject_id }}</td><td class="muted">{{ $l->meta ? \Illuminate\Support\Str::limit(json_encode($l->meta), 80) : '' }}</td><td class="muted">{{ $l->ip }}</td></tr>@empty<tr><td colspan="6" class="muted">{{ __('Nothing yet.') }}</td></tr>@endforelse</table></div>{{ $logs->links() }}
@else<p class="muted">{{ __('Audit log is on the Business plan.') }} <a href="{{ route('billing') }}">{{ __('Upgrade') }}</a></p>@endif
</div>
@endsection
