# 0011 — Palette record search (leads, tasks)

Status: accepted (2026-10-30)

## Context
ADR 0010 deferred record search. Users want to jump to a lead or task from ⌘K.

## Options
1. Client-side index of all records: leaks volume into every page load. Rejected.
2. New search service / Scout: new dependency. Rejected.
3. One throttled GET endpoint, tenant-scoped through BelongsToWorkspace, LIKE on non-sealed columns (lead name/company, task title), max 5 per type, minimum 2 characters. **Chosen**.

## Decision
`GET /search/palette?q=` (`PaletteSearchController`), JSON `{results:[{type,label,href}]}`. Links point to existing filtered pages (`leads?q=`, `tasks`). JS debounces 200 ms, aborts stale requests, merges below page results with textContent only, ignores IME composition.

## Security
Auth + workspace middleware group; global tenant scope; LIKE wildcards escaped; throttle 60/min; sealed columns (lead notes, email) never searched or returned; no HTML in responses.
