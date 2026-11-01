# AI Business Brain · Multi-tenant SaaS (Laravel 13)

An all-in-one AI operating system for businesses, sold as SaaS. Each customer gets an isolated workspace with a company brain that runs on **any LLM**, knows their documents and data, works their CRM, talks to their customers, and moves data in and out with a full ETL engine.

## Features

**SaaS platform**
- Multi-tenant workspaces (row-level isolation, scoped automatically on every query, job and API call)
- Sign up, login, password reset, teams with owner / admin / member roles, email invitations
- Plans with limits (messages, documents, members, agents, datasets, import rows) and feature gates
- Stripe subscriptions via Laravel Cashier: checkout, plan swap, billing portal, 14-day trial
- Super-admin console: every tenant, usage, tokens, MRR, plan override, suspend, support access
- Per-workspace API tokens (hashed), rate limiting, audit log, white-label on Business

**AI brain**
- Any LLM: OpenAI, Claude, Gemini, Groq, DeepSeek, Mistral, Grok, OpenRouter, Together, Azure, Ollama, LM Studio, plus any custom OpenAI-compatible endpoint per workspace
- Bring-your-own-key per workspace (encrypted) or platform keys by plan
- Multiple agents per workspace: own instructions, model, temperature, knowledge collection, allowed actions
- Knowledge base (RAG): PDF, Word, PowerPoint, Excel, CSV, HTML, Markdown, web pages; pgvector or portable vector store; keyword fallback
- Actions: leads, tasks, business reports, dataset queries, email, webhooks (n8n/Zapier/Make), human approval queue
- Memory, sources, token usage, 👍/👎 feedback, conversation export (Markdown/JSON)

**Channels**
- Web app chat · REST API · embeddable website chat widget · Telegram bot · daily AI briefing email

**Data: ETL, import, export, mapping**
- Extract from files (CSV, TSV, XLSX, JSON, NDJSON), URLs/JSON APIs (bearer auth, JSON path, nested flattening), or external databases (MySQL, MariaDB, Postgres, SQL Server; read-only SELECT)
- Map with auto-suggestion (synonyms + fuzzy + optional AI), templates (`{First} {Last}`), defaults
- Transform: trim, lower, upper, title, number (US/EU), integer, boolean, date (incl. Excel serials), phone, email, map:a=b, replace, split, truncate, nullify…
- Load into leads, tasks, knowledge, or custom datasets (products, stock, orders…) the AI can query
- Upsert / create / update-only / skip modes with match keys, dry-run preview, row-level error report
- Scheduled syncs (hourly/daily/weekly) for URL and database sources
- Export leads, tasks, conversations, documents, datasets, audit log to CSV / Excel / JSON with column mapping and filters
- API: push rows, import files, run exports

**Security & compliance (v3)**
- TOTP two-factor auth with recovery codes, enforceable per workspace; login lockout; breached-password check; sign-in history
- Field-level encryption at rest for conversations and lead notes; encrypted secrets and backups
- SSRF guard on every outbound fetch, strict security headers/CSP, CSV-injection protection
- Tamper-evident, hash-chained audit log verified nightly
- CCPA privacy center: find, export and erase a consumer's data; retention policies; "download my data" and "delete my account"
- HIPAA mode (Enterprise): forced 2FA and 15-min idle logout, PHI access logging, BAA-only AI providers, public channels off
- Signed outbound webhooks (HMAC, retries, auto-disable) and WhatsApp Business channel
- See docs/SECURITY.md and docs/COMPLIANCE.md (what the code covers and what still needs your organisation)

**Performance & scale**
- Laravel Octane (FrankenPHP), Horizon queues with autoscaling, Redis, read replicas, composite indexes, atomic counters
- Kubernetes manifests with autoscaling (deploy/k8s), k6 load test (deploy/loadtest), docs/SCALING.md

**Easy to migrate and deploy**
- One-command setup `php artisan brain:install`
- Upgrades a single-tenant v1 install in place (existing data moves into a Default workspace)
- Portable workspace backup .zip; restore on any server or database (MySQL ⇄ Postgres ⇄ SQLite), ids remapped
- Docker image + compose (web, queue, scheduler, Postgres/pgvector, optional Ollama)

