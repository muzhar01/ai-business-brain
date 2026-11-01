{{-- ⌘K / Ctrl+K command palette (ADR 0010): WAI-ARIA combobox + listbox over the sidebar links. No server calls. --}}
<dialog id="cmdk" class="cmdk" aria-label="{{ __('Command palette') }}">
  <input id="cmdk-input" class="cmdk-input" type="text" role="combobox" aria-expanded="true" aria-controls="cmdk-list" aria-autocomplete="list" aria-activedescendant="" autocomplete="off" spellcheck="false" placeholder="{{ __('Jump to a page…') }}" aria-label="{{ __('Jump to a page…') }}">
  <ul id="cmdk-list" class="cmdk-list" role="listbox" aria-label="{{ __('Pages') }}"></ul>
  <p id="cmdk-empty" class="cmdk-empty" role="status" hidden>{{ __('No matching pages') }}</p>
  <p class="cmdk-hint">{{ __('↑ ↓ to move, Enter to open, Esc to close') }}</p>
</dialog>
<script nonce="{{ $cspNonce ?? '' }}">
(() => {
  const dlg = document.getElementById('cmdk'); if (!dlg || !dlg.showModal) return;
  const input = document.getElementById('cmdk-input'), list = document.getElementById('cmdk-list'), empty = document.getElementById('cmdk-empty');
  const items = [...document.querySelectorAll('#sidenav nav a[href]')].map(a => ({label: a.textContent.trim(), href: a.getAttribute('href')}));
  let shown = [], idx = 0;
  // Fuzzy: subsequence match; prefix and contiguous matches rank higher.
  const score = (q, s) => {
    q = q.toLowerCase(); s = s.toLowerCase(); if (!q) return 1;
    const at = s.indexOf(q); if (at >= 0) return 100 - at;
    let i = 0, last = -2, sc = 0;
    for (const ch of s) { if (ch === q[i]) { sc += last === i ? 2 : 1; last = i; i++; if (i === q.length) return sc; } else last++; }
    return 0;
  };
  const TYPES = {lead: @js(__('Lead')), task: @js(__('Task'))};
  const searchUrl = @js(route('palette.search', [], false));
  let remote = [], ctl = null, timer = null;
  const fetchRemote = () => {
    const q = input.value.trim(); clearTimeout(timer); if (ctl) ctl.abort();
    if (q.length < 2) { remote = []; render(); return; }
    timer = setTimeout(() => {
      ctl = new AbortController();
      fetch(searchUrl + '?q=' + encodeURIComponent(q), {signal: ctl.signal, headers: {Accept: 'application/json'}, credentials: 'same-origin'})
        .then(r => r.ok ? r.json() : {results: []})
        .then(d => { remote = (d.results || []).filter(r => typeof r.href === 'string' && r.href.charAt(0) === '/' && r.href.charAt(1) !== '/').map(r => ({label: (TYPES[r.type] || '') + ': ' + r.label, href: r.href})); render(); })
        .catch(() => {});
    }, 200);
  };
  const render = () => {
    const q = input.value.trim();
    shown = items.map(it => [score(q, it.label), it]).filter(x => x[0] > 0).sort((a, b) => b[0] - a[0]).map(x => x[1]).concat(q.length > 1 ? remote : []);
    idx = 0; list.textContent = '';
    shown.forEach((it, n) => { const li = document.createElement('li'); li.id = 'cmdk-o' + n; li.setAttribute('role', 'option'); li.textContent = it.label; li.dataset.href = it.href; li.addEventListener('click', () => go(n)); list.appendChild(li); });
    empty.hidden = shown.length > 0; mark();
  };
  const mark = () => { list.querySelectorAll('[role=option]').forEach((li, n) => li.setAttribute('aria-selected', n === idx ? 'true' : 'false')); input.setAttribute('aria-activedescendant', shown.length ? 'cmdk-o' + idx : ''); list.children[idx]?.scrollIntoView({block: 'nearest'}); };
  const go = n => { if (shown[n]) location.href = shown[n].href; };
  const open = () => { if (dlg.open) return; input.value = ''; remote = []; render(); dlg.showModal(); input.focus(); };
  document.addEventListener('keydown', e => { if ((e.metaKey || e.ctrlKey) && !e.altKey && e.key.toLowerCase() === 'k') { e.preventDefault(); dlg.open ? dlg.close() : open(); } });
  document.querySelectorAll('[data-cmdk-open]').forEach(b => b.addEventListener('click', open));
  input.addEventListener('input', () => { render(); fetchRemote(); });
  input.addEventListener('keydown', e => {
    if (e.isComposing) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); if (shown.length) { idx = (idx + 1) % shown.length; mark(); } }
    else if (e.key === 'ArrowUp') { e.preventDefault(); if (shown.length) { idx = (idx - 1 + shown.length) % shown.length; mark(); } }
    else if (e.key === 'Enter') { e.preventDefault(); go(idx); }
  });
  dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });
})();
</script>
