# LedgerHive — Development Plan

> Status: **M1–M4 live in production** (auth, master data, the ledger,
> daily entry). **M5 — budgets and profit distribution — built and tested,
> pending PR/merge.** Next after that: M6 — visibility.
> Owner: Muhammad Waris · Last updated: 2026-09-24
> Source of truth for product scope: `LedgerHive_App.pdf` V1.0

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
| Out of scope for V1 | Recurring expenses, approval workflows, forecasting, invoicing, receivables/payables, payroll, tax reporting, inventory, multi-company. |

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
| Partner | Read financial data and own partner statement; no data entry, no configuration | **active** |
| Accountant | Transactions, accounts, budgets, financial reports | enum only, not assignable |
| Data Entry | Create transactions, view what they are permitted | enum only, not assignable |

**No `role_permissions` table in V1.** The spec asks for view/create/edit/
approve/export per module, which a role column cannot express - but with two
roles, where one can do everything and the other can only read, a permission
matrix is machinery with nothing to express yet. Simple role middleware covers
it. The table arrives with the third role, which is when it starts earning its
keep.

**Confirmed 2026-09-23:** with no Data Entry role, the admin does the daily
expense entry and partners are read-only. Revisiting this later means a
migration once transactions carry a `created_by` history, so it is recorded
rather than left as an assumption.

Where this is enforced: `App\Services\Access::canWriteTransactions()`, which
returns false for `partner` and is asserted in `tests/Unit/AccessTest.php`.
Changing the policy means changing that one function and its test.

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

## 7. Open questions

Raised 2026-09-23, shaping V1:

- [ ] Export libraries — the only runtime dependencies V1 would take on
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
