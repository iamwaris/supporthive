-- JOIN-1: the public new-joiner form (decision 2026-11-05, docs/PLAN.md §5).
--
-- employee_join_links: at most one shared link per branch. Only the sha256
-- of its token is stored, so a copy of the database cannot be turned back
-- into a working URL; the full URL is shown to the admin once, when it is
-- generated. Regenerating replaces token_hash in place (the old token stops
-- matching at once); disabling clears is_active. The history of both lives
-- in audit_log.
--
-- joiner_submissions: one row per submitted form. A submission is final —
-- the joiner can never read or change it. An admin either approves it
-- (status approved, employee_user_id = the employee created through
-- App\Services\EmployeeOnboarding) or deletes it outright: a pending row
-- that is not wanted is hard-deleted, encrypted bank details and all, so
-- there is no rejected status and nothing left to retain. Bank details use
-- the same base64 SecretBox columns as employee_profiles (see 000021).
-- submitted_ip is kept for abuse investigation only.
--
-- generated_by / reviewed_by reference users without cascading, so
-- BranchController::destroy deletes these rows before the branch's users.
--
-- Brand-new tables, so CREATE TABLE IF NOT EXISTS alone makes this safe to
-- re-run on MySQL 8 and MariaDB 10.6+.

CREATE TABLE IF NOT EXISTS employee_join_links (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id    BIGINT UNSIGNED NOT NULL,
    token_hash   CHAR(64)        CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_active    TINYINT(1)      NOT NULL DEFAULT 1,
    -- Who generated the current token, and when; regenerating overwrites both.
    generated_by BIGINT UNSIGNED NOT NULL,
    generated_at DATETIME        NOT NULL,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_employee_join_links_branch (branch_id),
    UNIQUE KEY uq_employee_join_links_token (token_hash),

    CONSTRAINT fk_employee_join_links_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
    CONSTRAINT fk_employee_join_links_generator FOREIGN KEY (generated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS joiner_submissions (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id               BIGINT UNSIGNED NOT NULL,
    name                    VARCHAR(120)    NOT NULL,
    email                   VARCHAR(190)    NOT NULL,
    phone                   VARCHAR(30)     NULL,
    joining_date            DATE            NULL,
    bank_name_enc           VARCHAR(600)    CHARACTER SET ascii COLLATE ascii_bin NULL,
    bank_account_number_enc VARCHAR(128)    CHARACTER SET ascii COLLATE ascii_bin NULL,
    bank_iban_enc           VARCHAR(128)    CHARACTER SET ascii COLLATE ascii_bin NULL,
    status                  ENUM('pending','approved') NOT NULL DEFAULT 'pending',
    employee_user_id        BIGINT UNSIGNED NULL,
    reviewed_by             BIGINT UNSIGNED NULL,
    reviewed_at             DATETIME        NULL,
    submitted_ip            VARCHAR(45)     NULL,
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    -- The admin list is "this branch, this status, oldest first".
    KEY idx_joiner_submissions_branch_status (branch_id, status, created_at),

    CONSTRAINT fk_joiner_submissions_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
    CONSTRAINT fk_joiner_submissions_employee
        FOREIGN KEY (employee_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_joiner_submissions_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
