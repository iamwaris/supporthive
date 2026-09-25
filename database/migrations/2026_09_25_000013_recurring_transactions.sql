-- M9: recurring transactions — rules that generate draft income/expense
-- entries on a schedule (daily/weekly/monthly/quarterly/yearly), plus the
-- approval queue those drafts sit in until a human accepts or rejects them.
--
-- Two tables, not one, and drafts are never `transactions.status='pending'`:
--
--   * `pending` on `transactions` already means something specific — money
--     invoiced but not yet received, the state cash-basis reporting relies on
--     (see M4, expense_income_detail). A generated-but-unreviewed recurring
--     draft is a different concept entirely (it may not even be approved,
--     let alone invoiced); overloading the same status value would corrupt
--     every report that filters on it.
--   * A generated draft is not a row in the ledger at all until it is
--     approved — `transactions` records money that moved (or was invoiced),
--     and an un-reviewed draft is neither. Keeping drafts in their own table
--     means the cron job that generates them touches no ledger data and
--     needs no Auth/session context to run; it only starts mattering once a
--     human approves a row, and that approval is the ordinary authenticated,
--     CSRF-checked write that creates the real `transactions` row.
--
-- `recurring_rules` is the schedule; `recurring_occurrences` is the queue of
-- drafts a rule has produced. `last_generated_date` on the rule is the
-- catch-up cursor the generator advances after each run; the UNIQUE key on
-- occurrences is the hard backstop that makes re-running the generator safe
-- even if that cursor is ever wrong.

