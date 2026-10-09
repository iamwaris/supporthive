# LedgerHive — Development Plan

> Status: **M1–M4 live in production** (auth, master data, the ledger,
> daily entry). **M5 — budgets and profit distribution — built and tested,
> pending PR/merge. M6 dashboard (§14/§15) — built and tested, pending
> PR/merge, stacked on M5.** Next: M6 reports (§16).
> Owner: Muhammad Waris · Last updated: 2026-09-24
> Source of truth for product scope: `LedgerHive_App.pdf` V1.0 (now on file
> with the assistant as of this session — §14/§15/§16 read verbatim)

What we are building and why. Module breakdown lives in
[MODULES.md](MODULES.md); per-task state in [TRACKER.md](TRACKER.md).

---

## 1. Product definition

| Question | Answer |
|---|---|
| What is it? | A business finance and expense management platform — not an expense tracker. Expenses, revenue, partner ownership, capital movements, budgets, profitability. |
| Tagline | Know Where Your Money Goes. |
| Who uses it? | Admin, Partner, Accountant, Data Entry — inside one company. |
| Most important journey | Record a day's expenses in seconds, and have the dashboard and P&L stay correct without reconciliation. |
| Currency | PKR (spec examples). Single currency in V1. |
| Success in 3 months | Daily entry habit sustained; month-end P&L and partner statements produced from the system rather than a spreadsheet. |
| Out of scope for V1 | Approval workflows, forecasting, invoicing, receivables/payables, payroll, tax reporting, inventory, multi-company. Recurring expenses shipped as M9 (`docs/TRACKER.md`) — no longer out of scope. |

### Branding (spec §2)

| Token | Value |
|---|---|
| Primary | `#0F172A` deep navy |
| Secondary | `#475569` slate |
| Accent | `#F59E0B` amber |
| Success | `#10B981` emerald |
| Danger | `#EF4444` red |
| Background | `#F8FAFC` |

The scaffold ships an indigo `brand-*` token set. Module 1 swaps those values in
`public/assets/css/app.src.css` — components consume tokens, so nothing else
changes.

## 2. Roles and permissions

**V1 ships two roles** (decided 2026-09-23). The spec's four are kept in the
enum so adding one later is data, not a schema migration.

| Role | V1 access | Status |
|---|---|---|
| Admin | Everything: configuration, users, all data entry, corrections, reports | **active** |
| Partner | Read financial data and own partner statement; can record/edit/void transactions (widened 2026-09-24); no master-data configuration, no profit distribution | **active** |
| Accountant | Transactions, accounts, budgets, financial reports | enum only, not assignable |
| Data Entry | Create transactions, view what they are permitted | enum only, not assignable |
| Employee | **No financial access.** Own read-only profile and the PDFs published to their branch (`/portal`). Created/managed only from the Employees screen, never the Users screen (added 2026-10-08) | **active**, not in `Access::ASSIGNABLE` |

**No `role_permissions` table in V1.** The spec asks for view/create/edit/
approve/export per module, which a role column cannot express - but with two
roles, where one can do everything and the other can only read, a permission
matrix is machinery with nothing to express yet. Simple role middleware covers
it. The table arrives with the third role, which is when it starts earning its
keep.

The employee role (2026-10-08) does not change that: it is a self-service role
with exactly two abilities (`canUseEmployeePortal()`,
`canReadEmployeeDocuments()`) and none of the finance/admin ones, which a role
check still expresses completely. `canViewFinancials()` was changed from "every
known role" to an explicit list in the same change, so a new role can never
inherit read access to the books by being added to `Access::ALL`.

**Confirmed 2026-09-23, revised 2026-09-24:** with no Data Entry role, partners
were initially read-only so only the admin recorded transactions. Widened
2026-09-24 at the owner's request: partners can now record, edit and void
transactions like admin/accountant/data-entry — `created_by` on every
transaction already gives per-user history, so this was a one-line policy
change rather than a migration.

Where this is enforced: `App\Services\Access::canWriteTransactions()`, which
returns true for `admin`, `partner`, `accountant` and `data_entry`, and is
asserted in `tests/Unit/AccessTest.php`. Master data
(`canManageMasterData()`) and profit distribution (`canDistributeProfit()`)
remain admin-only. Changing the policy further means changing the relevant
function and its test.

