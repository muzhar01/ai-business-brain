# Security

| Control | Implementation | Verified by |
|---|---|---|
| Tenant isolation | global scope + insert guard + scoped route binding | TenantIsolationMatrixTest (~100 cases) |
| AuthN | bcrypt/argon, ≥12-char complex passwords, breached-password check (HIBP k-anonymity), lockout after 8 failures / 15 min | SecurityComplianceTest |
| MFA | TOTP (RFC 6238) + one-time hashed recovery codes, replay protection, enforceable per workspace | SecurityComplianceTest |
| Sessions | regenerate on login, idle timeout, sign out other devices on password change, HttpOnly + Secure + SameSite cookies | SecurityComplianceTest |
| AuthZ | owner / admin / member roles, super-admin, API tokens hashed (SHA-256) and revocable | AuthorizationMatrixTest (~150 cases) |
| Encryption at rest | AES-256-CBC + HMAC (APP_KEY) on message content, lead notes, provider keys, channel tokens, DB credentials, webhook secrets; encrypted backups (`BACKUP_ARCHIVE_PASSWORD`) | CryptoMatrixTest (~400 cases) |
| Encryption in transit | HSTS (2 years, preload), TLS at the edge | ContractMatrixTest |
| XSS | Blade escaping everywhere, CSP, `frame-ancestors 'none'` | InjectionMatrixTest (160 payload × page cases) |
| SQL injection | query builder bindings only; read-only SELECT guard for DB imports | InjectionMatrixTest (140 cases) |
| SSRF | `UrlGuard` blocks private, loopback, link-local (cloud metadata), CGNAT, IPv6 ULA, and re-checks every redirect hop for URL imports, web pages, webhooks | UrlGuardMatrixTest (~650 cases) |
| CSRF | Laravel tokens; only signed webhooks are exempt (Stripe, Telegram secret, WhatsApp HMAC) | tests |
| CSV/Excel injection | `=`, `+`, `-`, `@` prefixed cells neutralised | EtlMatrixTest |
| Audit | append-only, SHA-256 hash-chained per workspace, nightly verification | SecurityComplianceTest |
| Dependencies | `composer audit` clean at release; Dependabot recommended | CI |
| Rate limiting | login, 2FA, API, widget (per IP and per tenant) | — |

Report vulnerabilities to security@your-domain (set up a security.txt).

## SSO and Slack (v4)
- OAuth state is verified by Socialite; failures return to /login without detail.
- Account linking only on a verified email; public email domains (gmail.com, outlook.com…) can't be claimed; a domain can be claimed by only one workspace.
- Slack requests: HMAC-SHA256 `v0` signature, constant-time compare, ±300 s timestamp window; bot token and signing secret encrypted at rest and hidden from serialization.


## Patch levels (updated 2026-10-25)

| Component | Minimum | Why |
| --- | --- | --- |
| PHP 8.4 | 8.4.27 | CVE-2026-109618 / CVE-2026-109619, pre-auth RCE in request handling (FrankenPHP affected). Enforced by a build guard in the Dockerfile. |
| laravel/framework | ^13.30 | GHSA-f9x3-7vq9-cph6 (TokenGuard bypass, 13.29), GHSA-5wcm-3v2w-8362 (JSON:API include truncation, 13.27), CVE-2026-102279 (debug page XSS, 13.30). This app uses session guards + its own ApiToken, but the floor stops a downgrade. |
| ca-certificates | latest | eMudhra intermediate CA compromise; the image upgrades the bundle at build time. |

Rebuild images with `docker compose build --pull` so the floating `serversideup/php:8.4-frankenphp` tag is refreshed.
