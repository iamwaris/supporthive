-- AR/AP-1: a receivables / payables register (decision 2026-10-17,
-- docs/PLAN.md §5).
--
-- Deliberately independent of the ledger: nothing here references
-- `transactions`, and recording, editing, settling or cancelling an entry
-- never posts, voids or changes a ledger row, an account balance, the P&L,
-- a budget or a report. It is a tracking register of who owes the business
-- money (kind = 'receivable') and whom the business owes (kind = 'payable').
--
-- The counterparty is free text on both sides (customer name or vendor
-- name), the same trade-off as decision D-5 for expense vendors: no FK to
-- `customers`, no vendors table.
--
-- Settlement is a single action for the full amount (status 'open' ->
-- 'settled', with settled_on); there are no partial payments. Cancelling is
-- a status flag with a mandatory reason, like a transaction void — a row is
-- never deleted by the app. "Overdue" is computed (open AND due_date before
-- today), never stored, so it can never go stale.
--
-- amount is DECIMAL(15,2) like every other money column (docs/PLAN.md §3).
--
-- created_by is not cascaded: BranchController::destroy deletes a branch's
-- rows here before its users, as it does for employee_salaries.
--
-- Safe to re-run: CREATE ... IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS receivables_payables (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id       BIGINT UNSIGNED NOT NULL,
    kind            ENUM('receivable','payable') NOT NULL,
    party_name      VARCHAR(160)    NOT NULL,
    reference_no    VARCHAR(80)     NULL,
    description     VARCHAR(255)    NULL,
    amount          DECIMAL(15,2)   NOT NULL,
    issue_date      DATE            NOT NULL,
    due_date        DATE            NULL,
    status          ENUM('open','settled','cancelled') NOT NULL DEFAULT 'open',
    settled_on      DATE            NULL,
    settlement_note VARCHAR(255)    NULL,
    settled_by      BIGINT UNSIGNED NULL,
    settled_at      DATETIME        NULL,
    cancel_reason   VARCHAR(255)    NULL,
    cancelled_by    BIGINT UNSIGNED NULL,
    cancelled_at    DATETIME        NULL,
    created_by      BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modified_by     BIGINT UNSIGNED NULL,
    modified_at     DATETIME        NULL,

    PRIMARY KEY (id),
    -- The list page and its summary cards always ask "this branch, this
    -- kind, this status", and overdue adds a due_date range on top.
    KEY idx_rp_branch_kind_status_due (branch_id, kind, status, due_date),
    -- The party-name search within one branch and kind.
    KEY idx_rp_branch_kind_party (branch_id, kind, party_name),

    CONSTRAINT fk_rp_branch    FOREIGN KEY (branch_id)    REFERENCES branches (id),
    CONSTRAINT fk_rp_creator   FOREIGN KEY (created_by)   REFERENCES users (id),
    CONSTRAINT fk_rp_settler   FOREIGN KEY (settled_by)   REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_rp_canceller FOREIGN KEY (cancelled_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_rp_modifier  FOREIGN KEY (modified_by)  REFERENCES users (id) ON DELETE SET NULL,

    CONSTRAINT chk_rp_amount CHECK (amount > 0),
    CONSTRAINT chk_rp_due_date CHECK (due_date IS NULL OR due_date >= issue_date),
    -- A settled entry must say when; the date can never precede the issue.
    CONSTRAINT chk_rp_settled_on CHECK (status <> 'settled' OR settled_on IS NOT NULL),
    CONSTRAINT chk_rp_settled_after_issue CHECK (settled_on IS NULL OR settled_on >= issue_date),
    -- A cancellation must say why, exactly like a transaction void.
    CONSTRAINT chk_rp_cancel_reason CHECK (
        status <> 'cancelled' OR (cancel_reason IS NOT NULL AND cancel_reason <> '')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
