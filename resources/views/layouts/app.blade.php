<!DOCTYPE html>
<html lang="{{ \App\Support\Direction::lang() }}" dir="{{ \App\Support\Direction::of() }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<script nonce="{{ $cspNonce ?? '' }}">document.documentElement.classList.add('js');try{var t=localStorage.getItem('brain-theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t)}catch(e){}</script>
<title>@yield('title', __('Dashboard')) · {{ $plan->has('white_label') && $workspace->setting('business_name') ? $workspace->setting('business_name') : config('saas.name') }}</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
<style>:root{--acc:{{ $workspace->setting('brand_color', '#7c9cff') }}}</style>
</head>
<body>
<a class="skip" href="#main">{{ __('Skip to content') }}</a>
<header class="topbar">
  <button type="button" class="navtoggle" id="navtoggle" aria-controls="sidenav" aria-expanded="false" aria-label="{{ __('Open menu') }}"><span aria-hidden="true">☰</span></button>
  <span class="topbar-title">@yield('title', __('Dashboard'))</span>
  <button type="button" class="navtoggle js-only" data-cmdk-open aria-haspopup="dialog" aria-label="{{ __('Command palette') }}"><span aria-hidden="true">⌕</span></button>
</header>
<div class="scrim" id="navscrim" hidden></div>
<div class="shell">
<aside class="side" id="sidenav" aria-label="{{ __('Main navigation') }}">
  <div class="brand">🧠 {{ $plan->has('white_label') ? $workspace->name : config('saas.name') }}</div>
  <div class="wsbox">
    <form method="post" action="{{ route('workspaces.switch') }}">@csrf
      <select name="workspace_id" onchange="this.form.submit()" aria-label="{{ __('Workspace') }}">
        @foreach(auth()->user()->workspaces as $ws)
          <option value="{{ $ws->id }}" @selected($ws->id === $workspace->id)>{{ $ws->name }}</option>
        @endforeach
      </select>
    </form>
    <div class="row" style="margin-top:6px"><span class="pill">{{ $plan->name() }}</span><a class="right" href="{{ route('workspaces.create') }}" style="font-size:12px">+ {{ __('New') }}</a></div>
  </div>
  @php($is = fn($p) => request()->routeIs($p) ? 'on' : '')
  <nav aria-label="{{ __('Sections') }}">
    <a class="{{ $is('dashboard') }}" href="{{ route('dashboard') }}">📊 {{ __('Dashboard') }}</a>
    <a class="{{ $is('chat') }}" href="{{ route('chat') }}">💬 {{ __('Chat') }}</a>
    <a class="{{ $is('agents.*') }}" href="{{ route('agents.index') }}">🤖 {{ __('Agents') }}</a>
    <a class="{{ $is('knowledge') }}" href="{{ route('knowledge') }}">📚 {{ __('Knowledge') }}</a>
    <a class="{{ $is('approvals') }}" href="{{ route('approvals') }}">✅ {{ __('Approvals') }}</a>
    <div class="sec">{{ __('Business') }}</div>
    <a class="{{ $is('leads.*') }}" href="{{ route('leads.index') }}">👥 {{ __('Leads') }}</a>
    <a class="{{ $is('tasks.*') }}" href="{{ route('tasks.index') }}">📌 {{ __('Tasks') }}</a>
    <a class="{{ $is('datasets.*') }}" href="{{ route('datasets.index') }}">🗂 {{ __('Datasets') }}</a>
    <div class="sec">{{ __('Data') }}</div>
    <a class="{{ $is('imports.*') }}" href="{{ route('imports.index') }}">⬇️ {{ __('Import / ETL') }}</a>
    <a class="{{ $is('exports.*') }}" href="{{ route('exports.index') }}">⬆️ {{ __('Export') }}</a>
    @if(in_array($role, ['owner','admin']))
    <div class="sec">{{ __('Workspace') }}</div>
    <a class="{{ $is('settings.*') }}" href="{{ route('settings.general') }}">⚙️ {{ __('Settings') }}</a>
    <a class="{{ $is('billing*') }}" href="{{ route('billing') }}">💳 {{ __('Billing') }}</a>
    @endif
    @if(auth()->user()->is_admin)
    <a class="{{ $is('admin.*') }}" href="{{ route('admin.index') }}">🛡 {{ __('Super admin') }}</a>
    @endif
    <a class="{{ $is('profile*') }}" href="{{ route('profile') }}">👤 {{ \Illuminate\Support\Str::limit(auth()->user()->name, 18) }}</a>
    <form method="post" action="{{ route('logout') }}" style="margin-top:14px">@csrf<button class="btn ghost sm" style="width:100%">{{ __('Sign out') }}</button></form>
  </nav>
  <div class="themesw js-only" id="themesw" role="group" aria-label="{{ __('Colour theme') }}"><button type="button" data-theme="dark" aria-pressed="false">{{ __('Dark') }}</button><button type="button" data-theme="light" aria-pressed="false">{{ __('Light') }}</button><button type="button" data-theme="system" aria-pressed="true">{{ __('Auto') }}</button></div>
  @include('partials.locale-switcher', ['compact' => true])