-- ---------------------------------------------------------------------------
-- recurring_rules — one row per recurring schedule. Detail fields
-- (account/category/vendor/customer/reference/notes) mirror the entry form
-- for a one-off income/expense transaction, because each occurrence is
-- generated from exactly these values.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recurring_rules (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id            BIGINT UNSIGNED NOT NULL,
    type                 ENUM('income','expense') NOT NULL,
    description          VARCHAR(255)    NOT NULL,
    amount               DECIMAL(15,2)   NOT NULL,
    account_id           BIGINT UNSIGNED NOT NULL,
    category_id          BIGINT UNSIGNED NOT NULL,
    vendor               VARCHAR(160)    NULL,
    customer_id          BIGINT UNSIGNED NULL,
    reference_no         VARCHAR(80)     NULL,
    notes                TEXT            NULL,
    frequency            ENUM('daily','weekly','monthly','quarterly','yearly') NOT NULL,
    -- Exactly one of the two is populated, and which one depends on
    -- `frequency` — enforced below by chk_recurring_rule_schedule.
    day_of_month         TINYINT UNSIGNED NULL,
    day_of_week          TINYINT UNSIGNED NULL,
    start_date           DATE            NOT NULL,
    end_date             DATE            NULL,
    is_active            TINYINT(1)      NOT NULL DEFAULT 1,
    -- The idempotency cursor for the catch-up generator: the occurrence_date
    -- of the most recently generated draft, so a run only has to generate
    -- occurrences strictly after this date. When a paused rule
    -- (is_active = 0) is reactivated, this is set to the resume date so
    -- nothing is backfilled for the paused window. NULL means the rule has
    -- never generated a draft yet.
    last_generated_date  DATE            NULL,
    created_by           BIGINT UNSIGNED NOT NULL,
    created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- The generator's own scan ("every active rule for this branch due to
    -- run") and the rule-list screen both hit exactly this prefix.
    KEY idx_recurring_rule_branch_active (branch_id, is_active),

    CONSTRAINT fk_recurring_rule_branch   FOREIGN KEY (branch_id)    REFERENCES branches   (id),
    CONSTRAINT fk_recurring_rule_account  FOREIGN KEY (account_id)  REFERENCES accounts   (id),
    CONSTRAINT fk_recurring_rule_category FOREIGN KEY (category_id) REFERENCES categories (id),
    CONSTRAINT fk_recurring_rule_customer FOREIGN KEY (customer_id) REFERENCES customers  (id),
    CONSTRAINT fk_recurring_rule_creator  FOREIGN KEY (created_by)  REFERENCES users      (id),

    CONSTRAINT chk_recurring_rule_amount CHECK (amount > 0),
    CONSTRAINT chk_recurring_rule_day_of_month CHECK (day_of_month IS NULL OR day_of_month BETWEEN 1 AND 31),
    CONSTRAINT chk_recurring_rule_day_of_week  CHECK (day_of_week  IS NULL OR day_of_week  BETWEEN 0 AND 6),
    -- Daily needs neither day field; weekly needs day_of_week only;
    -- monthly/quarterly/yearly need day_of_month only.
    CONSTRAINT chk_recurring_rule_schedule CHECK (
        (frequency = 'daily'   AND day_of_month IS NULL     AND day_of_week IS NULL)
        OR (frequency = 'weekly'  AND day_of_week IS NOT NULL  AND day_of_month IS NULL)
        OR (frequency IN ('monthly','quarterly','yearly')
            AND day_of_month IS NOT NULL AND day_of_week IS NULL)
    ),
    -- vendor is free text for expenses only; customer_id is a real FK for
    -- income only — the same split `transactions`/expense_income_detail
    -- already uses for one-off entries (decision D-5, M4).
    CONSTRAINT chk_recurring_rule_detail CHECK (
        (type = 'expense' AND customer_id IS NULL)
        OR (type = 'income' AND vendor IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- recurring_occurrences — one row per draft the generator has produced,
-- sitting in the approval queue until a human approves or rejects it.
--
-- The entry fields here (type/amount/account_id/category_id/description/
-- vendor/customer_id/reference_no/notes) are a SNAPSHOT of the owning rule
-- at generation time, copied rather than joined live: if the rule is edited
-- after a draft is generated, the draft already sitting in the review queue
-- must keep showing what was actually scheduled, not be silently rewritten
-- by the edit. Approving a row copies this snapshot into a new
-- `transactions` row.
--
-- branch_id is denormalised from the rule on purpose: the approval queue
-- (list pending drafts for this branch) is read standalone and should never
-- have to join back to recurring_rules just to stay branch-scoped.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recurring_occurrences (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_id          BIGINT UNSIGNED NOT NULL,
    branch_id        BIGINT UNSIGNED NOT NULL,
    occurrence_date  DATE            NOT NULL,

    -- Snapshot of the rule's entry fields at generation time — see comment
    -- above. occurrence_date becomes transactions.transaction_date on
    -- approval.
    type             ENUM('income','expense') NOT NULL,
    amount           DECIMAL(15,2)   NOT NULL,
    account_id       BIGINT UNSIGNED NOT NULL,
    category_id      BIGINT UNSIGNED NOT NULL,
    description      VARCHAR(255)    NOT NULL,
    vendor           VARCHAR(160)    NULL,
    customer_id      BIGINT UNSIGNED NULL,
    reference_no     VARCHAR(80)     NULL,
    notes            TEXT            NULL,

    status           ENUM('pending_review','approved','rejected') NOT NULL DEFAULT 'pending_review',
    -- Set on approval, once the snapshot above has been copied into a real
    -- ledger row.
    transaction_id   BIGINT UNSIGNED NULL,
    reviewed_by      BIGINT UNSIGNED NULL,
    reviewed_at      DATETIME        NULL,
    reject_reason    VARCHAR(255)    NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- The hard idempotency backstop: even if last_generated_date is ever
    -- wrong (a bug, a manual DB edit, a re-run after a crash mid-batch), the
    -- generator can never insert the same rule/date twice — the second
    -- attempt fails on this key instead of double-drafting.
    UNIQUE KEY uq_occurrence_rule_date (rule_id, occurrence_date),

    -- The approval queue's own read: "pending (or any status) drafts for
    -- this branch, oldest occurrence first" — the screen that lists what a
    -- reviewer has to act on.
    KEY idx_occurrence_branch_status (branch_id, status, occurrence_date),

    CONSTRAINT fk_occurrence_rule        FOREIGN KEY (rule_id)        REFERENCES recurring_rules (id),
    CONSTRAINT fk_occurrence_branch      FOREIGN KEY (branch_id)      REFERENCES branches        (id),
    CONSTRAINT fk_occurrence_account     FOREIGN KEY (account_id)     REFERENCES accounts        (id),
    CONSTRAINT fk_occurrence_category    FOREIGN KEY (category_id)    REFERENCES categories      (id),
    CONSTRAINT fk_occurrence_customer    FOREIGN KEY (customer_id)    REFERENCES customers       (id),
    CONSTRAINT fk_occurrence_transaction
        FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE SET NULL,
    CONSTRAINT fk_occurrence_reviewer
        FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,

    CONSTRAINT chk_occurrence_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
