# CCPA, HIPAA and SOC 2

**Read this first.** No software is "HIPAA compliant" or "SOC 2 certified" on its own. HIPAA compliance belongs to the covered entity and its business associates (policies, risk analysis, training, signed BAAs). SOC 2 is an attestation an independent CPA firm issues after auditing your controls (Type II covers 3–12 months of operation). CCPA obligations fall on the business that collects the data. What this codebase gives you is the **technical controls** those programmes require, already built and tested.

## CCPA / CPRA
| Requirement | Where |
|---|---|
| Right to know / access (§1798.100, .110) | Settings → Privacy → consumer request export; Profile → Download my data |
| Right to delete (§1798.105) | Privacy → Erase; Profile → Delete my account |
| Notice at collection (§1798.100(b)) | widget privacy notice (configurable) |
| Do not sell / share (§1798.120) | the platform never sells or shares personal information; no ad trackers |
| Data minimisation & retention (§1798.100(c)) | per-workspace retention, nightly purge |
| Request records (§7101 regs, 24 months) | audit log entries `privacy.dsr_*` (subject stored only as a hash) |

## HIPAA Security Rule (45 CFR §164.312) – HIPAA mode (Enterprise)
| Standard | Control |
|---|---|
| Access control (a)(1): unique IDs, automatic logoff, encryption | per-user accounts, 15-min idle logout, field encryption |
| Audit controls (b) | every conversation view/export logged (`phi.*`), hash-chained |
| Integrity (c)(1) | tamper-evident audit chain, HMAC on encrypted fields |
| Person/entity authentication (d) | mandatory 2FA in HIPAA mode |
| Transmission security (e)(1) | HSTS/TLS, signed webhooks |
| BAA with subcontractors §164.308(b) | only providers you mark as covered by a BAA (or self-hosted models) can be used; public channels (widget, Telegram, WhatsApp) are disabled |

You still need: BAAs with your hosting provider and LLM vendors, a risk analysis, policies, workforce training, breach-notification procedures.

## SOC 2 Trust Services Criteria
| Criterion | Control in product |
|---|---|
| CC6.1 logical access | roles, least privilege, hashed API tokens, tenant isolation |
| CC6.2/6.3 provisioning & removal | invitations with expiry, member removal, role changes audit-logged |
| CC6.6 boundary protection | security headers, SSRF guard, rate limiting, WAF-ready |
| CC6.7 transmission | TLS/HSTS |
| CC6.8 malicious software | dependency audit, read-only DB import guard |
| CC7.2 monitoring | auth events, audit log, Horizon, health endpoint, failed-job visibility |
| CC7.3/7.4 incident response | audit evidence; your IR runbook |
| CC8.1 change management | 5,000+ automated tests, Pint, migrations; enforce PR reviews in your Git host |
| A1.2 availability | horizontal scaling, encrypted daily backups (spatie/laravel-backup), PDBs |
| C1.1 confidentiality | encryption at rest, retention |
| PI1 processing integrity | import validation, dry-run preview, row-level errors, idempotent webhooks |

You still need: policies, vendor management, background checks, annual pen test, an auditor and an observation period.
