# LedgerHive — Module Plan

Build order, dependencies, and what "done" means for each module. Derived from
the V1.0 product specification. Read alongside [PLAN.md](PLAN.md) (why) and
[TRACKER.md](TRACKER.md) (current state).

---

## The architectural decision everything else depends on

The spec asks for a "central transaction engine" and separately lists
`transactions`, `sales`, `partner_contributions`, `partner_withdrawals` as
tables. Those two ideas conflict unless the relationship is made explicit, and
getting it wrong is the single most expensive mistake available here — it
produces a system where the dashboard and the ledger disagree and nobody can
say which is right.

**One ledger, many detail tables.**

```
                    ┌─────────────────────────┐
                    │      transactions       │  ← the only source of money truth
                    │  (every movement, once) │
                    └────────────┬────────────┘
                                 │ 1:1 transaction_id
        ┌───────────┬────────────┼────────────┬──────────────┐
        │           │            │            │              │
   expenses      sales    contributions  withdrawals  distributions
   (vendor,   (customer,   (partner,      (partner,     (period,
    receipt)   invoice)     capital)       reason)       share %)
```

- `transactions` holds date, type, amount, account, category, status, created_by.
  **Every** balance, dashboard KPI, P&L line and report aggregates this table
  and nothing else.
- Satellite tables hold only type-specific detail and a FK back to the ledger
  row. They never hold an amount — duplicating the amount is how the two drift
  apart.
- Nothing writes to `transactions` directly. Everything goes through
  `TransactionService::post()`, which validates, writes the ledger row, writes
  the satellite row, and writes the audit entry in one database transaction.

This satisfies the spec's acceptance criterion "Dashboard totals reconcile with
transactions" **by construction** rather than by discipline.

### Supporting decisions

| Decision | Choice | Why |
|---|---|---|
| Money storage | `DECIMAL(15,2)`, never `FLOAT`/`DOUBLE` | Binary floats cannot represent 0.10 exactly; errors compound across aggregation. Non-negotiable in financial software. |
| Transfers | **Two ledger legs** (`TRANSFER_OUT` + `TRANSFER_IN`) sharing a `transfer_group_id` | Account balance stays a plain `SUM()` over legs. A single row with two account columns forces every balance query to branch on direction, and someone eventually forgets. Both legs excluded from P&L, so transfers cannot inflate income or expenses. |
| Account balance | **Derived**, not stored: `opening_balance + SUM(signed amounts)` | A stored balance is a second source of truth that silently goes wrong. Add a cached aggregate only if measurement shows it is needed. |
| Signed amounts | Store `amount` positive + a `direction` (`+1`/`-1`) resolved from type | Keeps "show me the amount" trivial while making `SUM(amount * direction)` correct. |
| Partner shares | Separate `partner_shares` table with `effective_from` / `effective_to` | The spec requires effective dates. A percentage column on `partners` cannot answer "what was the split in March?" — which is exactly what profit distribution needs. |
| Corrections | `status` = `posted` / `void`, excluded from every aggregate, plus audit row | Decided 2026-09-23. Simple, readable, history intact. **Risk:** any aggregate that forgets `WHERE status = 'posted'` silently counts voided rows. Mitigated by routing every read through one query builder that applies it - a hand-written `SUM()` is a review failure. |
| Profit basis | **Cash**: only `sales.payment_status = 'received'` is revenue | Decided 2026-09-23. Profit never shows money that has not arrived. Pending sales get their own expected-income report. |
| Category type | Categories are typed (`expense` / `income`) | Stops an income category being selected on an expense form. |

---

## Module 1 — Foundation *(partially built)*

**Status:** auth primitives, routing, sessions, CSRF, rate limiting, audit table
and deploy pipeline already exist from the scaffold.

**Remaining:**

| Task | Notes |
|---|---|
| Replace `users.role` enum | Currently `admin/agent/customer` — wrong product. Needs `admin/partner/accountant/data_entry`. |
| Permission layer | Spec wants view/create/edit/approve/export **per module**. A four-value role enum cannot express that. Add `role_permissions` (role × module × ability). |
| Login / logout screens | `AuthController`, rate-limited, audited. |
| Base layout | Sidebar navigation per spec §20.1, LedgerHive palette, Lucide icons. |
| Settings scaffold | Company name, currency, fiscal year start, approval threshold. |

**Done when:** an admin can log in, a data-entry user cannot reach `/users`, and
every login attempt appears in the audit log.

---

## Module 2 — Master Data

Everything transactions depend on. Must exist before any money can be recorded.

| Entity | Key points |
|---|---|
| **Partners** | CRUD + activate/deactivate. Shares live in `partner_shares`, not here. |
| **Partner shares** | Effective-dated. **Validation: active shares on any given date must total exactly 100.00%** — enforced in a service, tested, and blocking activation. This is a V1 acceptance criterion. |
| **Categories** | Self-referencing parent for subcategories, typed expense/income, seeded with the spec's 12 starter categories. |
| **Accounts** | Cash / bank / credit card / petty cash / other, with `opening_balance`. |
| **Customers** | Referenced by sales. Light: name, contact, notes. |
| **Vendors** | Spec mentions vendor/payee on expenses as free text. Recommend a table for consistent reporting — see open questions. |

