# 0012 — In-conversation find

Status: accepted (2026-10-30)

## Options
1. Server-side search endpoint: round trip, no benefit, the conversation is already loaded. Rejected.
2. CSS Custom Highlight API: no DOM changes but weak support on older browsers. Rejected for compatibility.
3. Client-side TreeWalker over text nodes, wrapping matches in `<mark>` built with textContent. **Chosen**.

## Decision
A "Find" toggle opens a `role=search` bar above the log: min 2 chars, case-insensitive, Enter/Shift+Enter step, Esc closes and restores focus, count in an aria-live status. Logical CSS only; forced-colors fallback.

## Security
No innerHTML: matches are created via textContent; no new endpoint.
