# 0006 i18n slice 2b-4a: billing, profile, security, super admin

Status: accepted. Follows ADR 0001-0005.
- Billing, profile (details/password/data), security (2FA, sign-in activity) and super admin views wrapped with `__()`; en/ur/ar +105 keys.
- Runtime-built labels use namespaced keys (`quota.*`, `feature.*`, `event.*`) so short words stay unambiguous ("Messages" the quota vs the column). Plan names (Free/Pro/Business/Enterprise) are plain keys. Unknown sign-in event names fall back to the raw stored value via `Lang::has`.
- I18nTest: namespaced keys are exempt from "en value == key", prefix-dynamic keys are exempt from the unused-key check; the `__('prefix.'.$x)` pattern is skipped by the used-key scan.
- a11y: usage bars get `role=progressbar` with value/label; QR code `role=img`; password/confirm inputs get aria-label + autocomplete; numeric/code/price values `dir=ltr`.
- Security: no new input paths; output escaped; stored values unchanged.
- Remaining slice 2b-4b: settings tabs (general, team, providers, channels, webhooks, api, privacy, data), flash messages.