**Done when:** shares cannot be saved in a state that doesn't total 100%, and
the category tree renders correctly nested.

---

## Module 3 — Transaction Engine

The core. No UI of its own beyond "All Transactions".

- `TransactionService` — `post()`, `void()`, `list()` with server-side pagination.
- Ledger table + indexes on `(transaction_date)`, `(category_id)`,
  `(account_id)`, `(type, status)`, `(created_by)`.
- All Transactions screen with the full filter set from spec §17.
- Void flow: requires a reason, writes audit, never deletes.

**Done when:** a transaction can be posted and voided, voided rows vanish from
totals but remain visible with their reason, and 10,000 rows paginate without
loading them all.

---

## Module 4 — Expenses & Income

The daily-use screens, built on Module 3.

| Module | Fields (spec §8, §9) |
|---|---|
| Expenses | date, category/sub, description, amount, payment account, vendor, reference, receipt, entered by, notes, status |
| Income | date, customer, description, revenue category, amount, account, invoice ref, received/pending, entered by, notes, attachment |

Spec §20.3 calls for fast repeated entry: date defaults to today, last-used
account remembered, keyboard-navigable, save-and-add-another.

**Done when:** an expense takes under 15 seconds to record, and transfers
between accounts appear in neither the expense nor the income totals.

---

## Module 5 — Partner Capital

Contributions and withdrawals. Thin modules over the engine — their entire
purpose is that they are **not** income and **not** expenses.

**Done when:** a PKR 500,000 contribution raises the bank balance, changes the
P&L by zero, and appears on the partner statement.

---

## Module 6 — Budgets

Monthly budget per category. Budget vs actual reads live from the ledger.

- `budgets` unique on `(year, month, category_id)`.
- Utilization = actual ÷ budget × 100; remaining = budget − actual.
- Alerts at a configurable threshold (default 80%) and on exceeding.

**Done when:** budget-vs-actual for a month equals a hand-written `SUM()` over
the same period.

---

## Module 7 — Profit Distribution

The most error-prone module — it is the one that decides who gets paid.

1. Pick a period → compute distributable profit from the ledger.
2. Resolve each partner's share **as of that period** from `partner_shares`.
3. Write `calculated_amount` per partner.
4. Approve → distribute → posts `PROFIT_DISTRIBUTION` ledger rows for
   `distributed_amount`.

Calculated ≠ distributed; both are stored, per spec §11.

**Done when:** allocations sum exactly to distributable profit (rounding
remainder assigned deterministically, not dropped), and a mid-period share
change produces the correct split.

---

## Module 8 — Dashboard

12 widgets per spec §14, every number a SQL aggregate — never a PHP loop over
rows. ApexCharts fed by a JSON endpoint, never inline `<script>` data (the CSP
forbids it, and it is an XSS vector).

**Done when:** the dashboard issues a bounded number of queries regardless of
transaction volume, and every KPI reconciles with All Transactions filtered the
same way.

---

## Module 9 — Reports & Exports

Twelve reports (spec §16), each with date range + filters, CSV and PDF export.

Export libraries are the **only** runtime Composer dependencies the project
would acquire — see open questions.

---

## Module 10 — Attachments

Receipts, invoices, bills, payment proofs linked to transactions.

**Decided 2026-09-23:** files live in `storage/documents/`, outside the web root
entirely, streamed by a controller that checks the session *and* that the user
may see the owning transaction. Nothing is readable by holding a URL.

Two consequences for the code:
- `Upload::store()` needs a variant writing outside `PUBLIC_PATH` (it currently
  hardcodes `PUBLIC_PATH . '/uploads'`).
- `DocumentController@show` resolves an attachment **id** to a file path via the
  database. A user-supplied path must never reach the filesystem - that is how
  path traversal reads `.env`.

---

## Module 11 — Audit & Hardening

Audit table exists; this module wires it to every write, adds the approval
threshold, and runs the pre-launch checklist in [SECURITY.md](SECURITY.md).

---

## Dependency order

```
M1 Foundation
  └─> M2 Master Data
        └─> M3 Transaction Engine
              ├─> M4 Expenses & Income
              ├─> M5 Partner Capital
              ├─> M6 Budgets
              └─> M7 Profit Distribution  (needs M2 shares + M3 ledger)
                    └─> M8 Dashboard  (needs M4, M6)
                          └─> M9 Reports
M10 Attachments  — any time after M4
M11 Audit        — continuous, verified last
```

M3 is the critical path. Nothing downstream is safe to build until the ledger
shape is settled, because changing it later means rewriting every aggregate.
