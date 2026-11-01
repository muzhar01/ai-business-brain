@extends('layouts.app')
@section('title', __('Knowledge'))
@section('content')
<h1>{{ __('Knowledge base') }}</h1>
<p class="sub">{{ __('Everything the brain should know: policies, price lists, SOPs, contracts, FAQs. PDF, Word, PowerPoint, Excel, CSV, HTML, Markdown, web pages.') }}</p>
<div class="card">
  <form id="up" onsubmit="upload(event)">
    <div class="grid g3">
      <div><label for="file">{{ __('File') }}</label><input type="file" name="file" id="file"></div>
      <div><label for="url">{{ __('…or web page URL') }}</label><input name="url" id="url" placeholder="{{ 'https://' }}"></div>
      <div><label for="collection">{{ __('Collection') }}</label><input name="collection" id="collection" placeholder="{{ __('default') }}" list="cols"><datalist id="cols">@foreach($collections as $c)<option value="{{ $c }}">@endforeach</datalist></div>
    </div>
    <label for="text">{{ __('…or paste text') }}</label><textarea id="text" placeholder="{{ __('Title on the first line, content below') }}"></textarea>
    <div class="row" style="margin-top:12px"><button class="btn">{{ __('Add to knowledge') }}</button><span class="muted">{{ str(e(__('Bulk FAQ / articles? Use :link.', ['link' => '%%'])))->replace('%%', '<a href="'.e(route('imports.create', ['target' => 'knowledge'])).'">'.e(__('Import')).'</a>')->toHtmlString() }}}</span></div>
  </form>
</div>
<div class="card">
<div class="row"><b>{{ __('Search test') }}</b><input id="sq" aria-label="{{ __('Search test') }}" placeholder="{{ __('What would a customer ask?') }}" style="max-width:420px" onkeydown="if(event.key==='Enter'){search()}"><button class="btn ghost sm" onclick="search()">{{ __('Search') }}</button></div>
<div id="sr"></div>
</div>
<div class="card">
<div class="tablewrap" role="region" tabindex="0" aria-label="{{ __('Documents') }}"><table><tr><th>{{ __('Title') }}</th><th>{{ __('Collection') }}</th><th>{{ __('Status') }}</th><th>{{ __('Chunks') }}</th><th>{{ __('Added') }}</th><th></th></tr>
@forelse($documents as $d)
<tr><td>{{ $d->title }} @if($d->error)<div class="hint">{{ \Illuminate\Support\Str::limit($d->error, 140) }}</div>@endif</td><td><span class="pill">{{ $d->collection }}</span></td><td><span class="pill st-{{ $d->status }}">{{ __($d->status) }}</span></td><td>{{ $d->chunk_count }}</td><td class="muted">{{ $d->created_at->diffForHumans() }}</td>
<td class="row"><button class="btn ghost sm" aria-label="{{ __('Reprocess') }}" onclick="act('documents/{{ $d->id }}/reprocess','POST')">↻</button><button class="btn ghost sm" aria-label="{{ __('Delete') }}" onclick="if(confirm(@js(__('Delete?'))))act('documents/{{ $d->id }}','DELETE')">✕</button></td></tr>
@empty<tr><td colspan="6" class="muted">{{ __('No documents yet.') }}</td></tr>@endforelse
</table></div>{{ $documents->links() }}
</div>
@endsection
@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
async function upload(e) {
  e.preventDefault(); const fd = new FormData(); const f = document.getElementById('file').files[0];
  const url = document.getElementById('url').value.trim(), text = document.getElementById('text').value.trim();
  if (f) fd.append('file', f); else if (url) fd.append('url', url); else if (text) { const [t, ...rest] = text.split('\n'); fd.append('title', t.slice(0, 200)); fd.append('text', rest.join('\n') || t); } else return;
  const c = document.getElementById('collection').value.trim(); if (c) fd.append('collection', c);
  try { await api('documents', {method:'POST', body: fd}); location.reload(); } catch (err) { alert(err.message); }
}
async function act(path, method) { try { await api(path, {method}); location.reload(); } catch (e) { alert(e.message); } }
async function search() {
  const r = await api('search?q=' + encodeURIComponent(document.getElementById('sq').value));
  document.getElementById('sr').innerHTML = r.map(x => `<div style="margin-top:10px"><span class="pill">${x.score}</span> <b>${esc(x.title)}</b><pre>${esc(x.content.slice(0, 500))}</pre></div>`).join('') || '<p class="muted">'+esc(@js(__('No matches.')))+'</p>';
}
</script>
@endpush