**Conflict to resolve:** the deployed `users.role` enum is
`admin / agent / customer` — from the generic scaffold, wrong for this product.
Module 1 migrates it. No production data exists yet, so this is cheap now and
expensive later.

## 3. Data model

Full rationale in [MODULES.md](MODULES.md). Shape:

**Ledger (source of truth)**
- `transactions` — every money movement, exactly once

**Satellites (type-specific detail, FK to ledger, never hold amounts)**
- `expenses`, `sales`, `partner_contributions`, `partner_withdrawals`,
  `profit_distributions`

**Master data**
- `users`, `role_permissions`, `partners`, `partner_shares` (effective-dated),
  `categories`, `accounts`, `customers`

**Supporting**
- `budgets`, `attachments`, `audit_log`, `rate_limits`, `migrations`, `settings`

**People (2026-10-08)**
- `employee_profiles` — one row per `users` row with role `employee` (PK
  `user_id`, cascade on user delete); phone, designation, joining date, and a
  copy of `branch_id`
- `employee_documents` — PDFs an admin publishes to every employee of their
  branch; generated `stored_filename`, display-only `original_filename`
- `employee_salaries` (2026-10-09) — append-only, effective-dated salary
  history per employee (`amount` DECIMAL(15,2), `effective_from`, `note`,
  `created_by`); the current salary is the latest row in effect today, a
  future-dated row is a scheduled change. Admin-only

Already migrated from the scaffold: `users`, `auth_tokens`, `rate_limits`,
`audit_log`, `migrations`.

### Rules that must hold

| Rule | Enforcement |
|---|---|
| Money is `DECIMAL(15,2)` | Schema. Never float. |
| Active partner shares total exactly 100% on any date | Service validation + test, blocks activation |
| Transfers affect no P&L line | Two ledger legs, type excluded from income/expense aggregates |
| Posted rows are voided, never deleted | `status` enum + mandatory reason + audit row |
| Every transaction records creator and time | `created_by`, `created_at` NOT NULL |
| Dashboard reconciles with transactions | Same table, same filters — by construction |
| Salary history is never rewritten | `App\Models\EmployeeSalary` has no update/delete path; a change or correction is a new effective-dated, audited row |

## 4. Milestones

| # | Milestone | Modules | Exit criteria |
|---|---|---|---|
| M0 | **Foundation infra** ✅ | scaffold, CI/CD, deploy | Live, verified, green preflight |
| M1 | **Auth & shell** | Module 1 | Roles migrated, permissions enforced, navigation in LedgerHive branding |
| M2 | **Master data** | Module 2 | Shares cannot save unless they total 100% |
| M3 | **Ledger** | Module 3 | Post + void work; 10k rows paginate; voids excluded from totals |
| M4 | **Daily use** | Modules 4, 5 | Expense entry under 15s; transfers and capital excluded from P&L |
| M5 | **Controls** | Modules 6, 7 | Budget vs actual matches hand-written SQL; allocations sum exactly to profit |
| M6 | **Visibility** | Modules 8, 9 | Every KPI reconciles; 12 reports export |
| M7 | **Documents & audit** | Modules 10, 11 | Receipts access-controlled; every write audited; security checklist ticked |

## 5. Architecture decisions

