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
| OPS-1 | Update `DB_PASS` secret, redeploy | S | done | Rotated 2026-09-23; production DB connects |
| OPS-2 | Protect `main` (require PR + green CI) | S | deferred | User's explicit call while solo and testing on live — revisit before other users get access |
| OPS-3 | Turn off `DEV_QUICK_LOGIN` before wider access | S | done | Repo variable flipped to `false` and redeployed 2026-09-24 |

## Live status (2026-09-24)

**M1–M4 are merged to `main` and deployed to https://myinvoicestudio.com.**
Verified on production, not just by build hash: all 6 migrations applied,
14 tables, every new route exists and requires auth, CSP intact, no secrets
web-reachable, `transactions` table empty (no test data leaked from local).

M5 — budgets and profit distribution — is built on `feat/m5-budgets-profit-distribution`,
verified locally (migration applied, `composer check` clean, 15 new tests
passing) and awaiting PR review and merge. Next after that: **M6 — visibility.**

## M1 — Auth & shell ✅ *(merged to `main`, deployed)*

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
| M1-12 | In-app Users screen | M | done | `App\Models\User` + `UserController`, scoped to the admin's own branch via `App\Core\Model`'s existing branch scoping. Create/edit-name-and-role/suspend/reset-password; no hard delete (users are FK-referenced from every ledger row via `created_by`). Sidebar's "Users" item switched from `ready => false` to `true`. Test: `tests/Feature/UserManagementTest.php` |

## M2 — Master data ✅ *(merged to `main`, deployed)*

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M2-1 | `partners` CRUD | M | done | Inline edit; deactivation blocked while holding a share |
| M2-2 | `partner_shares` effective-dated + 100% validation | L | done | **Acceptance criterion met.** Integer basis points; 105% refused with "5% over", zero rows written |
| M2-3 | `categories` tree, typed expense/income | M | done | 15 seeded; hidden not deleted; children follow the parent |
| M2-4 | `accounts` CRUD with opening balance | M | done | `DECIMAL(15,2)`, kept as a string end to end |
| M2-5 | `customers` CRUD | S | done | |
| M2-6 | Share-validation tests | M | done | 17 cases incl. a verified float-failure split |
| M2-7 | ~~Vendor type-ahead endpoint~~ | — | moved | D-5. Needed the `expenses` table — built as M4-8 instead |
| M2-8 | Quick-login buttons for testing | S | done | Behind `DEV_QUICK_LOGIN` — see OPS-3, it's ON in production by request |

## M3 — Ledger ✅ *(merged to `main`, deployed)*

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M3-1 | `transactions` schema + indexes | M | done | `DECIMAL(15,2)`, 8 indexes, 3 CHECK constraints |
| M3-2 | `TransactionService::post()` | L | done | Ledger + optional satellite + audit in one DB transaction |
| M3-3 | `TransactionService::void()` | M | done | Mandatory reason; voids both legs of a transfer |
| M3-4 | Transfers as two linked legs | M | done | Verified: net zero, P&L untouched, balances exact |
| M3-5 | All Transactions screen + full filter set | L | done | Spec §17; server-side paging capped at 200 |
| M3-6 | Ledger integrity tests | L | done | 26 cases: voids, transfers, capital, atomicity, paging |
| M3-7 | Query builder applying `status='posted'` | M | done | `LedgerQuery`; filter set in the constructor |
| M3-8 | `TransactionType` as the single source of truth | M | done | direction and P&L membership live in one enum |
| M3-9 | Nested transaction support | S | done | `postTransfer` posts two rows through `post()` |
| M3-10 | Derived account balances | S | done | Opening + posted movements; never stored |

