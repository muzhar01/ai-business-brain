# 0002 — i18n slice 2a: dashboard, approvals, tasks

Status: accepted (2026-10-29). Builds on 0001.

## Decision
Wrap whole-page text of three views in `__()` using the same JSON catalogues (en/ur/ar). Cut from the larger "all app views" item: smallest slices first, each guarded by `SCOPED_VIEWS` in I18nTest so regressions fail.
- Interpolated sentences use named placeholders (`:count leads`, `trial ends :when`) so word order can differ per language; no string concatenation.
- Status/priority values are translated at display only (`__($status)`); stored values stay English, so queries and API are unchanged. These keys are listed in `DYNAMIC_KEYS` in the test.
- Icon-only buttons (done toggle, delete) and placeholder-only inputs gained `aria-label`s (WCAG 4.1.2 / 3.3.2).
## Alternatives rejected
Translating stored enums in DB (breaks API/filters); per-view PHP lang files (two systems).
## Security
Output stays escaped (`{{ }}`); `:when`/`:plan` values are escaped by Blade via `__()` replacement of plain strings in `{{ }}`.
## Next slices
leads, chat, knowledge, agents, datasets, imports/exports, settings tabs, billing, profile; JS alert strings; flash messages.
