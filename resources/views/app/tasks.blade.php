@extends('layouts.app')
@section('title', __('Tasks'))
@section('content')
<div class="row"><div><h1>{{ __('Tasks') }}</h1><p class="sub">{{ __('Follow-ups created by your team and the brain.') }}</p></div><a class="btn ghost right" href="{{ route('imports.create', ['target' => 'tasks']) }}">{{ __('Import') }}</a></div>
<div class="row" style="margin-bottom:14px">@foreach(['open','done','all'] as $s)<a class="pill {{ request('status','open') === $s ? 'ok' : '' }}" href="?status={{ $s }}">{{ __($s) }}</a>@endforeach</div>
<form method="post" action="{{ route('tasks.store') }}" class="card grid g4">@csrf
<input name="title" placeholder="{{ __('New task') }} *" aria-label="{{ __('New task') }}" required><input name="due_date" type="date" aria-label="{{ __('Due') }}"><select name="priority" aria-label="{{ __('Priority') }}">@foreach(['normal','low','high','urgent'] as $p)<option value="{{ $p }}">{{ __($p) }}</option>@endforeach</select><div class="row"><input name="assignee" placeholder="{{ __('Assignee') }}" aria-label="{{ __('Assignee') }}"><button class="btn">{{ __('Add') }}</button></div></form>
<div class="card"><div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('Tasks') }}"><table><tr><th></th><th>{{ __('Task') }}</th><th>{{ __('Due') }}</th><th>{{ __('Priority') }}</th><th>{{ __('Assignee') }}</th><th>{{ __('Lead') }}</th><th></th></tr>
@forelse($tasks as $t)
<tr><td><form method="post" action="{{ route('tasks.toggle', $t) }}">@csrf<button class="btn ghost sm" aria-label="{{ $t->status === 'done' ? __('Reopen') : __('Mark done') }}">{{ $t->status === 'done' ? '✔' : '○' }}</button></form></td>
<td style="{{ $t->status === 'done' ? 'text-decoration:line-through;color:var(--muted)' : '' }}">{{ $t->title }}<div class="hint">{{ $t->description }}</div></td>
<td class="{{ $t->status === 'open' && $t->due_date && $t->due_date->isPast() && ! $t->due_date->isToday() ? 'st-failed' : '' }}">{{ \App\Support\Format::date($t->due_date) }}</td><td><span class="pill">{{ __($t->priority) }}</span></td><td>{{ $t->assignee }}</td><td>{{ $t->lead?->name }}</td>
<td><form method="post" action="{{ route('tasks.destroy', $t) }}">@csrf @method('delete')<button class="btn ghost sm" aria-label="{{ __('Delete') }}">✕</button></form></td></tr>
@empty<tr><td colspan="7" class="muted">{{ __('No tasks.') }}</td></tr>@endforelse</table></div>{{ $tasks->links() }}</div>
@endsection
