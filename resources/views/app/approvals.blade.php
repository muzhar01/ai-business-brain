@extends('layouts.app')
@section('title', __('Approvals'))
@section('content')
<h1>{{ __('Approvals') }}</h1>
<p class="sub">{{ __('Actions that touch the outside world (emails, automations) wait here for a human.') }}</p>
@forelse($pending as $a)
<div class="card"><div class="row"><b class="mono">{{ $a->tool }}</b><span class="pill warn">#{{ $a->id }}</span><span class="muted">{{ $a->conversation?->title }}</span><span class="right muted">{{ $a->created_at->diffForHumans() }}</span></div>
<pre>{{ json_encode($a->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
@if(in_array($role, ['owner','admin']))<div class="row"><button class="btn" onclick="decide({{ $a->id }},'approve')">{{ __('Approve & run') }}</button><button class="btn ghost" onclick="decide({{ $a->id }},'reject')">{{ __('Reject') }}</button></div>@else<p class="muted">{{ __('An admin needs to approve this.') }}</p>@endif
</div>
@empty<div class="card muted">{{ __('Nothing waiting.') }} 🎉</div>@endforelse
<h2>{{ __('History') }}</h2>
<div class="card"><div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('Approvals') }}"><table><tr><th>{{ __('Action') }}</th><th>{{ __('Status') }}</th><th>{{ __('By') }}</th><th>{{ __('Result') }}</th><th>{{ __('When') }}</th></tr>
@forelse($history as $a)<tr><td class="mono">{{ $a->tool }}</td><td><span class="pill st-{{ $a->status }}">{{ __($a->status) }}</span></td><td>{{ $a->decided_by }}</td><td class="muted">{{ \Illuminate\Support\Str::limit($a->result, 80) }}</td><td class="muted">{{ $a->decided_at?->diffForHumans() }}</td></tr>@empty<tr><td colspan="5" class="muted">{{ __('No decisions yet.') }}</td></tr>@endforelse</table></div></div>
@endsection
@push('scripts')<script nonce="{{ $cspNonce ?? '' }}">async function decide(id, what) { try { await api(`actions/${id}/${what}`, {method:'POST', json:{}}); location.reload(); } catch (e) { alert(e.message); } }</script>@endpush
