# Architecture

```
            ┌──────────── CDN / WAF (Cloudflare, CloudFront) ────────────┐
 browsers ──┤  static assets cached at edge · TLS 1.3 · bot & DDoS rules  ├── widget.js (cached 5 min)
            └──────────────────────────┬─────────────────────────────────┘
                                       │ L7 load balancer (health: /health)
                 ┌─────────────────────┼─────────────────────┐
           Octane web pod        Octane web pod   …  N (HPA on CPU, stateless)
                 │  FrankenPHP workers keep Laravel booted in memory
     ┌───────────┼──────────────┬───────────────────┬──────────────────┐
  Postgres 17   read replicas   Redis (cluster)     S3 / R2            LLM providers
  primary +     (DB_READ_HOST)  cache · sessions    documents,         (per tenant: BYOK
  pgvector                      queues · locks      exports, backups   or platform keys)
     │                          rate limits                │
     └──── Horizon workers (HPA) ── queues: channels > default > webhooks > imports
                                    └─ scheduler (onOneServer locks)
```

## Request lifecycle
1. `SecurityHeaders` (global) → session/auth → `SetCurrentWorkspace` → `EnforceSecurityPolicy` (idle timeout, mandatory 2FA) → route-model binding (already tenant-scoped) → controller.
2. API: `AuthenticateApiToken` (SHA-256 token lookup, plan gate) replaces the session steps.
3. `Tenant::forget()` on terminate and on every Octane request/task/tick, so no state leaks between tenants in long-lived workers.

## Multi-tenancy
Row-level: every tenant table has `workspace_id`; the `BelongsToWorkspace` trait adds a global scope and stamps inserts, and refuses to insert without a tenant. Jobs extend `TenantJob`, which re-enters the tenant. Proven by `TenantIsolationMatrixTest` (13 models × 5 operations, 14 API endpoints, 7 listings).

## Modules
| Folder | Responsibility |
|---|---|
| `app/Tenancy` | tenant context, scope |
| `app/Saas` | plans, quotas, feature gates |
| `app/Brain` | agent loop, LLM drivers, RAG, tools |
| `app/Etl` | extract (file/URL/DB) → map → transform → load; exports; backups |
| `app/Security` | field encryption, SSRF guard, TOTP 2FA, password policy |
| `app/Webhooks` | signed outbound events |
| `app/Channels` | Telegram, WhatsApp |

## Data stores
- **Postgres** recommended at scale (pgvector for RAG). MySQL 8.4 and SQLite are supported; migrations are portable.
- **Redis**: `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` = redis in production. Rate limits and scheduler locks need a shared cache.
- **Object storage**: set `FILESYSTEM_DISK=s3` and `BRAIN_DISK=s3`.

## v4 additions

- **Streaming replies** — `POST /app/api/chat/stream` and `POST /api/brain/chat/stream` return
  `text/event-stream`: `delta {text}` per token, `tool {name, arguments}` when a tool runs, then
  `done` (same body as `/chat`) or `error {message}`. Native streaming for OpenAI-compatible
  (incl. tool-call fragment assembly), Anthropic and Gemini; other drivers fall back to one chunk.
  Quota and validation run before headers are sent; the tenant is restored inside the stream.
  Behind nginx, `X-Accel-Buffering: no` disables buffering. Octane/FrankenPHP flush natively.
- **Single sign-on** — Laravel Socialite with Google, Microsoft Entra ID (socialiteproviders/microsoft)
  and GitHub. Identities live in `social_accounts`. Unverified Google emails are refused (prevents
  account takeover by email collision). Workspaces can claim a company domain (auto-join with a default
  role) and enforce SSO (owner keeps password break-glass). App 2FA still applies after SSO.
- **Slack bot** — Events API at `/channels/slack/{workspace-uuid}`, v0 signature check with a 5-minute
  replay window, retries acknowledged without reprocessing, answers mentions in-thread and DMs,
  queued on `channels`. Off on Free and in HIPAA mode.
- **Laravel Pulse** — `/pulse` for super admins (slow queries, slow jobs, exceptions, cache hit rate).
  At scale set `PULSE_INGEST_DRIVER=redis` and run `php artisan pulse:work`.