## Install (local)

```bash
composer install
cp .env.example .env
touch database/database.sqlite            # or set DB_* for MySQL / Postgres
php artisan brain:install                 # migrates, creates super admin + first workspace
php artisan serve                         # http://localhost:8000
php artisan queue:work                    # imports, exports, documents, Telegram
php artisan schedule:work                 # briefings, scheduled ETL syncs
```

Add at least one LLM key in `.env` (platform keys) or per workspace in **Settings → AI providers**.

## Docker

```bash
cp .env.example .env && php artisan key:generate --show   # paste into APP_KEY
docker compose up -d
docker compose exec app php artisan brain:install
```

## Stripe

1. Create two recurring prices (Pro, Business) and set `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_PRICE_PRO`, `STRIPE_PRICE_BUSINESS`.
2. Add a webhook to `https://your-app/stripe/webhook` with subscription and invoice events, set `STRIPE_WEBHOOK_SECRET`.
3. Plans, limits and features live in `config/saas.php`.

## Migrating

| From | Do |
|---|---|
| v2 → v3 | `php artisan migrate && php artisan brain:encrypt-existing` |
| v1 single-tenant brain | Pull the code, `php artisan migrate`. Data moves into a "Default" workspace owned by the first user. |
| Another server / database | `php artisan brain:workspace:export {id}` then `php artisan brain:workspace:import file.zip --owner=you@x.com` (or Settings → Data). |
| Spreadsheets, CRMs, ERPs | Import / ETL wizard, or schedule a URL / database sync. |

## REST API

Auth: `Authorization: Bearer brn_...` (Settings → API). Everything is scoped to the token's workspace.

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/brain/chat` | message, conversation_id?, agent_id?, provider?, model?, collection?, tools? |
| GET | `/api/brain/providers`, `/agents` | what's available |
| GET/DELETE | `/conversations`, `/conversations/{id}` · GET `/conversations/{id}/export` | history |
| POST | `/messages/{id}/rate` | feedback |
| GET/POST/DELETE | `/documents` · GET `/search?q=` | knowledge |
| GET · POST | `/actions` · `/actions/{id}/approve` · `/reject` | approvals |
| GET | `/leads`, `/tasks`, `/report?days=30` | business data |
| POST · GET | `/imports` (file or url, auto-mapped) · `/imports/{id}` | ETL in |
| POST · GET | `/exports` · `/exports/{id}` · `/exports/{id}/download` | ETL out |
| GET · POST | `/datasets` · `/datasets/{id}/rows` (push with `key` to upsert) | datasets |

## Commands

```
brain:install                  setup wizard
brain:chat {workspace}         terminal chat
brain:ingest {workspace} path  bulk knowledge ingest (file, folder, URL)
brain:providers --test         ping every LLM
brain:briefings                daily briefings (scheduled hourly)
brain:etl:scheduled            due ETL syncs (scheduled every 15 min)
brain:workspace:export|import  backups and migration
brain:retention                retention policies (nightly)
brain:audit:verify             verify audit hash chains (nightly)
brain:encrypt-existing         seal legacy plaintext rows after upgrading
make:brain-tool Name           new AI action
```

## Code map

```
app/Tenancy/                Tenant context + BelongsToWorkspace scope
app/Saas/                   plans, quotas, feature gates
app/Brain/                  agent loop, LLM drivers, knowledge, tools
app/Etl/                    SourceReader, AutoMapper, Transformer, Importer, Exporter, Xlsx, WorkspaceBackup
app/Channels/Telegram.php   Telegram bot
app/Http/Controllers/App/   web app (auth, dashboard, agents, CRM, ETL, settings, billing, admin, widget)
app/Http/Controllers/Brain/ JSON API (shared by web UI and REST)
config/brain.php            providers, models, tools, search
config/saas.php             plans, limits, features
tests/                      5,033 tests / 32,818 assertions (php artisan test --parallel)
```
