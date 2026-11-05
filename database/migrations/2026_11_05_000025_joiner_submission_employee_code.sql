-- JOIN-4: the Employee ID a joiner types on the public /join/{token} form.
--
-- Only a suggestion: it prefills the admin's approve form, where the admin
-- can change it, and approval still runs the uniqueness checks on
-- employee_profiles. So this column is deliberately not unique — two joiners
-- may type the same value, or one already in use, and the public form never
-- says so.
--
-- VARCHAR(30) NULL, utf8mb4_unicode_ci, after email, matching
-- employee_profiles.employee_code. NULL for every submission made before
-- this migration; the form requires it from now on.
--
-- Safe to re-run on MySQL 8 and MariaDB 10.6+: ADD COLUMN IF NOT EXISTS is
-- not on MySQL 8, so the ALTER is guarded by an information_schema lookup
-- and run through PREPARE, as 000021 and 000024 do.

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'joiner_submissions'
        AND COLUMN_NAME = 'employee_code') = 0,
    'ALTER TABLE joiner_submissions ADD COLUMN employee_code VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER email',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;
