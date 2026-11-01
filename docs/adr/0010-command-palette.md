# 0010 — ⌘K / Ctrl+K command palette

Status: accepted (2026-10-30)

## Context
Users with 15+ pages need keyboard navigation; backlog item "⌘K palette (WAI-ARIA combobox, fuzzy search)".

## Options
1. Third-party palette lib (ninja-keys, cmdk): new dependency, CSP and RTL risk. Rejected.
2. Server-side search endpoint: new attack surface, tenancy concerns, not needed for page jumping. Deferred (for records search).
3. Native `<dialog>` + inline JS reading the existing sidebar links. **Chosen**: zero new routes, labels are already translated, role-gated links (settings, admin) are automatically respected, focus trap and Esc come from the platform.

## Decision
`partials/command-palette.blade.php` included once in the app layout. ARIA 1.2 combobox (input role=combobox, aria-controls, aria-activedescendant) + listbox/option; IME-safe (isComposing); Ctrl/⌘+K toggles; topbar button for touch. Items are built via textContent only (no innerHTML). Navigation only to hrefs already in the sidebar.

## Security
No new endpoints; no HTML injection (textContent); hrefs come from server-rendered same-origin links; no data leaves the page.

## Follow-ups
Record search (leads, tasks) via a throttled, workspace-scoped endpoint; shortcut sheet; recent items.