| Date | Decision | Why | Rejected |
|---|---|---|---|
| 2026-09-23 | Core PHP, no framework | Client requirement | Laravel |
| 2026-09-23 | Composer for dev tools only | Smaller supply-chain surface; app boots with empty `vendor/` | Shipping `vendor/` |
| 2026-09-23 | Single front controller + explicit routes | One web-reachable entry point | File-per-page |
| 2026-09-23 | App code outside the web root | Source and `.env` unreachable over HTTP | Everything in `public_html` |
| 2026-09-23 | Vendored front-end libs, no CDN | CSP stays `'self'` | CDN links |
| 2026-09-23 | Server-side pagination, no DataTables | Scales; keeps authorisation server-side | DataTables + jQuery |
| 2026-09-23 | Deploy over SSH (rsync) to Hostinger | Per-site FTP is chrooted to `public_html` | FTPS |
| 2026-09-23 | CSP set in `.htaccess` as well as PHP | Host overrides PHP's header | PHP only |
| 2026-09-23 | **One ledger, satellite detail tables** | Makes "dashboard reconciles with ledger" structural rather than a discipline problem | Parallel `sales`/`expenses` tables each holding amounts |
| 2026-09-23 | **Transfers as two legs** | Balance stays a plain `SUM()`; direction bugs become impossible | Single row with from/to accounts |
| 2026-09-23 | **Balances derived, not stored** | A stored balance is a second truth that drifts | Running-balance column |
| 2026-09-23 | **Effective-dated `partner_shares`** | Profit distribution needs the split *as of* a period | Percentage column on `partners` |
| 2026-09-23 | **Service layer** added to the scaffold | Financial rules (posting, validation, distribution) belong in one testable place | Logic in controllers/models |
| 2026-09-23 | **Cash basis**: only `received` income counts toward profit | Profit never shows money that has not arrived; matches how an owner-managed business reads the dashboard. Pending sales are tracked and reported as expected income, not revenue | Accrual (implies receivables, deferred to V2); showing both (doubles every report) |
| 2026-09-23 | **Void by status flag**, not reversing entry | Simple, readable, history intact. Risk mitigated by a central query builder | Reversing entries: correct accounting, but two extra rows for a typo fix, and V1 has no period-close concept |
| 2026-09-23 | **Attachments in `storage/documents`**, streamed by an authenticated controller | Financial documents must not be readable by anyone holding a URL | `public/uploads` with random filenames |
| 2026-09-23 | **Vendor/payee as free text**, not a managed table | Keeps expense entry fast, which is the spec's stated priority (§20.3). Accepted cost: vendor-level reporting is only as good as the typing, so the field type-aheads from previously used values to keep spellings converging | A `vendors` table with CRUD |
| 2026-09-23 | **Rebrand app to LedgerHive; infrastructure names unchanged** | Zero risk, no downtime. Repo `supporthive`, DB `u400948127_supporthive`, site `myinvoicestudio.com` keep their names | Renaming repo/DB/domain |
| 2026-09-24 | **Partner role can write transactions** (record/edit/void), widened from read-only, at owner's request | Owner wants partners entering their own day-to-day transactions, not only admin | Keeping partners read-only |
| 2026-09-24 | **dompdf for PDF export (M6-7)** — the first and only runtime Composer dependency V1 takes on | Pure-PHP (no native extensions to keep working on the host), renders the same HTML/CSS the report views already use rather than a second hand-built layout per report, MIT-licensed, widely deployed | mPDF (similar weight, no reuse advantage for this app's Latin-only reports); TCPDF (lower-level cell/text API — report layouts would have to be hand-built, not reused from the views) |
| 2026-09-25 | **Multi-branch: one shared database, `branch_id` on every table**, not a database per branch | No extra hosting privileges needed (`CREATE DATABASE` isn't available on the current plan), one migration/backup story, and the app already had a central choke point (`LedgerQuery`, now also `App\Core\Model`) to enforce a mandatory scope the same way `status='posted'` already is | Database-per-branch: stronger isolation in principle, but real operational cost at this scale — per-branch migrations, per-branch backups, a provisioning step instead of an INSERT |
| 2026-09-25 | **Super admin is a role (`users.role='super_admin'`, `branch_id` NULL), not a separate account type or table** | Fits the existing `Access::` pure-function-of-role pattern with zero signature changes; not in `Access::ASSIGNABLE` since there is no in-app user-management UI to assign it from yet — granted by direct DB action only, deliberately, so it can never be self-escalated to | A `is_super_admin` boolean flag on top of a normal role — rejected as a second, overlapping privilege axis for a role system that already exists |
| 2026-09-25 | **Claude AI integration via raw curl (`App\Services\AnthropicClient`), no Composer SDK** | Keeps the zero-runtime-dependency stance for everything except the already-accepted `dompdf` exception; the Anthropic API surface used here (messages + tool use) is small enough that a hand-rolled client is less risk than a new supply-chain dependency, and it stays consistent with `Upload`/`Http` already being raw-PHP wrappers over simple protocols | `anthropic-sdk-php` (or an equivalent HTTP client package): more surface than needed, one more `vendor/` package to keep patched |
| 2026-09-25 | **AI features are optional and per-branch gated, off by default** | Each branch supplies its own Anthropic API key (`App\Core\Crypto`, AES-256-GCM, encrypted at rest under `APP_KEY`) via `/settings/ai`; `AiAvailability` checks both "enabled" and "key present" before either feature is reachable | A single global API key shared across all branches — rejected: no per-branch cost attribution, and one branch's usage would exhaust another's budget |
| 2026-10-08 | **Employee HR fields in a separate `employee_profiles` table, carrying its own copy of `branch_id`** | `users` stays the same shape for every role; every employee query filters on the profile table's own `branch_id` like every other branch-scoped table, and `findWithUser()` checks id + role + branch in one query as the single ownership gate | Nullable phone/designation/joining-date columns on `users` |
| 2026-10-08 | **Employee documents stored privately and streamed with auth** (decision D-4 extended), previewed in a new top-level tab | Same "nothing readable by holding a URL" rule as receipts; a new tab keeps the CSP's `frame-ancestors 'none'`/`object-src 'none'` intact | `public/uploads`; an embedded viewer (would need the frame/object policy loosened) |
| 2026-10-08 | **PDF only, 10 MB default** (`UPLOAD_PDF_MAX_BYTES`), `%PDF-` header required at offset 0 | Handbooks and policies are PDFs; one type keeps preview behaviour predictable and the upload surface small | Images/Office documents |
| 2026-10-08 | **Document visibility is per branch** — "all employees" means all employees of the uploading admin's branch | Every table is branch-scoped; a cross-branch publish would be the first exception to that rule | Global documents visible to every branch |
| 2026-10-09 | **Employee salary: append-only, effective-dated history, admin-only** (`employee_salaries`, EMP-9). Every change is a new row with an effective date, a note and who recorded it, audited as `employee.salary_changed`; "current" is derived (latest `effective_from` on or before today, ties by id), so a raise can be scheduled ahead. Only admins see it: it lives in its own model and its own card on the admin employee page, never in the shared `partials/employee-profile.php`, the portal, `PortalController` or `EmployeeProfile::findWithUser()`; partners do not see it either. Salary changes only through `POST /employees/{id}/salary`, never the general edit form; an optional starting salary is written with the employee in the same transaction | History and payroll questions ("what were they paid in March?") need every past value with its date; a derived current value cannot drift from the history; keeping salary out of every shared or employee-facing read path makes "the employee never sees it" structural rather than a view-level `if` | A `salary` column on `employee_profiles` overwritten on edit (loses history, and the profile row is what the portal reads); editable/deletable history rows; showing the employee their own salary |

## 6. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Ledger shape changes after modules are built | Rewrite every aggregate | M3 is the critical path; settle it before M4+ |
| Floating-point money | Silent, compounding errors | `DECIMAL(15,2)` in schema, reviewed in every migration |
| Rounding in profit distribution | Allocations don't sum to profit | Deterministic remainder assignment, unit-tested |
| Share history mishandled | Wrong partner paid the wrong amount | Effective-dated table + tests for mid-period changes |
| Receipts readable by URL guess | Financial data disclosure | Serve from `storage/documents` via authenticated controller |
| Production `DB_PASS` secret not updated after rotation | Live DB unreachable | Open item: update secret, redeploy |
| Single developer, no review | Defects reach production | CI gates + `main` protection (not yet enabled) |
| An employee document larger than PHP's `post_max_size` | PHP drops the whole request body, CSRF token included, so the admin sees a 419 instead of "file too large" | `scripts/preflight.php` fails when `upload_max_filesize`/`post_max_size` are below `UPLOAD_PDF_MAX_BYTES`; the upload form states the limit |
| A suspended/deactivated user kept a session already open | Access continued until the session expired | **Closed 2026-10-08:** `Auth::requireLogin()` re-reads status every request and signs the account out |

## 7. Open questions

Raised 2026-09-23, shaping V1:

- [ ] Expected transaction volume per month (drives indexing and caching)
- [ ] Staging site before production deploys?

Answered:
- [x] Vendors - **free text**, with type-ahead from prior values
- [x] Product naming - rebrand app to LedgerHive, leave infra names alone
- [x] Accounting basis - **cash basis**, only `received` income counts
- [x] Corrections - **status-flag void**, excluded from aggregates
- [x] Attachments - `storage/documents`, authenticated controller
- [x] PHP version on host — 8.3.30 web, 8.2.30 CLI
- [x] Domain and HTTPS — provisioned
- [x] Currency — PKR, single currency in V1
- [x] Export libraries — **dompdf** for PDF (native `fputcsv` covers CSV, no dependency)
