-- JOIN-3: bank details stored in plain text; encryption deferred
-- (decision 2026-11-05, docs/PLAN.md §5).
--
-- Production could not encrypt (libsodium / APP_ENCRYPTION_KEY on the host),
-- so every joiner submission with bank details failed with a 500. The owner
-- chose plain columns for now; the protection is admin-only access, masked
-- values in lists and audit rows, and never logging them.
--
-- Adds bank_name VARCHAR(100), bank_account_number VARCHAR(24) and
-- bank_iban VARCHAR(24), NULL, utf8mb4_unicode_ci, after joining_date, to
-- employee_profiles and joiner_submissions. Sized from
-- App\Services\BankDetails::RULES: name max 100 characters, account number
-- 6-24 digits, Pakistani IBAN exactly 24 characters (both stored normalised,
-- with no spaces or dashes).
--
-- The *_enc columns from 000021/000022 are deliberately left in place: the
-- app no longer reads or writes them, and nothing is copied across
-- (ciphertext cannot be decrypted in SQL). Dropping them is the pending
-- cleanup, once it is confirmed they hold no values.
--
-- Safe to re-run on MySQL 8 and MariaDB 10.6+: ADD COLUMN IF NOT EXISTS is
-- not on MySQL 8, so each ALTER is guarded by an information_schema lookup
-- and run through PREPARE, as 000021 does.

-- employee_profiles: plain columns ------------------------------------------

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles'
        AND COLUMN_NAME = 'bank_name') = 0,
    'ALTER TABLE employee_profiles ADD COLUMN bank_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER joining_date',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles'
        AND COLUMN_NAME = 'bank_account_number') = 0,
    'ALTER TABLE employee_profiles ADD COLUMN bank_account_number VARCHAR(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER bank_name',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_profiles'
        AND COLUMN_NAME = 'bank_iban') = 0,
    'ALTER TABLE employee_profiles ADD COLUMN bank_iban VARCHAR(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER bank_account_number',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

-- joiner_submissions: plain columns -----------------------------------------

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'joiner_submissions'
        AND COLUMN_NAME = 'bank_name') = 0,
    'ALTER TABLE joiner_submissions ADD COLUMN bank_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER joining_date',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'joiner_submissions'
        AND COLUMN_NAME = 'bank_account_number') = 0,
    'ALTER TABLE joiner_submissions ADD COLUMN bank_account_number VARCHAR(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER bank_name',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'joiner_submissions'
        AND COLUMN_NAME = 'bank_iban') = 0,
    'ALTER TABLE joiner_submissions ADD COLUMN bank_iban VARCHAR(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER bank_account_number',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;
