@extends('layouts.app')
@section('title', __('Chat'))
@section('content')
<h1 class="sr-only">{{ __('Chat') }}</h1>
<div class="chatwrap">
  <div class="card convs">
    <div class="row"><button class="btn sm" onclick="newChat()">{{ __('+ New chat') }}</button></div>
    <label for="agent">{{ __('Agent') }}</label><select id="agent"></select>
    <label for="provider">{{ __('Model') }}</label><select id="provider"></select>
    <input id="search" dir="auto" placeholder="{{ __('Search chats') }}" aria-label="{{ __('Search chats') }}" style="margin-top:12px" oninput="loadConvs()">
    <div id="convs" style="margin-top:8px"></div>
  </div>
  <div class="card" style="display:flex;flex-direction:column;padding:0">
    <div class="row" style="padding:10px 14px;border-bottom:1px solid var(--line)"><b id="ctitle" dir="auto">{{ __('New conversation') }}</b><button type="button" id="findBtn" class="right btn ghost sm" aria-expanded="false" aria-controls="findbar" onclick="toggleFind()">{{ __('Find') }}</button><a id="exportLink" class="btn ghost sm" style="display:none">{{ __('Export') }}</a></div>
    <div id="findbar" class="row" role="search" hidden style="padding:8px 14px;border-bottom:1px solid var(--line)">
      <input id="findq" type="search" dir="auto" aria-label="{{ __('Find in conversation') }}" placeholder="{{ __('Find in conversation') }}" oninput="runFind()" onkeydown="findKey(event)">
      <span id="findcount" class="muted" role="status" aria-live="polite"></span>
      <button type="button" class="btn ghost sm" onclick="stepFind(-1)" aria-label="{{ __('Previous match') }}">↑</button>
      <button type="button" class="btn ghost sm" onclick="stepFind(1)" aria-label="{{ __('Next match') }}">↓</button>
      <label class="muted" for="jumpd">{{ __('Jump to date') }}</label>
      <input id="jumpd" type="date" onchange="jumpDate()">
      <span id="jumpmsg" class="muted" role="status" aria-live="polite"></span>
    </div>
    <div id="log" class="log" style="flex:1"><p class="muted" style="text-align:center;margin-top:18vh">{{ __('Ask about your business: policies, numbers, customers.') }}<br>{{ __('Try “Add Sara from Nova Traders as a lead worth 5000 and remind me to call her Friday”.') }}</p></div>
    <form onsubmit="send(event)" class="row" style="padding:12px;border-top:1px solid var(--line);flex-wrap:nowrap">
      <textarea id="q" dir="auto" style="min-height:46px;height:46px;resize:none" placeholder="{{ __('Message…') }}" aria-label="{{ __('Message') }}" enterkeyhint="send" onkeydown="if(event.key==='Enter'&&!event.shiftKey&&!event.isComposing&&event.keyCode!==229){send(event)}"></textarea>
      <button class="btn" id="sendBtn">{{ __('Send') }}</button>
    </form>
  </div>
