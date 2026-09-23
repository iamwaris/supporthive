# SupportHive — Task Tracker

Update this in the same commit as the work. One line per task.
Status: `todo` · `doing` · `review` · `done` · `blocked`

**Legend** — ID: `M<milestone>-<n>` · Size: S (<2h) / M (<1d) / L (>1d)

---

## Now (in progress)

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M0-9 | Authorise the deploy key on the host and set GitHub secrets | S | doing | Blocks first deploy. Host confirmed on PHP 8.3.30 |

## Next (ready to pick up)

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M1-1 | `AuthController`: login form + POST handler | M | todo | Rate-limit `login:<email>` and `login-ip:<ip>` |
| M1-2 | Registration with email verification token | M | todo | Store only the token hash in `auth_tokens` |
| M1-3 | Password reset request + reset form | M | todo | Single-use token, 60-min expiry, invalidate all sessions on reset |
| M1-4 | Logout + session teardown | S | todo | POST only, CSRF-checked |
| M1-5 | Role middleware coverage test | S | todo | Assert a customer cannot reach admin routes |
| M1-6 | Auth flow unit + feature tests | M | todo | |

## Backlog

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M2-x | Core domain CRUD | L | blocked | Waiting on the app plan |
| M3-1 | Dashboard metric queries | M | todo | Verify each number against hand-written SQL |
| M3-2 | ApexCharts wiring (vendored, JSON endpoint) | M | todo | |
| M3-3 | Paginated + filterable table component | M | todo | Server-side, Alpine-enhanced |
| M4-1 | Admin user management | M | todo | |
| M4-2 | Audit log viewer | S | todo | |
| M5-1 | Full pass over the `docs/SECURITY.md` pre-launch checklist | M | todo | |
| M5-2 | Automated database backup + restore drill | M | todo | A backup is not a backup until a restore has been tested |
| M5-3 | Error-log monitoring / alerting | S | todo | |

## Done

| ID | Task | Landed | Notes |
|---|---|---|---|
| M0-1 | Repository + MVC folder structure | 2026-09-23 | |
| M0-2 | Core classes: Env, Config, Database, Session, Csrf, Validator, Auth, RateLimiter, Upload, Router, View, Controller, Model, Http, Logger, ErrorHandler | 2026-09-23 | |
| M0-3 | Security baseline: CSP + headers, hardened `.htaccess`, uploads execution block, deny-all outside the web root | 2026-09-23 | |
| M0-4 | Initial migrations: users, auth_tokens, rate_limits, audit_log | 2026-09-23 | |
| M0-5 | Tailwind + Alpine + ApexCharts + Lucide front-end build, all vendored | 2026-09-23 | |
| M0-6 | Tooling: PHPCS (PSR-12 + banned functions), PHPStan L5, PHPUnit | 2026-09-23 | |
| M0-7 | CI workflow (lint, static analysis, tests, front-end build) | 2026-09-23 | |
| M0-8 | Deploy workflow (SSH/rsync to Hostinger on `main`) | 2026-09-23 | Gated behind `DEPLOY_ENABLED`; migrations opt-in via `RUN_MIGRATIONS` |

---

## Decision log for in-flight work

Short notes that are not yet worth a row in `PLAN.md` §5.

| Date | Note |
|---|---|
| 2026-09-23 | CSP keeps `script-src 'unsafe-eval'` for Alpine and `style-src 'unsafe-inline'` for ApexCharts. Revisit if we later adopt the Alpine CSP build. |
