-- M5: budgets and profit distribution.
--
-- Two independent controls, bundled in one migration because both are small
-- and both landed in the same milestone.

-- ---------------------------------------------------------------------------
-- Budgets. One row per (year, month, expense category) — the unique key is
-- the whole point: it is what stops "Salaries" getting a second, conflicting
-- budget for the same month.
--
-- Budgets are never here for `budgets vs actual`. Actual spend is read live
-- from `transactions` at request time (LedgerQuery, same as every other
-- aggregate) — a stored "spent" figure would drift the moment something is
-- voided or backdated.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS budgets (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    year                 SMALLINT UNSIGNED NOT NULL,
    month                TINYINT UNSIGNED NOT NULL,
    category_id          BIGINT UNSIGNED NOT NULL,
    amount               DECIMAL(15,2) NOT NULL,
    alert_threshold_pct  TINYINT UNSIGNED NOT NULL DEFAULT 80,
    created_by           BIGINT UNSIGNED NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_budget_period_category (year, month, category_id),
    KEY idx_budget_period (year, month),
    CONSTRAINT fk_budget_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE,
    CONSTRAINT fk_budget_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_budget_month CHECK (month BETWEEN 1 AND 12),
    CONSTRAINT chk_budget_amount CHECK (amount > 0),
    CONSTRAINT chk_budget_threshold CHECK (alert_threshold_pct BETWEEN 1 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Profit distribution. One row per partner per distribution run.
--
-- A "run" is the set of rows sharing a batch_id — bin2hex(random_bytes(16)),
-- the same scheme `transactions.transfer_group` already uses to link rows
-- that must be treated as one unit. There is no separate header table: every
-- row in a batch carries the same period and moves through the same states
-- together, so nothing is lost by keeping it flat.
--
-- calculated_amount and distributed_amount are both stored and are allowed to
-- differ in principle (spec §11) even though V1's flow always distributes
-- exactly what was calculated — the column exists so a future "adjust before
-- paying out" step is a UPDATE, not a schema change.
--
-- share_bp is copied from partner_shares at calculation time (not joined live)
-- for the same reason partner_shares itself is effective-dated: a later
-- ownership change must not rewrite the basis of a run already calculated.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS profit_distributions (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id            CHAR(32)     NOT NULL,
    period_start        DATE         NOT NULL,
    period_end          DATE         NOT NULL,
    partner_id          BIGINT UNSIGNED NOT NULL,
    share_bp            INT UNSIGNED NOT NULL,
    calculated_amount   DECIMAL(15,2) NOT NULL,
    distributed_amount  DECIMAL(15,2) NULL,
    status              ENUM('calculated','approved','distributed') NOT NULL DEFAULT 'calculated',
    account_id          BIGINT UNSIGNED NULL,
    transaction_id      BIGINT UNSIGNED NULL,
    approved_by         BIGINT UNSIGNED NULL,
    approved_at         DATETIME NULL,
    created_by          BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_distribution_batch_partner (batch_id, partner_id),
    KEY idx_distribution_period (period_start, period_end),
    KEY idx_distribution_status (status),
    CONSTRAINT fk_distribution_partner FOREIGN KEY (partner_id) REFERENCES partners (id),
    CONSTRAINT fk_distribution_account FOREIGN KEY (account_id) REFERENCES accounts (id),
    CONSTRAINT fk_distribution_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE SET NULL,
    CONSTRAINT fk_distribution_approver FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_distribution_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_distribution_calculated CHECK (calculated_amount >= 0),
    CONSTRAINT chk_distribution_share CHECK (share_bp > 0 AND share_bp <= 1000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
