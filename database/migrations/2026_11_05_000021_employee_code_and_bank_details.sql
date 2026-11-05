-- JOIN-1: Employee ID and encrypted bank details on the employee profile
-- (decision 2026-11-05, docs/PLAN.md §5).
--
-- employee_code is the Employee ID an admin types in. NULL until someone
-- fills it in — every employee created before this migration stays NULL.
-- Unique within a branch: the composite key allows any number of NULLs on
-- both engines, and utf8mb4_unicode_ci makes "emp-7" and "EMP-7" the same
-- code, which is what an admin would expect.
--
-- The three *_enc columns hold App\Core\SecretBox output: base64 of a random
-- 24-byte nonce followed by the XSalsa20-Poly1305 ciphertext and tag. Never
-- plain text. They are ASCII VARCHAR rather than VARBINARY so the value goes
-- through PDO's ordinary string binding byte-for-byte on MySQL 8 and MariaDB
-- alike, with no connection-charset conversion of raw bytes to reason about,
-- and ascii_bin so no collation ever folds case inside a ciphertext. Sizes:
-- bank name <= 100 characters (<= 400 bytes) + 40 bytes of nonce and tag,
-- base64 = 588; account number and IBAN <= 24 bytes + 40, base64 = 88.
--
-- Safe to re-run on MySQL 8 and MariaDB 10.6+: neither ADD COLUMN IF NOT
-- EXISTS nor ADD INDEX IF NOT EXISTS exists on MySQL 8, so each ALTER is
-- guarded by an information_schema lookup and run through PREPARE, the same
-- way 000018 does it.

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles'
        AND COLUMN_NAME = 'employee_code') = 0,
    'ALTER TABLE employee_profiles ADD COLUMN employee_code VARCHAR(30) NULL AFTER branch_id',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles'
        AND COLUMN_NAME = 'bank_name_enc') = 0,
    'ALTER TABLE employee_profiles ADD COLUMN bank_name_enc VARCHAR(600) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER joining_date',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles'
        AND COLUMN_NAME = 'bank_account_number_enc') = 0,
    'ALTER TABLE employee_profiles ADD COLUMN bank_account_number_enc VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER bank_name_enc',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles'
        AND COLUMN_NAME = 'bank_iban_enc') = 0,
    'ALTER TABLE employee_profiles ADD COLUMN bank_iban_enc VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER bank_account_number_enc',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles'
        AND INDEX_NAME = 'uq_employee_profiles_branch_code') = 0,
    'ALTER TABLE employee_profiles ADD UNIQUE KEY uq_employee_profiles_branch_code (branch_id, employee_code)',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;
