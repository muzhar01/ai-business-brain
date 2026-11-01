# 0018 — Locale-aware dates in views

Status: accepted (2026-10-31)

## Options
1. PHP `intl` NumberFormatter/IntlDateFormatter. Rejected: ext-intl is not a declared requirement and absent here.
2. Leave `format('M j')`. Rejected: English month names in ur/ar UIs.
3. Small `Format` helper over Carbon `isoFormat` (Carbon follows App::setLocale, verified for ar/ur). **Chosen**. `diffForHumans()` already localised.

## Scope
Four views (admin, imports/create, settings/data, tasks). Numbers keep Latin digits with `number_format` (deliberate: codes, quotas and CSV stay unambiguous). Remaining numbers/plurals are slice 3c.

## Security
Output only; values are Carbon instances, escaped by Blade. No input.
