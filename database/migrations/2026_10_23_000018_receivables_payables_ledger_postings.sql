-- AR/AP-3: the receivables / payables register posts to the ledger
-- (decision 2026-10-23, docs/PLAN.md §5, superseding the 2026-10-17
-- "independent register" decision).
--
-- Entries are loans / advances. They move an account balance and never the
-- P&L, exactly like transfers and partner capital:
--
--   receivable_issued     -1  money lent out of an account
--   receivable_collected  +1  that money coming back
--   payable_received      +1  money borrowed into an account
--   payable_repaid        -1  that money paid back
--
-- Only App\Services\ReceivablePayableService posts or voids these types,
-- always through TransactionService.
--
-- The four new link columns stay NULLABLE: rows recorded before this
-- migration have no posting until scripts/backfill-receivables-payables.php
-- runs, and a cancelled legacy row never gets one. "Open or settled rows are
-- posted" is therefore enforced in PHP and tests, not by a CHECK that every
-- legacy row would fail. The two CHECKs below only require that a posting
-- always names its account, which holds for every row, old or new.
--
-- Safe to re-run on MySQL 8 and MariaDB 10.6+. Neither ADD COLUMN IF NOT
-- EXISTS nor ADD CONSTRAINT IF NOT EXISTS exists on MySQL 8, so each ALTER is
-- guarded by an information_schema lookup and run through PREPARE: a no-op
-- `DO 0` when the column / constraint is already there. MODIFY COLUMN to the
-- same ENUM is a no-op by itself.

-- ---------------------------------------------------------------------------
-- transactions.type: the four register movements.
-- ---------------------------------------------------------------------------
ALTER TABLE transactions
    MODIFY COLUMN type ENUM(
        'income',
        'expense',
        'transfer_in',
        'transfer_out',
        'partner_contribution',
        'partner_withdrawal',
        'profit_distribution',
        'receivable_issued',
        'receivable_collected',
        'payable_received',
        'payable_repaid'
    ) NOT NULL;

-- ---------------------------------------------------------------------------
-- receivables_payables: which account each side moved, and its posting.
-- ---------------------------------------------------------------------------
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receivables_payables'
        AND COLUMN_NAME = 'account_id') = 0,
    'ALTER TABLE receivables_payables ADD COLUMN account_id BIGINT UNSIGNED NULL AFTER amount',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receivables_payables'
        AND COLUMN_NAME = 'issue_transaction_id') = 0,
    'ALTER TABLE receivables_payables ADD COLUMN issue_transaction_id BIGINT UNSIGNED NULL AFTER account_id',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receivables_payables'
        AND COLUMN_NAME = 'settlement_account_id') = 0,
    'ALTER TABLE receivables_payables ADD COLUMN settlement_account_id BIGINT UNSIGNED NULL AFTER settled_on',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receivables_payables'
        AND COLUMN_NAME = 'settlement_transaction_id') = 0,
    'ALTER TABLE receivables_payables
        ADD COLUMN settlement_transaction_id BIGINT UNSIGNED NULL AFTER settlement_account_id',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

-- One posting belongs to at most one entry. The unique keys also serve as
-- the indexes the transaction foreign keys need.
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receivables_payables'
        AND INDEX_NAME = 'uq_rp_issue_tx') = 0,
    'ALTER TABLE receivables_payables ADD UNIQUE KEY uq_rp_issue_tx (issue_transaction_id)',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receivables_payables'
        AND INDEX_NAME = 'uq_rp_settlement_tx') = 0,
    'ALTER TABLE receivables_payables ADD UNIQUE KEY uq_rp_settlement_tx (settlement_transaction_id)',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

-- Foreign keys: RESTRICT, like every other ledger reference. A branch delete
-- removes register rows before transactions and accounts.
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'receivables_payables'
        AND CONSTRAINT_NAME = 'fk_rp_account') = 0,
    'ALTER TABLE receivables_payables
        ADD CONSTRAINT fk_rp_account FOREIGN KEY (account_id) REFERENCES accounts (id)',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'receivables_payables'
        AND CONSTRAINT_NAME = 'fk_rp_issue_tx') = 0,
    'ALTER TABLE receivables_payables
        ADD CONSTRAINT fk_rp_issue_tx FOREIGN KEY (issue_transaction_id) REFERENCES transactions (id)',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'receivables_payables'
        AND CONSTRAINT_NAME = 'fk_rp_settlement_account') = 0,
    'ALTER TABLE receivables_payables
        ADD CONSTRAINT fk_rp_settlement_account FOREIGN KEY (settlement_account_id) REFERENCES accounts (id)',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'receivables_payables'
        AND CONSTRAINT_NAME = 'fk_rp_settlement_tx') = 0,
    'ALTER TABLE receivables_payables
        ADD CONSTRAINT fk_rp_settlement_tx FOREIGN KEY (settlement_transaction_id) REFERENCES transactions (id)',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

-- A posting always says which account it moved; a settlement posting only
-- exists on a settled entry.
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'receivables_payables'
        AND CONSTRAINT_NAME = 'chk_rp_issue_posting') = 0,
    'ALTER TABLE receivables_payables ADD CONSTRAINT chk_rp_issue_posting
        CHECK (issue_transaction_id IS NULL OR account_id IS NOT NULL)',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'receivables_payables'
        AND CONSTRAINT_NAME = 'chk_rp_settlement_posting') = 0,
    'ALTER TABLE receivables_payables ADD CONSTRAINT chk_rp_settlement_posting CHECK (
        settlement_transaction_id IS NULL
        OR (settlement_account_id IS NOT NULL AND status = ''settled'')
    )',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;
