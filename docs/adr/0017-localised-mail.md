# 0017 — Localised transactional mail

Status: accepted (2026-10-31)

## Options
1. Leave mail English-only. Rejected: invitee/owner see a foreign-language email after a translated UI.
2. Markdown mailables + per-locale view files. Rejected: duplicates templates.
3. English-sentence keys in the existing JSON catalogues (catalogue test requires key == en value), `Mailable::locale()` set by the sender; dates via Carbon `isoFormat('LL')` in that locale. **Chosen**.

## Locale choice
Invitation: inviter's current request locale (invitee has no account yet). Briefing: workspace owner's saved locale, else app default. Locale always passes through `Locale::match()` (allow-list), never raw input.

## Security
Role is translated via fixed keys only (`admin`/`member` enum validated at invite). No new inputs or endpoints; body of briefing unchanged (`{!! !!}` is plain-text mail).