</div>
@endsection
@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
const T = @js(['thinking' => __('Thinking…'), 'closed' => __('Connection closed early.'), 'newConv' => __('New conversation'), 'newConvMsg' => __('New conversation.'), 'conv' => __('Conversation'), 'untitled' => __('Untitled'), 'none' => __('No chats yet.'), 'agentDefault' => __('Agent default'), 'tokens' => __(':tokens tokens'), 'noProvider' => __('No AI provider is set up yet. Add a key in :where.'), 'settings' => __('Settings'), 'providers' => __('AI providers'), 'matches' => __(':n of :total'), 'noMatch' => __('No matches'), 'jumped' => __('Jumped to :date'), 'noneAfter' => __('No messages on or after that date')]);
let conversation = null;
const $ = id => document.getElementById(id);
function bubble(role, html, ts) {
  const log = $('log'); if (log.querySelector('p.muted')) log.innerHTML = '';
  const d = document.createElement('div'); d.className = 'msg ' + role; d.dir = 'auto'; if (ts) d.dataset.ts = ts; d.innerHTML = html; log.appendChild(d); log.scrollTop = 1e9; return d;
}
function rateHtml(id, r) { return id ? `<span class="rate ${r==1?'on':''}" onclick="rate(${id},1,this)">👍</span><span class="rate ${r==-1?'on':''}" onclick="rate(${id},-1,this)">👎</span>` : ''; }
async function rate(id, v, el) { await api('messages/'+id+'/rate', {method:'POST', json:{rating:v}}); el.parentNode.querySelectorAll('.rate').forEach(x=>x.classList.remove('on')); el.classList.add('on'); }
async function send(e) {
  e.preventDefault(); const message = $('q').value.trim(); if (!message) return;
  $('q').value = ''; bubble('user', esc(message)); const wait = bubble('assistant', T.thinking); $('sendBtn').disabled = true;
  try {
    const r = await streamChat({message, conversation_id: conversation, provider: $('provider').value || null, agent_id: conversation ? null : ($('agent').value || null)}, wait);
    conversation = r.conversation_id; setExport();
    const tools = r.tool_runs.map(t => `<span class="chip">⚙ ${esc(t.tool)}</span>`).join('');
    const src = r.sources.map(s => `<span class="chip">📄 ${esc(s.title)}</span>`).join('');
    wait.innerHTML = esc(r.answer) + `<div class="meta">${tools}${src}<br>${esc(r.provider)} · ${esc(r.model)} · ${esc(T.tokens.replace(':tokens', r.usage.input_tokens + r.usage.output_tokens))} ${rateHtml(r.message_id)}</div>`;
    loadConvs();
  } catch (err) { wait.innerHTML = '⚠ ' + esc(err.message); }
  $('sendBtn').disabled = false;
}
// Streams tokens over SSE (fetch + ReadableStream so we can POST with CSRF); falls back to JSON.
async function streamChat(body, el) {
  const res = await fetch('/app/api/chat/stream', {method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json','Accept':'text/event-stream','X-CSRF-TOKEN':csrf}, body: JSON.stringify(body)});
  if (!res.ok || !res.body) { const d = await res.json().catch(() => ({})); throw new Error(d.message || res.statusText); }
  const reader = res.body.getReader(), dec = new TextDecoder(); let buf = '', text = '', tools = '';
  el.textContent = '';
  for (;;) {
    const {value, done} = await reader.read(); if (done) break;
    buf += dec.decode(value, {stream:true}); let i;
    while ((i = buf.indexOf('\n\n')) >= 0) {
      const raw = buf.slice(0, i); buf = buf.slice(i + 2);
      const ev = (raw.match(/^event: (.*)$/m) || [])[1], data = JSON.parse((raw.match(/^data: (.*)$/m) || [,'{}'])[1]);
      if (ev === 'delta') { text += data.text; el.innerHTML = esc(text) + tools + '<span class="cursor">▍</span>'; $('log').scrollTop = 1e9; }
      else if (ev === 'tool') { tools += `<div class="meta"><span class="chip">⚙ ${esc(data.name)}…</span></div>`; text = ''; el.innerHTML = tools; }
      else if (ev === 'error') throw new Error(data.message);
      else if (ev === 'done') return data;
    }
  }
  throw new Error(T.closed);
}
let hits = [], cur = -1;
function clearFind() { $('log').querySelectorAll('mark.hit').forEach(m => { m.replaceWith(document.createTextNode(m.textContent)); }); $('log').normalize(); hits = []; cur = -1; }
function runFind() {
  clearFind(); const q = $('findq').value.trim().toLowerCase(), out = $('findcount'); out.textContent = '';
  if (q.length < 2) return;
  const w = document.createTreeWalker($('log'), NodeFilter.SHOW_TEXT), nodes = []; let n;
  while ((n = w.nextNode())) if (n.nodeValue.toLowerCase().includes(q)) nodes.push(n);
  nodes.forEach(node => {
    const t = node.nodeValue, l = t.toLowerCase(), frag = document.createDocumentFragment(); let i = 0, j;
    while ((j = l.indexOf(q, i)) >= 0) { frag.append(t.slice(i, j)); const m = document.createElement('mark'); m.className = 'hit'; m.textContent = t.slice(j, j + q.length); frag.append(m); hits.push(m); i = j + q.length; }
    frag.append(t.slice(i)); node.replaceWith(frag);
  });
  if (!hits.length) { out.textContent = T.noMatch; return; }
  stepFind(1);
}
function stepFind(d) {
  if (!hits.length) return; if (cur >= 0) hits[cur].classList.remove('cur');
  cur = (cur + d + hits.length) % hits.length; hits[cur].classList.add('cur'); hits[cur].scrollIntoView({block:'center'});
  $('findcount').textContent = T.matches.replace(':n', cur + 1).replace(':total', hits.length);
}
function findKey(e) {
  if (e.isComposing || e.keyCode === 229) return;
  if (e.key === 'Enter') { e.preventDefault(); stepFind(e.shiftKey ? -1 : 1); }
  else if (e.key === 'Escape') { toggleFind(false); }
}
function toggleFind(on) {
  const bar = $('findbar'); on = on === undefined ? bar.hidden : on; bar.hidden = !on; $('findBtn').setAttribute('aria-expanded', on);
  if (on) $('findq').focus(); else { $('findq').value = ''; clearFind(); $('findcount').textContent = ''; $('findBtn').focus(); }
}
function localDay(iso) { const d = new Date(iso); return isNaN(d) ? null : d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
function jumpDate() {
  const v = $('jumpd').value, out = $('jumpmsg'); out.textContent = ''; if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return;
  const el = [...$('log').querySelectorAll('.msg[data-ts]')].find(m => { const d = localDay(m.dataset.ts); return d && d >= v; });
  if (!el) { out.textContent = T.noneAfter; return; }
  el.scrollIntoView({block: 'start'}); el.setAttribute('tabindex', '-1'); el.focus({preventScroll: true});
  out.textContent = T.jumped.replace(':date', localDay(el.dataset.ts));
}
function setExport() { const a = $('exportLink'); a.style.display = conversation ? '' : 'none'; a.href = '/app/api/conversations/' + conversation + '/export'; }
function newChat() { conversation = null; setExport(); $('ctitle').textContent = T.newConv; $('log').innerHTML = '<p class="muted" style="text-align:center;margin-top:18vh">' + esc(T.newConvMsg) + '</p>'; loadConvs(); }
async function openConv(id) {
  conversation = id; setExport(); $('log').innerHTML = '';
  const r = await api('conversations/' + id); $('ctitle').textContent = r.conversation.title || T.conv;
  r.messages.forEach(m => bubble(m.role, esc(m.content) + (m.role === 'assistant' && m.model ? `<div class="meta">${esc(m.provider)} · ${esc(m.model)} ${rateHtml(m.id, m.rating)}</div>` : ''), m.created_at));
  loadConvs();
}
async function loadConvs() {
  const r = await api('conversations?q=' + encodeURIComponent($('search').value));
  $('convs').innerHTML = r.data.map(c => `<div dir="auto" class="conv ${c.uuid === conversation ? 'on' : ''}" onclick="openConv('${c.uuid}')" title="${esc(c.channel)}">${c.channel !== 'app' ? '🌐 ' : ''}${esc(c.title || T.untitled)}</div>`).join('') || '<p class="muted">' + esc(T.none) + '</p>';
}
(async () => {
  try {
    const [p, a] = await Promise.all([api('providers'), api('agents')]);
    $('provider').innerHTML = '<option value="">' + esc(T.agentDefault) + '</option>' + p.providers.map(x => `<option value="${x.name}">${x.name} · ${esc(x.model)}</option>`).join('');
    if (!p.providers.length) bubble('assistant', '⚠ ' + esc(T.noProvider).replace(':where', esc(T.settings) + ' <span class="dir-arrow" aria-hidden="true">→</span> ' + esc(T.providers)));
    $('agent').innerHTML = a.map(x => `<option value="${x.id}" ${x.is_default ? 'selected' : ''}>${esc(x.name)}</option>`).join('');
    loadConvs();
  } catch (e) { bubble('assistant', '⚠ ' + esc(e.message)); }
})();
</script>
@endpush
