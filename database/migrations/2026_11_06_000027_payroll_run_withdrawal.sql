-- PAY-3: withdraw a month's salary notices.
--
-- A withdrawal resets every line of the run to draft and kills every link
-- already emailed; each line's prior state goes to audit_log. The run keeps
-- when, by whom and why it was last withdrawn. withdrawn_at also marks every
-- notice sent afterwards as a correction ("Updated salary notice"), and it
-- lives on the run row, so it survives a re-import that replaces the lines.
--
-- Re-runnable: each column is added only if it is missing, guarded by an
-- information_schema lookup and run through PREPARE (MySQL 8 has no
-- ADD COLUMN IF NOT EXISTS; MariaDB 10.6 does, but this form works on both).
-- withdrawn_by carries no foreign key: like audit_log.user_id it names who
-- acted, and must not stop that user being removed later.

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_runs'
        AND COLUMN_NAME = 'withdrawn_at') = 0,
    'ALTER TABLE payroll_runs ADD COLUMN withdrawn_at DATETIME NULL AFTER note',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_runs'
        AND COLUMN_NAME = 'withdrawn_by') = 0,
    'ALTER TABLE payroll_runs ADD COLUMN withdrawn_by BIGINT UNSIGNED NULL AFTER withdrawn_at',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_runs'
        AND COLUMN_NAME = 'withdraw_reason') = 0,
    'ALTER TABLE payroll_runs ADD COLUMN withdraw_reason VARCHAR(500) NULL AFTER withdrawn_by',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;
