-- PAY-5: mark a confirmed salary notice line as paid.
--
-- Paying a line posts an ordinary expense (category Salaries) through
-- TransactionService and links it here. paid_at is the payment date the
-- admin chose (also the expense's transaction_date); paid_account_id the
-- account it came from. "Mark as unpaid" voids that expense and clears all
-- four columns. While paid_transaction_id is set, the expense can be voided
-- only from the salary notice, and the month cannot be withdrawn, replaced
-- or updated from a sheet.
--
-- uq_payroll_lines_paid_tx keeps one transaction from paying two lines and
-- lets the ledger join back to its line. paid_by carries no foreign key:
-- like withdrawn_by and audit_log.user_id it names who acted, and must not
-- stop that user being removed later. BranchController::destroy deletes
-- payroll lines before transactions and accounts, so the foreign keys never
-- block a branch deletion.
--
-- Re-runnable: every column, index and constraint is added only if missing,
-- guarded by information_schema and run through PREPARE (MySQL 8 has no
-- ADD COLUMN IF NOT EXISTS; this form works on MySQL 8 and MariaDB 10.6+).

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_run_lines'
        AND COLUMN_NAME = 'paid_at') = 0,
    'ALTER TABLE payroll_run_lines ADD COLUMN paid_at DATE NULL AFTER query_text',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_run_lines'
        AND COLUMN_NAME = 'paid_by') = 0,
    'ALTER TABLE payroll_run_lines ADD COLUMN paid_by BIGINT UNSIGNED NULL AFTER paid_at',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_run_lines'
        AND COLUMN_NAME = 'paid_transaction_id') = 0,
    'ALTER TABLE payroll_run_lines ADD COLUMN paid_transaction_id BIGINT UNSIGNED NULL AFTER paid_by',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_run_lines'
        AND COLUMN_NAME = 'paid_account_id') = 0,
    'ALTER TABLE payroll_run_lines ADD COLUMN paid_account_id BIGINT UNSIGNED NULL AFTER paid_transaction_id',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_run_lines'
        AND INDEX_NAME = 'uq_payroll_lines_paid_tx') = 0,
    'ALTER TABLE payroll_run_lines ADD UNIQUE KEY uq_payroll_lines_paid_tx (paid_transaction_id)',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_run_lines'
        AND CONSTRAINT_NAME = 'fk_payroll_lines_paid_tx') = 0,
    'ALTER TABLE payroll_run_lines
        ADD CONSTRAINT fk_payroll_lines_paid_tx FOREIGN KEY (paid_transaction_id) REFERENCES transactions (id)',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_run_lines'
        AND CONSTRAINT_NAME = 'fk_payroll_lines_paid_account') = 0,
    'ALTER TABLE payroll_run_lines
        ADD CONSTRAINT fk_payroll_lines_paid_account FOREIGN KEY (paid_account_id) REFERENCES accounts (id)',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;
