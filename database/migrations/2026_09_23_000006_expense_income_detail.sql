-- M4: expense and income detail, plus a status for money not yet received.
--
-- ============================================================================
-- Why 'pending' is a ledger status rather than a separate table
-- ============================================================================
-- Decision D-2 put the app on a cash basis: only money actually received is
-- revenue. A pending invoice therefore must not touch the bank balance or the
-- profit figure, but it still has to be visible as expected income.
--
-- Two ways to model that:
--
--   (a) Keep pending invoices out of the ledger, in their own table with
--       their own amount column. That reintroduces the exact problem the
--       single-ledger design exists to prevent - an amount living in two
--       places, free to disagree.
--
--   (b) Put it in the ledger with status = 'pending'.
--
-- (b) wins, and cheaply: LedgerQuery::posted() already filters on
-- status = 'posted', so a pending row is excluded from every balance, every
-- P&L line and every dashboard tile the moment it exists, with no new rule to
-- remember. Marking it received is a status change on the row that already
-- holds the amount, not a copy between tables.
--
-- Pending applies to income only. An unpaid EXPENSE is a payable, which the
-- specification defers to V2 (section 4.2), so expenses post immediately.
-- ============================================================================

ALTER TABLE transactions
    MODIFY COLUMN status ENUM('posted','pending','void') NOT NULL DEFAULT 'posted';

-- Date the money actually arrived, which under a cash basis is the date that
-- matters for reporting. transaction_date stays the invoice date.
ALTER TABLE transactions
    ADD COLUMN received_at DATE NULL AFTER status;

-- ---------------------------------------------------------------------------
-- Expense detail. No amount column: the ledger row holds it.
-- vendor is free text by decision D-5 - fast to type, with a type-ahead over
-- previous values so spellings converge instead of drifting.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS expenses (
    transaction_id BIGINT UNSIGNED NOT NULL,
    vendor         VARCHAR(160) NULL,
    notes          TEXT         NULL,
    PRIMARY KEY (transaction_id),
    KEY idx_expenses_vendor (vendor),
    CONSTRAINT fk_expenses_transaction
        FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Income detail. Again no amount column.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sales (
    transaction_id BIGINT UNSIGNED NOT NULL,
    customer_id    BIGINT UNSIGNED NULL,
    invoice_no     VARCHAR(80)  NULL,
    notes          TEXT         NULL,
    PRIMARY KEY (transaction_id),
    KEY idx_sales_customer (customer_id),
    KEY idx_sales_invoice (invoice_no),
    CONSTRAINT fk_sales_transaction
        FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_customer
        FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
