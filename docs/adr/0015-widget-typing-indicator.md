# 0015 — Widget typing indicator and small-screen layout

Status: accepted (2026-10-30)

## Options
1. Keep the "…" placeholder: not announced, no feedback. Rejected.
2. Server-sent partial tokens in the widget: needs a streaming public endpoint; deferred.
3. Animated three-dot indicator (CSS only, static under prefers-reduced-motion), visually-hidden translated "Assistant is typing…" for the aria-live log, aria-busy on the bubble, Send disabled while pending, Escape closes the panel, full-screen sheet with dvh under 480px. **Chosen**.

## Security
Static markup only; the translated string is set with textContent; no new endpoint.
