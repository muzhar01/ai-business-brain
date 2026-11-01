# 0009 — Translate controller flash and error messages
Status: accepted (2026-10-30)

## Context
Views are translated (ADR 0001–0008) but controllers still flashed English literals via `with('status', …)` and `withErrors`.

## Options
1. Translate in the view (`__(session('status'))`): fails for interpolated strings.
2. Message-code classes: more code, no gain over JSON catalogues.
3. Wrap at source with `__()` and `:placeholders` (chosen): reuses ADR 0001 catalogues and the key-parity/key-exists tests.

## Decision
68 literals wrapped; interpolated ones use placeholders so word order can differ in ur/ar. Raw vendor/exception messages (Telegram, providers) stay untranslated on purpose. A test forbids new literals.

## Security
Values pass through placeholders and are escaped by Blade; no HTML in messages.