## M4 — Daily use ✅ *(merged to `main`, deployed to production)*

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M4-1 | Expense list + add/edit | L | done | Month filter, search, by-category breakdown |
| M4-2 | Fast-entry affordances | M | done | Today default, remembered account, save-and-add-another, vendor type-ahead |
| M4-3 | Income list + add/edit | L | done | Cash-basis: pending invoices excluded from revenue until marked received |
| M4-4 | Transfer screen | M | done | Live balances per account, derived not stored |
| M4-5 | Partner contributions | M | done | Verified: P&L row count unchanged after posting |
| M4-6 | Partner withdrawals | M | done | Same verification |
| M4-7 | Capital-isolation tests | M | done | Confirmed live: contribution/withdrawal do not touch profit |
| M4-8 | Vendor type-ahead endpoint | S | done | Was M2-7, blocked on `expenses` table; built here |
| M4-9 | `markReceived()` flow | M | done | Pending → posted; starts counting from the received date |

## M5 — Controls ✅ *(built, pending deploy — see Now)*

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M5-1 | `budgets` schema + CRUD | M | done | Unique on `(year, month, category_id)`; top-level expense categories only |
| M5-2 | Budget vs actual view | M | done | `LedgerQuery::groupedByCategory()`, same rollup every other report uses |
| M5-3 | Threshold + exceeded alerts | S | done | Configurable per budget, default 80%; `ok`/`warning`/`exceeded` badges |
| M5-4 | Profit calculation service | L | done | `ProfitDistributionService`; cash-basis P&L over the period, split read as of period end |
| M5-5 | Profit distribution + approval flow | L | done | `calculate → approve → distribute`, each a separate audited action; posts through `TransactionService::post()` |
| M5-6 | Rounding-remainder tests | M | done | Largest-remainder method in bcmath cents; exact-sum, tie-break-by-id and mid-period-split cases all pass |

## M6 — Visibility *(dashboard + reports + exports done)*

Spec PDF received 2026-09-24 (was missing from the repo — §14/§15/§16 now
extracted verbatim rather than guessed). §14 lists exactly 12 dashboard
widgets, §15 lists 8 chart types, §16 lists 12 reports.

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M6-1 | Dashboard KPI aggregates | L | done | `DashboardService` + `BudgetService`; every figure a bounded SQL aggregate |
| M6-2 | Dashboard widgets, spec §14 | L | done | All 12: Today's/Monthly Expenses, Monthly Revenue, Net Profit, Budget Used/Remaining, Account Balance, Revenue vs Expenses, Expense by Category, Budget vs Actual, Recent Transactions, Alerts |
| M6-3 | Chart JSON endpoints | M | done | `/dashboard/charts/{trend,category,budget}`; no inline script data |
| M6-4 | ApexCharts wiring, spec §15 | M | done | 3 of the 8 §15 chart types live on the dashboard (the ones §14 asks for); the rest (profit trend, revenue trend, partner allocation, account balance trend) belong on report screens, next |
| M6-8 | KPI reconciliation tests | M | done | `DashboardServiceTest` + `BudgetServiceTest`, each figure checked against an independent LedgerQuery/SQL read |
| M6-5 | 12 reports with filters | L | done | `ReportController` + `ReportService`, `app/Views/pages/reports/*`; every figure via LedgerQuery/BudgetService/ProfitDistributionService, none hand-rolled |
| M6-9 | Expected-income report (pending sales) | S | done | Folded into the Monthly Income report (separate "Expected income" section) and the Cash Flow report ("Expected, not counted" line) |
| M6-6 | CSV export | M | done | `App\Core\Csv::download()`, native `fputcsv`, streamed with UTF-8 BOM; every report has an Export CSV link that preserves its filters |
| M6-7 | PDF export | M | done | `dompdf/dompdf` (decision 2026-09-24, `docs/PLAN.md`) via `App\Core\Pdf` + `app/Views/pdf/report.php`; deploy pipeline now ships a production `vendor/` (`composer install --no-dev`) since this is the app's first runtime Composer dependency |

## M7 — Documents & audit

