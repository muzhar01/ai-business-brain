(function () {
  var s = document.currentScript, key = s.getAttribute('data-key'), base = s.src.replace(/\/widget\.js.*$/, '');
  if (!key) return;
  var vid = localStorage.getItem('brain_vid') || (Math.random().toString(36).slice(2) + Date.now().toString(36));
  localStorage.setItem('brain_vid', vid);
  var conv = sessionStorage.getItem('brain_conv_' + key);
  // Language (ADR 0001): data-locale on the embed tag, else the host page's html lang attribute; the server allow-lists it.
  var want = s.getAttribute('data-locale') || document.documentElement.lang || '';
  // ADR 0028: start after the host page is idle so the widget never competes with first paint; a failed config fetch leaves the page untouched.
  var boot = function () {
  fetch(base + '/widget/' + key + '/config' + (want ? '?locale=' + encodeURIComponent(want.slice(0, 35)) : '')).then(function (r) { if (!r.ok) throw new Error('config'); return r.json(); }).then(function (cfg) {
    var c = /^#[0-9a-f]{6}$/i.test(cfg.color || '') ? cfg.color : '#7c9cff', tr = cfg.strings || {};
    // ADR 0026: text on the brand colour is black or white, whichever has the higher WCAG contrast.
    var lin = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    var lum = 0.2126 * lin(parseInt(c.substr(1, 2), 16)) + 0.7152 * lin(parseInt(c.substr(3, 2), 16)) + 0.0722 * lin(parseInt(c.substr(5, 2), 16));
    var fg = (1.05 / (lum + 0.05) >= (lum + 0.05) / 0.05) ? '#fff' : '#000';
    var css = '#bw-btn{position:fixed;right:20px;bottom:20px;width:56px;height:56px;border-radius:50%;background:' + c + ';color:' + fg + ';border:0;font-size:24px;cursor:pointer;box-shadow:0 6px 24px rgba(0,0,0,.25);z-index:2147483000}' +
      '#bw{position:fixed;right:20px;bottom:88px;width:360px;max-width:calc(100vw - 40px);height:520px;max-height:calc(100vh - 120px);background:#fff;color:#111;border-radius:16px;box-shadow:0 12px 48px rgba(0,0,0,.25);display:none;flex-direction:column;overflow:hidden;font:14px/1.5 system-ui,sans-serif;z-index:2147483000}' +
      '#bw header{background:' + c + ';color:' + fg + ';padding:14px 16px;font-weight:600}#bw .l{flex:1;overflow:auto;padding:12px;background:#f6f7f9;display:flex;flex-direction:column}#bw .l>*{flex:none}' +
      '#bw .m{margin:6px 0;padding:9px 12px;border-radius:12px;max-width:85%;white-space:pre-wrap;word-wrap:break-word}#bw .u{background:' + c + ';color:' + fg + ';align-self:flex-end}#bw .a{background:#fff;border:1px solid #e5e7eb;align-self:flex-start}' +
      '#bw form{display:flex;border-top:1px solid #e5e7eb}#bw input{flex:1;border:0;padding:12px;font:inherit;}#bw button.s{border:0;background:none;color:' + c + ';font-weight:700;padding:0 14px;cursor:pointer}#bw .rt{margin-inline-start:6px;border:1px solid currentColor;background:none;color:'+c+';border-radius:8px;padding:2px 10px;cursor:pointer;font:inherit}#bw small{display:block;text-align:center;color:#999;font-size:11px;padding:4px}' +
      '#bw .t{display:inline-flex;gap:4px;align-items:center}#bw .t i{width:6px;height:6px;border-radius:50%;background:#9ca3af;animation:bwb 1.2s infinite ease-in-out}#bw .t i:nth-child(2){animation-delay:.2s}#bw .t i:nth-child(3){animation-delay:.4s}@keyframes bwb{0%,80%,100%{opacity:.3}40%{opacity:1}}@media(prefers-reduced-motion:reduce){#bw .t i{animation:none;opacity:.7}}' +
      '@media(max-width:480px){#bw{right:0;left:0;bottom:0;width:100%;max-width:100%;height:100vh;height:100dvh;max-height:none;border-radius:0}#bw[dir=rtl]{left:0;right:0}}' +
      '@media(prefers-color-scheme:dark){#bw{background:#111827;color:#f3f4f6}#bw .l{background:#0b1220}#bw .a{background:#1f2937;border-color:#374151}#bw form{border-top-color:#374151}#bw input{background:#111827;color:#f3f4f6}#bw small{color:#9ca3af}#bw .s,#bw .rt{color:#fff}}' +
      '@media(forced-colors:active){#bw,#bw-btn{border:1px solid CanvasText}#bw .m{border:1px solid CanvasText}#bw .u{border-style:dashed;background:Canvas;color:CanvasText}#bw header{border-bottom:1px solid CanvasText;background:Canvas;color:CanvasText}#bw-btn{background:ButtonFace;color:ButtonText}#bw button:focus-visible,#bw input:focus-visible,#bw-btn:focus-visible{outline:2px solid Highlight}#bw button:disabled{color:GrayText}}' +
      '#bw input:focus-visible{outline:2px solid ' + c + ';outline-offset:-2px}#bw-btn:focus-visible,#bw button:focus-visible{outline:2px solid ' + c + ';outline-offset:2px}' +
      '#bw-btn[dir=rtl]{right:auto;left:20px}#bw[dir=rtl]{right:auto;left:20px}#bw[dir=rtl] header,#bw[dir=rtl] .m{line-height:1.7}';
    // ADR 0027: Shadow DOM isolates the widget from host-page CSS (both directions); falls back to the light DOM where unsupported.
    var host = document.createElement('div'), root = document.body;
    if (host.attachShadow) { host.id = 'bw-host'; host.style.cssText = 'all:initial'; root = host.attachShadow({ mode: 'open' }); css = ':host{all:initial}*{box-sizing:border-box}' + css; } else { host = null; }
    var st = document.createElement('style'); st.textContent = css; (host ? root : document.head).appendChild(st);
    var btn = document.createElement('button'); btn.id = 'bw-btn'; btn.textContent = '💬'; btn.setAttribute('aria-label', tr.open || 'Chat'); btn.setAttribute('aria-expanded', 'false');
    var w = document.createElement('div'); w.id = 'bw';
    w.innerHTML = '<header></header><div class="l" role="log" aria-live="polite"></div><form><input maxlength="2000"><button class="s"></button></form>' + (cfg.branding ? '<small class="p"></small>' : '');
    w.querySelector('header').textContent = cfg.name;
    // Translated chrome goes in via textContent / attributes only, never as HTML.
    w.querySelector('input').setAttribute('placeholder', tr.placeholder || 'Type your message…');
    w.querySelector('input').setAttribute('aria-label', tr.input || 'Your message');
    w.querySelector('button.s').textContent = tr.send || 'Send';
    if (cfg.branding) w.querySelector('small.p').textContent = tr.powered || 'Powered by {{ config('saas.name') }}';
    if (cfg.lang) w.lang = cfg.lang;
    root.appendChild(w); root.appendChild(btn); if (host) document.body.appendChild(host);
    var dir = (s.getAttribute('data-locale') && cfg.dir) ? cfg.dir : ((getComputedStyle(document.documentElement).direction === 'rtl') ? 'rtl' : 'ltr');
    w.dir = dir; btn.dir = dir; w.querySelector('header').dir = 'auto';
    var log = w.querySelector('.l'), input = w.querySelector('input'); input.dir = 'auto';
    function add(t, cls) { var d = document.createElement('div'); d.className = 'm ' + cls; d.dir = 'auto'; d.textContent = t; log.appendChild(d); log.scrollTop = 1e9; return d; }
    add(cfg.greeting, 'a');
    if (cfg.privacy) { var p = document.createElement('small'); p.textContent = cfg.privacy; log.appendChild(p); }
    btn.onclick = function () { var open = w.style.display !== 'flex'; w.style.display = open ? 'flex' : 'none'; btn.setAttribute('aria-expanded', open ? 'true' : 'false'); btn.setAttribute('aria-label', open ? (tr.close || 'Close chat') : (tr.open || 'Chat')); if (open) input.focus(); };
    w.addEventListener('keydown', function (e) { if (e.key === 'Escape' && w.style.display === 'flex') { btn.onclick(); btn.focus(); } });
    w.querySelector('form').onsubmit = function (e) {
      e.preventDefault(); var msg = input.value.trim(); if (!msg) return; input.value = ''; add(msg, 'u'); var t = add('', 'a'); var sb = w.querySelector('button.s');
      // Resilience (ADR 0016): 20 s timeout, one automatic retry on network/5xx, Retry button, deferred send while offline.
      function typing() { t.setAttribute('aria-busy', 'true'); t.innerHTML = '<span class="t"><i></i><i></i><i></i></span><span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)"></span>'; t.lastChild.textContent = tr.typing || 'Assistant is typing…'; sb.disabled = true; }
      function done(x) { t.removeAttribute('aria-busy'); t.textContent = x; sb.disabled = false; }
      function fail() { done(tr.offline || 'Connection problem. Please try again.'); var rb = document.createElement('button'); rb.type = 'button'; rb.className = 'rt'; rb.textContent = tr.retry || 'Retry'; rb.onclick = function () { send(0); }; t.appendChild(document.createTextNode(' ')); t.appendChild(rb); }
      function send(n) {
        typing();
        if (navigator.onLine === false) { t.lastChild && (t.lastChild.textContent = tr.offline_wait || 'You are offline. Your message will be sent when you reconnect.'); var go = function () { window.removeEventListener('online', go); send(n); }; window.addEventListener('online', go); return; }
        var ac = window.AbortController ? new AbortController() : null, to = setTimeout(function () { if (ac) ac.abort(); }, 20000);
        fetch(base + '/widget/' + key + '/chat', { method: 'POST', signal: ac ? ac.signal : undefined, headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ message: msg, visitor: vid, conversation_id: conv, locale: cfg.locale }) })
          .then(function (r) { clearTimeout(to); if (r.status >= 500) throw new Error('5xx'); return r.json(); })
          .then(function (r) { done(r.answer || r.message || tr.error || 'Sorry, something went wrong.'); if (r.conversation_id) { conv = r.conversation_id; sessionStorage.setItem('brain_conv_' + key, conv); } })
          .catch(function () { clearTimeout(to); if (n < 1) { setTimeout(function () { send(n + 1); }, 1500); } else { fail(); } });
      }
      send(0);
    };
  }).catch(function () {});
  };
  var go = function () { if (window.requestIdleCallback) requestIdleCallback(boot, { timeout: 3000 }); else setTimeout(boot, 200); };
  if (document.readyState === 'complete') go(); else window.addEventListener('load', go);
})();
