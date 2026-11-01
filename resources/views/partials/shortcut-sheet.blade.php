{{-- Keyboard shortcut sheet (ADR 0013): "?" opens a native <dialog>; ignored while typing. --}}
<dialog id="shortcuts" class="cmdk shortcuts" aria-labelledby="shortcuts-title">
  <h2 id="shortcuts-title" class="shortcuts-title">{{ __('Keyboard shortcuts') }}</h2>
  <dl class="shortcuts-list">
    <div><dt><kbd>Ctrl</kbd> / <kbd>⌘</kbd> + <kbd>K</kbd></dt><dd>{{ __('Open the command palette') }}</dd></div>
    <div><dt><kbd>?</kbd></dt><dd>{{ __('Show this sheet') }}</dd></div>
    <div><dt><kbd>Esc</kbd></dt><dd>{{ __('Close a dialog') }}</dd></div>
    <div><dt><kbd>↑</kbd> <kbd>↓</kbd> <kbd>Enter</kbd></dt><dd>{{ __('Move and open in the palette') }}</dd></div>
  </dl>
  <form method="dialog" class="shortcuts-close"><button class="btn">{{ __('Close') }}</button></form>
</dialog>
<script nonce="{{ $cspNonce ?? '' }}">
(() => {
  const dlg = document.getElementById('shortcuts'); if (!dlg || !dlg.showModal) return;
  document.addEventListener('keydown', e => {
    if (e.key !== '?' || e.ctrlKey || e.metaKey || e.altKey || e.isComposing) return;
    const t = e.target, tag = t && t.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (t && t.isContentEditable)) return;
    if (document.querySelector('dialog[open]')) return;
    e.preventDefault(); dlg.showModal();
  });
  document.querySelectorAll('[data-shortcuts-open]').forEach(b => b.addEventListener('click', () => { if (!dlg.open) dlg.showModal(); }));
})();
</script>
