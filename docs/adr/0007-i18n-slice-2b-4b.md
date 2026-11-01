# 0007 i18n slice 2b-4b: settings tabs (general, team, data & audit)

Status: accepted. Follows ADR 0001-0006.
- Settings tab bar, General, Team (incl. SSO) and Data & audit translated en/ur/ar (~70 keys). Stored roles stay `member`/`admin`; options carry explicit `value`s so translated labels never get submitted.
- Labels get `for`/`id`; icon-less inputs get aria-labels; email/domain/URL inputs `dir=ltr`; confirms via `@js(__())`. Example placeholders (URLs, domains, emails) are language-neutral literals echoed via `{{ }}`.
- Security: no new inputs or routes; output escaped.
- Remaining slice 2b-4c: providers, api (curl sample), webhooks, channels, privacy; flash messages (controllers).
