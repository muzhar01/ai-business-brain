# 0008 i18n slice 2b-4c: providers, API, webhooks, channels, privacy

Status: accepted. Follows ADR 0001-0007.
- Five remaining settings views translated en/ur/ar (118 keys). Only slice left: controller flash messages.
- Technical text (curl samples, signature code, URLs, tokens, keys, model names) stays literal and gets `dir="ltr"` so it does not reorder in RTL. Labels get `for`/`id`; placeholder-only inputs get aria-labels; the webhook event checkboxes sit in a `role=group` labelled by a visible caption instead of an orphan `<label>`.
- Catalogue values may not contain markup (existing test), so the widget snippet hint says "closing body tag" instead of embedding `</body>`.
- Dynamic webhook delivery status goes through `__($status)`: stored values (`delivered`, `failed`, `pending`) stay English.
- Confirm dialog uses `@js(__())`; HIPAA hint and found-counts use placeholders so RTL word order is free.
- Security: no routes or inputs changed; every string is echoed escaped.
