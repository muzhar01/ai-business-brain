# 0014 — Jump to date in conversations

Status: accepted (2026-10-30)

## Options
1. Server endpoint returning the message offset for a date: extra route, round trip. Rejected.
2. Paginated history with date anchors: large change, conversations are already fully loaded. Deferred.
3. Tag each rendered bubble with `data-ts` (created_at already in the JSON) and scroll to the first bubble on or after a native `<input type="date">`. **Chosen**.

## Decision
Date input in the find bar; comparison in the viewer's local day; result announced in an aria-live status; target gets tabindex=-1 and focus. Native date input gives locale and mobile pickers for free.

## Security
No new endpoint; input validated by regex; text via textContent.