| ID | Task | Size | Status | Notes |
|---|---|---|---|---|
| M7-1 | `attachments` + upload flow to `storage/documents` | M | done | `attachments` table (branch-scoped), `Upload::storePrivate()`, `Attachment::attachUpload()`. Optional file at expense/income entry, plus a generic "Attach" action on the All Transactions row for anything recorded without one |
| M7-2 | `DocumentController@show` | M | done | Branch ownership check IS the query — `Attachment::find()` is `App\Core\Model`'s existing branch-scoped `find()`, so a cross-branch id 404s with no extra condition to remember. Streams from `storage/documents`, never a public path. Regression test: `tests/Feature/AttachmentTest.php` |
| M7-3 | Audit every write path | M | done | `App\Services\Audit` already covered the ledger (`TransactionService`, `ProfitDistributionService`); wired the rest — accounts, categories, customers, partners, partner shares, budgets, settings, branches, users, attachments — each create/update as an `Audit::record()` with `Audit::diff()` for updates. **Bug found and fixed along the way:** `Audit::record()` never set `branch_id`, so every audit row ever written (including the ledger ones already in production) landed with `branch_id = NULL` — dead weight for the branch-scoped viewer M7-4 needs. Fixed at the source in `Audit::record()`; regression test `tests/Feature/AuditTest.php` |
| M7-4 | Audit log viewer | M | done | `AuditLogController` + `pages/audit-log.php`, admin only, branch-scoped. `Audit::search()`/`searchCount()`/`distinctActions()`/`distinctEntityTypes()` added to the existing (previously write-only) `App\Services\Audit`. **Fixed the same class of bug found in M7-3**: `Audit::forEntity()` — written earlier, never actually called anywhere — had no branch filter at all; a guessed entity id from another branch would have leaked its audit trail the moment something finally called it. Fixed before it got its first real caller. Filters: entity type, action, user, date range. Test: `tests/Feature/AuditLogViewerTest.php` |
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
| 2026-09-24 | Partner role widened from read-only to `canWriteTransactions` (record/edit/void transactions) at owner's request. Master data (`canManageMasterData`) and profit distribution (`canDistributeProfit`) stay admin-only. `App\Services\Access::canWriteTransactions()` + `tests/Unit/AccessTest.php` updated; no route changes needed since `['can:write']` already delegates to that method. |
| 2026-09-24 | **Bug fix:** "Mark received" flipped a pending invoice to `status='posted'` and set `received_at`, but `LedgerQuery::between()` (and `DashboardService::revenueExpenseTrend()`'s raw SQL, and `ReportService::totalsByPartner()`) filtered only on `transaction_date` — so a received invoice stayed parked under its original invoice month instead of counting in the period it was actually received, contradicting cash-basis decision D-2. Fixed at the source: `LedgerQuery::between()`/default order now filter on `COALESCE(t.received_at, t.transaction_date)`, which is a no-op for every non-income type since only income ever sets `received_at`. Regression test: `tests/Feature/LedgerTest.php::testMarkingAPendingInvoiceReceivedCountsItInThePeriodItWasReceivedIn`. |
| 2026-09-25 | **Multi-branch retrofit**, at the owner's request: one deployment, one database, every business table scoped by `branch_id` (rejected: a database per branch — needs `CREATE DATABASE` hosting privileges and a per-branch migration/backup story this scale doesn't justify). New `super_admin` role (`branch_id` NULL) creates/switches branches via `/admin/branches`; every other user belongs to exactly one branch, fixed at login. Enforced centrally: `LedgerQuery`'s constructor and `App\Core\Model` (find/updateById/deleteById/count/paginate/create) are branch-scoped by default — the latter also closes a pre-existing gap where `Model::find($id)` had no ownership check at all. `Router::requireAbility()` now calls `Auth::requireActiveBranch()`, so every existing `can:*` route is branch-gated with zero changes to `routes/web.php`. Migration: `database/migrations/2026_09_25_000008_branches_and_tenant_scope.sql`. Load-bearing test: `tests/Feature/BranchIsolationTest.php`. See `docs/PLAN.md` §5 for the full decision and rejected alternative. |
| 2026-09-27 | **In-app Users screen.** Previously CLI-only (`scripts/create-user.php`); the sidebar's "Users" item existed but was rendered disabled since M1. Built as `App\Models\User` (a normal `App\Core\Model` subclass) + `UserController`, admin-only (`can:administer`) and scoped to the acting admin's own branch for free, the same way `AccountController`/`PartnerController`/etc. already are. Deliberately no hard delete: `transactions.created_by` and similar FKs reference `users` without `ON DELETE CASCADE`, so removing a user with any history would either fail outright or silently orphan records — suspend (a status flag) covers "this person should no longer be able to sign in" without that risk. Three self-targeting guards in the controller: can't change your own role, can't suspend yourself, can't reset your own password here (use the existing `/account/password` instead) — all three exist to prevent an admin locking themselves out through this exact screen. `super_admin` stays unreachable from it, matching the branch-management split decided 2026-09-25/26. |
| 2026-09-27 | **M7-1/M7-2: attachments.** `attachments` table (branch-scoped like every other business table since the retrofit); `App\Core\Upload::storePrivate()` writes to `storage/documents` instead of `public/uploads`, sharing the same MIME-sniffing/size/extension checks via a private `write()` helper rather than duplicating them. `Attachment::attachUpload()` is the one place a file becomes a row. `DocumentController@show` deliberately adds no separate ownership check — `Attachment::find()` is `App\Core\Model`'s existing branch-scoped `find()`, so a cross-branch id already doesn't exist from the query's point of view, the same guarantee `BranchIsolationTest` established for every other table. Files can be attached at expense/income entry (optional field, same POST) or after the fact to any transaction from the All Transactions row ("Attach" action, mirrors the existing "Void" pattern) via `POST /transactions/{id}/documents`. Regression test: `tests/Feature/AttachmentTest.php`. |
| 2026-09-27 | **M7-3: audit wiring, plus a real bug fix.** `App\Services\Audit` already existed and already covered the ledger (`TransactionService`, `ProfitDistributionService`) — this wired every remaining write path (master data, settings, branches, users, attachments) the same way, using the existing but previously-unused `Audit::diff()` for updates so an edit's audit row shows only what changed. While doing this, found that `Audit::record()` never populated `audit_log.branch_id` — a column added by the multi-branch migration specifically so a per-branch audit trail is queryable, but nothing ever wrote to it, silently, since the moment that migration landed. Every audit row in production to date, ledger included, carries `branch_id = NULL`. Fixed in `Audit::record()` (one line: `Auth::branchId()`), which needed no call-site changes since every existing caller already runs inside a request with the right session state. Historical rows are not backfilled — there is no way to attribute a NULL retroactively to the right branch. Regression test: `tests/Feature/AuditTest.php`. Several existing tests' `cleanUp()` needed a matching fix: their fixture branches now have audit rows FK-tied to them, so those rows must be deleted before the branch is. |
| 2026-09-27 | **M7-4: audit log viewer, plus another instance of the same bug class.** Built `AuditLogController` + `pages/audit-log.php` on top of new `Audit::search()`/`searchCount()`/`distinctActions()`/`distinctEntityTypes()` methods, all scoped to `Auth::branchId()`. While wiring it up, found that `Audit::forEntity()` — added when `Audit` was first written, never actually called from anywhere until now — had no branch filter at all: it would have handed back another branch's audit trail to anyone who guessed the right `entity_type`/`entity_id` pair. Fixed before this screen (or anything else) became its first real caller. Same root cause as M7-3's `branch_id` bug: a column/parameter existing in the schema does not mean every code path that touches it actually uses it — each one has to be checked, not assumed. Test: `tests/Feature/AuditLogViewerTest.php`. |
