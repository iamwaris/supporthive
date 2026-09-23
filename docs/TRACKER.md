# LedgerHive — Task Tracker

Update in the same commit as the work. Status: `todo` · `doing` · `review` ·
`done` · `blocked`. Size: S (<2h) / M (<1d) / L (>1d).

Module definitions: [MODULES.md](MODULES.md) · Decisions: [PLAN.md](PLAN.md)

---

## Blocked on a decision

| ID | Task | Blocking question |
|---|---|---|
| D-1 | Rename app to LedgerHive | Repo/DB/domain provisioned as SupportHive — rename, or keep infra names? |
| D-2 | Sales `pending` handling | Does pending income count toward profit? Changes every P&L query. |
| D-3 | Void vs reversal | Status flag, or posted reversing entry? Changes the ledger contract. |
| D-4 | Attachment storage | `storage/documents` + authed controller, or `public/uploads`? |

D-2 and D-3 must be settled **before M3** — they define the ledger contract.
D-1 is cheapest now (no production data). D-4 is needed before M10.

## Now

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| OPS-1 | Update `DB_PASS` secret, redeploy | S | todo | Production DB unreachable until done |
| OPS-2 | Protect `main` (require PR + green CI) | S | todo | Anything on `main` deploys straight to production |

## M1 — Auth & shell

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M1-1 | Migrate `users.role` → `admin/partner/accountant/data_entry` | S | todo | Scaffold enum is wrong product |
| M1-2 | `role_permissions` table + seed | M | todo | module × ability, per spec §5 |
| M1-3 | `permission:` middleware | M | todo | Extends existing `role:` middleware |
| M1-4 | Login / logout screens | M | todo | Rate-limited, audited — primitives exist |
| M1-5 | LedgerHive palette in `app.src.css` | S | todo | Navy/slate/amber per spec §2 |
| M1-6 | App shell: sidebar nav, spec §20.1 | M | todo | Lucide icons, mobile responsive |
| M1-7 | `settings` table + Settings screen | M | todo | Company, currency, fiscal start, approval threshold |
| M1-8 | Permission enforcement tests | S | todo | Data Entry must not reach `/users` |

## M2 — Master data

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M2-1 | `partners` CRUD | M | todo | |
| M2-2 | `partner_shares` effective-dated + 100% validation | L | todo | **Acceptance criterion.** Service + tests |
| M2-3 | `categories` tree, typed expense/income | M | todo | Seed spec's 12 starter categories |
| M2-4 | `accounts` CRUD with opening balance | M | todo | |
| M2-5 | `customers` CRUD | S | todo | |
| M2-6 | Share-validation unit tests | M | todo | Mid-period change, deactivation, 99.99% rejection |

## M3 — Ledger (critical path)

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M3-1 | `transactions` schema + indexes | M | todo | `DECIMAL(15,2)`; blocked by D-2, D-3 |
| M3-2 | `TransactionService::post()` | L | todo | Ledger + satellite + audit in one DB transaction |
| M3-3 | `TransactionService::void()` | M | todo | Mandatory reason, audit |
| M3-4 | Transfers as two linked legs | M | todo | Excluded from P&L |
| M3-5 | All Transactions screen + full filter set | L | todo | Spec §17, server-side pagination |
| M3-6 | Ledger integrity tests | L | todo | Voids excluded; transfers net zero; 10k-row pagination |

## M4 — Daily use

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M4-1 | Expense list + add/edit | L | todo | Spec §8 |
| M4-2 | Fast-entry affordances | M | todo | Today default, remembered account, save-and-add-another |
| M4-3 | Income list + add/edit | L | todo | Spec §9 |
| M4-4 | Transfer screen | M | todo | |
| M4-5 | Partner contributions | M | todo | Not income |
| M4-6 | Partner withdrawals | M | todo | Not an expense |
| M4-7 | Capital-isolation tests | M | todo | Contribution moves balance, not P&L |

## M5 — Controls

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M5-1 | `budgets` schema + CRUD | M | todo | Unique on year+month+category |
| M5-2 | Budget vs actual view | M | todo | Live from ledger |
| M5-3 | Threshold + exceeded alerts | S | todo | Configurable, default 80% |
| M5-4 | Profit calculation service | L | todo | Distributable profit for a period |
| M5-5 | Profit distribution + approval flow | L | todo | Calculated vs distributed |
| M5-6 | Rounding-remainder tests | M | todo | Allocations must sum exactly to profit |

## M6 — Visibility

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M6-1 | Dashboard KPI aggregates | L | todo | SQL only, bounded query count |
| M6-2 | Dashboard widgets, spec §14 | L | todo | |
| M6-3 | Chart JSON endpoints | M | todo | No inline script data — CSP |
| M6-4 | ApexCharts wiring, spec §15 | M | todo | Vendored already |
| M6-5 | 12 reports with filters | L | todo | Spec §16 |
| M6-6 | CSV export | M | todo | Native PHP, no dependency |
| M6-7 | PDF export | M | todo | Needs library decision |
| M6-8 | KPI reconciliation tests | M | todo | Dashboard == filtered ledger |

## M7 — Documents & audit

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M7-1 | `attachments` + upload flow | M | todo | Blocked by D-4 |
| M7-2 | Authenticated document controller | M | todo | Ownership check per file |
| M7-3 | Audit every write path | M | todo | Old/new values |
| M7-4 | Audit log viewer | M | todo | |
| M7-5 | Approval threshold | M | todo | Configurable amount |
| M7-6 | Pre-launch security checklist | M | todo | `docs/SECURITY.md` §7 |
| M7-7 | Backup + restore drill | M | todo | A backup isn't a backup until restored |

## Done

| ID | Task | Landed | Notes |
|---|---|---|---|
| M0-1 | Repo + MVC structure | 2026-09-23 | |
| M0-2 | 16 core classes | 2026-09-23 | Router, DB, Session, Csrf, Validator, Auth, RateLimiter, Upload, … |
| M0-3 | Security baseline | 2026-09-23 | CSP, headers, hardened `.htaccess`, uploads execution block |
| M0-4 | Scaffold migrations | 2026-09-23 | users, auth_tokens, rate_limits, audit_log |
| M0-5 | Tailwind + Alpine + ApexCharts + Lucide, vendored | 2026-09-23 | |
| M0-6 | PHPCS / PHPStan L5 / PHPUnit | 2026-09-23 | |
| M0-7 | CI workflow | 2026-09-23 | Lint, static analysis, tests, build, secret scan |
| M0-8 | SSH deploy pipeline | 2026-09-23 | Gated, verified, smoke-tested |
| M0-9 | First production deploy | 2026-09-23 | Live; CSP override and uploads-execution holes found and fixed |
| M0-10 | Product spec received; module plan written | 2026-09-23 | `MODULES.md` |

---

## Decision log for in-flight work

| Date | Note |
|---|---|
| 2026-09-23 | CSP keeps `script-src 'unsafe-eval'` (Alpine) and `style-src 'unsafe-inline'` (ApexCharts). Revisit with the Alpine CSP build. |
| 2026-09-23 | Hostinger overrides PHP's CSP header; policy duplicated in `public/.htaccess`. Keep both in sync. |
| 2026-09-23 | Scaffold's generic `agent/customer` roles are wrong for this product — migrate in M1 before any data exists. |
| 2026-09-23 | Spec's suggested structure has `views/` at root and `app/Services` + `app/Repositories`. Keeping views in `app/Views` (scaffold convention, outside web root) and adding `app/Services`. Repositories deferred until a model outgrows `app/Models`. |
