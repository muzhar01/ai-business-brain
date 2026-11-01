# Scaling to millions of users

**Honest note.** "5 million users at a time" is a property of the deployment (servers, database, LLM vendor quotas), not of code alone. The code is built to scale horizontally with no shared in-process state; the numbers below are the planning model to validate with `deploy/loadtest/k6.js` on your own infrastructure. Nothing here was load-tested at that size in development.

## What makes it scale
- **Stateless web tier** on Laravel Octane (FrankenPHP): no per-request boot, ~2–5 ms framework overhead. Tenant state is reset on every Octane request.
- **Everything slow is queued** (documents, imports, exports, webhooks, Telegram/WhatsApp) on Horizon with auto-balancing and separate queues, so spikes never block web requests.
- **Composite indexes** on every hot tenant query (`workspace_id` first).
- **Atomic counters** (`incrementEach`) and race-safe usage rows; append-only audit with row locks per workspace only.
- **Memoised plan resolution** per request; webhook endpoints cached 60 s per tenant.
- **Shared-nothing scheduler** (`onOneServer`) and Redis locks.
- **Read replicas**: set `DB_READ_HOST` (comma-separated) and `DB_STICKY=true`.

## Planning model for 5,000,000 concurrent users
Assume 1 request / user / 30 s on average (chat UIs are mostly idle) → **~170,000 req/s**.

| Layer | Sizing (starting point) |
|---|---|
| Edge / CDN | serves widget.js and CSS; absorbs ~40% of requests |
| Web pods | ~1,000 req/s per 2-vCPU Octane pod for cached/DB reads → **~120–200 pods** (HPA 6→400) |
| Postgres | primary + 4–8 read replicas (e.g. Aurora / AlloyDB); pgBouncer in transaction mode; partition `brain_messages` by month at > 1B rows; shard by `workspace_id` (Citus) beyond a single primary |
| Redis | cluster mode, 6+ shards; separate instances for cache vs. queues |
| Workers | Horizon HPA 4→200 pods |
| LLM | the real ceiling. 170k req/s of chat is beyond any single vendor's default quota: spread tenants across providers (BYOK), use local models (vLLM/Ollama) for bulk, cache embeddings |

## Checklist before a big launch
1. `php artisan config:cache route:cache view:cache event:cache` (the Docker image does this on boot via serversideup autorun).
2. Redis for cache/session/queue; never `file`/`database` at scale.
3. Run k6 at 2× expected peak; watch p95 < 150 ms on reads, error rate < 0.1%.
4. Enable Pulse/Prometheus metrics and alert on queue wait > 30 s.
