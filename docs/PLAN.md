# SupportHive — Development Plan

> Status: **scaffold complete, awaiting the app plan.**
> Owner: Muhammad Waris · Last updated: 2026-09-23

This is the single source of truth for *what* we are building and *why*. The
per-task state lives in [TRACKER.md](TRACKER.md); this file holds decisions.

---

## 1. Product definition

_Fill this in when the app plan lands._

| Question | Answer |
|---|---|
| What problem does SupportHive solve? | _TBD_ |
| Who are the users? | _TBD (expected: customer, agent, admin)_ |
| What is the single most important user journey? | _TBD_ |
| What does success look like in 3 months? | _TBD_ |
| Explicitly out of scope for v1 | _TBD_ |

## 2. Roles and permissions

Define this before writing any feature — it determines every authorisation
check. Table shape: role × resource × action.

| Role | Can read | Can create | Can update | Can delete |
|---|---|---|---|---|
| customer | own records | own records | own records (limited) | — |
| agent | assigned + queue | replies | assigned records | — |
| admin | everything | everything | everything | with audit trail |

## 3. Data model

Current tables (see `database/migrations`):

- `users` — identity, role, status, login audit fields
- `auth_tokens` — hashed, single-use password-reset / verification tokens
- `rate_limits` — throttling counters
- `audit_log` — append-only trail of security-relevant actions
- `migrations` — runner bookkeeping

Domain tables to be added once the app plan is defined. For every new table,
decide up front: owner column, soft-delete or hard-delete, indexes for the
queries we actually run, and retention.

## 4. Milestones

| # | Milestone | Contents | Exit criteria |
|---|---|---|---|
| M0 | **Foundation** ✅ | Repo, structure, core classes, security baseline, CI/CD | `php scripts/preflight.php` green locally; deploy pipeline proven |
| M1 | **Authentication** | Register, verify email, login, logout, password reset, roles, throttling | A user can complete every auth flow; all flows rate-limited and audited |
| M2 | **Core domain** | The primary entity + list/detail/create/update, ownership rules | The main user journey works end-to-end on staging |
| M3 | **Dashboard** | ApexCharts metrics, paginated + filterable tables | Numbers match a hand-written SQL check |
| M4 | **Admin** | User management, role assignment, audit log viewer | Admin can run the system without touching the database |
| M5 | **Hardening & launch** | Security review, load sanity check, backups, error monitoring | `docs/SECURITY.md` pre-launch checklist fully ticked |

## 5. Architecture decisions

Append a row whenever a decision is made that a future reader would otherwise
have to guess at.

| Date | Decision | Why | Alternatives rejected |
|---|---|---|---|
| 2026-09-23 | Core PHP, no framework | Client requirement; full control over the request lifecycle | Laravel (host constraints, learning overhead) |
| 2026-09-23 | Composer for dev tools only | Shared host has no shell; the app must boot with an empty `vendor/` | Shipping `vendor/` over FTP |
| 2026-09-23 | Single front controller (`public/index.php`) + explicit route table | Only one web-reachable entry point; no URL-to-file mapping to exploit | One PHP file per page |
| 2026-09-23 | Application code lives outside the web root on the host | Source, `.env` and logs are unreachable over HTTP even if PHP stops executing | Everything in `public_html` |
| 2026-09-23 | Vendored front-end libraries, no CDN | Keeps CSP at `'self'`; no third party can change the bytes we ship | CDN links |
| 2026-09-23 | Server-side pagination instead of DataTables | Avoids jQuery; does not send the whole table to the browser; scales | DataTables |
| 2026-09-23 | Deploy via GitHub Actions over FTPS to Hostinger | Available on every plan; keeps deploys reproducible from `main` | Manual FileZilla uploads; Hostinger Git integration (less control over the build) |

## 6. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Shared host has an older PHP than 8.3 | Code may not run | Resolved 2026-09-23: host runs PHP 8.3.30. `preflight.php` still asserts ≥ 8.2 |
| No shell on host → migrations run by hand | Schema drift between local and prod | Run `migrate.php` locally against the prod DB, or import via phpMyAdmin; record every run in TRACKER |
| FTPS credentials leak | Full site compromise | Store only as GitHub secrets; rotate on any suspicion; never in the repo |
| `.env` uploaded to the web root by mistake | Total credential disclosure | `.gitignore`, deploy exclusions, and a `preflight.php` check |
| Unbounded uploads fill the disk | Outage | Size limit in config; monitor disk; prune orphans |

## 7. Open questions

- [ ] Exact PHP version available on the hosting account?
- [ ] Domain name and whether HTTPS is already provisioned?
- [ ] Is a staging subdomain available (strongly recommended)?
- [ ] Transactional email: host SMTP, or a provider?
- [ ] Data retention / privacy obligations for the records we store?