</aside>
<main class="main" id="main" tabindex="-1">
  @if(session('status'))<div class="flash" role="status">{{ session('status') }}</div>@endif
  @if(session('secret'))<div class="flash">{{ __('Copy it now, it won’t be shown again:') }} <code>{{ session('secret') }}</code></div>@endif
  @if($errors->any())<div class="flash err" role="alert">{{ $errors->first() }}</div>@endif
  @yield('content')
</main>
</div>
<script nonce="{{ $cspNonce ?? '' }}">
const i18n = {open: @json(__('Open menu')), close: @json(__('Close menu'))};
window.csrf = document.querySelector('meta[name=csrf-token]').content;
async function api(path, opts = {}) {
  const headers = {'Accept':'application/json','X-CSRF-TOKEN':csrf};
  if (opts.json !== undefined) { headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(opts.json); }
  const res = await fetch('/app/api/' + path, {...opts, headers, credentials:'same-origin'});
  if (res.status === 204) return null;
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.message || res.statusText);
  return data;
}
// Mobile navigation drawer (< 900px): toggle, Escape to close, focus returns to the toggle.
(() => {
  const btn = document.getElementById('navtoggle'), side = document.getElementById('sidenav'), scrim = document.getElementById('navscrim');
  if (!btn || !side) return;
  const set = open => {
    document.body.classList.toggle('nav-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.setAttribute('aria-label', open ? i18n.close : i18n.open);
    scrim.hidden = !open;
    if (open) { (side.querySelector('nav a') || side).focus(); } else { btn.focus(); }
  };
  btn.addEventListener('click', () => set(btn.getAttribute('aria-expanded') !== 'true'));
  scrim.addEventListener('click', () => set(false));
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && document.body.classList.contains('nav-open')) set(false); });
  matchMedia('(min-width: 901px)').addEventListener?.('change', e => { if (e.matches && document.body.classList.contains('nav-open')) { document.body.classList.remove('nav-open'); btn.setAttribute('aria-expanded', 'false'); btn.setAttribute('aria-label', i18n.open); scrim.hidden = true; } });
})();
// Theme: Dark / Light / Match system (stored per browser; system = no data-theme, follows prefers-color-scheme).
(() => {
  const box = document.getElementById('themesw'); if (!box) return;
  const cur = () => { try { return localStorage.getItem('brain-theme') || 'system'; } catch (e) { return 'system'; } };
  const paint = () => box.querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed', b.dataset.theme === cur() ? 'true' : 'false'));
  box.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
    try { b.dataset.theme === 'system' ? localStorage.removeItem('brain-theme') : localStorage.setItem('brain-theme', b.dataset.theme); } catch (e) {}
    b.dataset.theme === 'system' ? document.documentElement.removeAttribute('data-theme') : document.documentElement.setAttribute('data-theme', b.dataset.theme);
    paint();
  }));
  paint();
})();
const esc = s => (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
</script>
@include('partials.command-palette')
@include('partials.shortcut-sheet')
@stack('scripts')
</body>
</html>
