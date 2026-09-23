# LedgerHive — Task Tracker

Update in the same commit as the work. Status: `todo` · `doing` · `review` ·
`done` · `blocked`. Size: S (<2h) / M (<1d) / L (>1d).

Module definitions: [MODULES.md](MODULES.md) · Decisions: [PLAN.md](PLAN.md)

---

## Decisions settled (2026-09-23)

| ID | Decision | Consequence for the code |
|---|---|---|
| D-1 | Rebrand app to LedgerHive; infra names unchanged | `APP_NAME`, UI, docs, favicon change. Repo/DB/domain stay as-is. |
| D-2 | Cash basis - only `received` income is revenue | Every P&L aggregate filters `payment_status='received'`. Pending gets its own report. |
| D-3 | Void by status flag | Every aggregate filters `status='posted'` - enforced in one query builder, not by memory. |
| D-4 | Attachments in `storage/documents`, authenticated controller | `Upload` needs a non-public variant; `DocumentController@show` resolves by id. |
| D-5 | Vendor/payee is free text, no table | Plain column on `expenses`, trimmed on save, with a type-ahead endpoint over prior values. |
| D-6 | V1 ships **admin + partner only** | Enum keeps all four values; `role_permissions` deferred until a third role exists. |
| D-7 | Partner is **read-only** (confirmed) | Admin records transactions. Enforced in `Access::canWriteTransactions()` and asserted in `AccessTest`. |

The ledger contract is settled, so **M3 is unblocked**.

## Now

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| OPS-1 | Update `DB_PASS` secret, redeploy | S | todo | Production DB unreachable until done |
| OPS-2 | Protect `main` (require PR + green CI) | S | todo | Anything on `main` deploys straight to production |

## M1 — Auth & shell ✅ *(branch `feat/m1-auth-shell`, awaiting merge)*

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M1-1 | Migrate `users.role` → `admin/partner/accountant/data_entry` | S | done | Only admin+partner assignable |
| M1-2 | ~~`role_permissions` table~~ | — | deferred | D-6. Revisit at role #3 |
| M1-3 | Partner read-only enforcement | S | done | `can:` middleware → `Access`; verified 403 on GET and POST |
| M1-4 | Login / logout screens | M | done | Rate-limited per-email **and** per-IP; single failure path |
| M1-5 | LedgerHive palette in `app.src.css` | S | done | Plus `-text` status variants for contrast |
| M1-9 | Rebrand: `APP_NAME`, favicon, views | S | done | Infra names unchanged per D-1 |
| M1-6 | App shell: sidebar nav, spec §20.1 | M | done | Unbuilt items render disabled, not as 404 links |
| M1-7 | `settings` table + Settings screen | M | done | Typed values; changes attributed to a user |
| M1-8 | Role enforcement tests | M | done | 6 cases incl. unknown-role fail-closed |
| M1-10 | Self-hosted fonts | S | done | Google Fonts is blocked by our own `font-src 'self'` |
| M1-11 | `scripts/create-user.php` | S | done | CLI only; password from stdin, not argv |

## M2 — Master data ✅ *(branch `feat/m2-master-data`)*

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M2-1 | `partners` CRUD | M | done | Inline edit; deactivation blocked while holding a share |
| M2-2 | `partner_shares` effective-dated + 100% validation | L | done | **Acceptance criterion met.** Integer basis points; 105% refused with "5% over", zero rows written |
| M2-3 | `categories` tree, typed expense/income | M | done | 15 seeded; hidden not deleted; children follow the parent |
| M2-4 | `accounts` CRUD with opening balance | M | done | `DECIMAL(15,2)`, kept as a string end to end |
| M2-5 | `customers` CRUD | S | done | |
| M2-6 | Share-validation tests | M | done | 17 cases incl. a verified float-failure split |
| M2-7 | Vendor type-ahead endpoint | S | blocked | D-5. Needs the `expenses` table — moves to M4 |
| M2-8 | Quick-login buttons for testing | S | done | Behind `DEV_QUICK_LOGIN`, off by default; every use logged |

## M3 — Ledger (critical path)

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M3-1 | `transactions` schema + indexes | M | todo | `DECIMAL(15,2)`, `status` enum. Unblocked |
| M3-2 | `TransactionService::post()` | L | todo | Ledger + satellite + audit in one DB transaction |
| M3-3 | `TransactionService::void()` | M | todo | Mandatory reason, audit |
| M3-4 | Transfers as two linked legs | M | todo | Excluded from P&L |
| M3-5 | All Transactions screen + full filter set | L | todo | Spec §17, server-side pagination |
| M3-6 | Ledger integrity tests | L | todo | Voids excluded; transfers net zero; 10k-row pagination |
| M3-7 | Query builder that always applies `status='posted'` | M | todo | D-3 mitigation - stops a hand-written `SUM()` counting voids |

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
| M6-9 | Expected-income report (pending sales) | S | todo | D-2 - pending stays visible, just not revenue |
| M6-6 | CSV export | M | todo | Native PHP, no dependency |
| M6-7 | PDF export | M | todo | Needs library decision |
| M6-8 | KPI reconciliation tests | M | todo | Dashboard == filtered ledger |

## M7 — Documents & audit

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M7-1 | `attachments` + upload flow to `storage/documents` | M | todo | D-4. Needs a non-public `Upload` variant |
| M7-2 | `DocumentController@show` | M | todo | Session + record-ownership check; resolve by id, never by path |
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
