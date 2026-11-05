-- PAY-1: monthly salary notices (payroll sign-off), decision 2026-11-05 in
-- docs/PLAN.md §5.
--
-- payroll_runs: one per branch per month (period_month is always the first
-- day of that month). Holds the month's single note, shown to every
-- employee on their notice.
--
-- payroll_run_lines: one per employee in a run, imported from the admin's
-- spreadsheet. salary is the FINAL amount to pay; absent_days and
-- working_days are informational only — nothing is calculated from them.
-- employee_code is a copy of the Employee ID as it was on import, so the
-- notice still reads correctly if the profile's ID changes later.
--
-- Each line carries its own private link. Only sha256(token) is stored, as
-- for employee_join_links; sending again overwrites token_hash, so the
-- previous link stops working at once. status walks
-- draft -> sent -> confirmed | query, and a query can be re-sent (back to
-- sent). The response columns hold the CURRENT response only: the previous
-- one is written to audit_log (payroll_line.resent) before it is cleared.
-- screenshot_filename is the generated name under
-- storage/documents/payroll-screenshots — never a public path.
--
-- branch_id is copied onto lines (always equal to the run's) so every
-- branch-scoped read filters on the table's own column, like every other
-- business table. created_by and user_id reference users without
-- cascading, so BranchController::destroy deletes lines and runs before
-- the branch's users.
--
-- Brand-new tables, so CREATE TABLE IF NOT EXISTS alone makes this safe to
-- re-run on MySQL 8 and MariaDB 10.6+.

CREATE TABLE IF NOT EXISTS payroll_runs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id    BIGINT UNSIGNED NOT NULL,
    period_month DATE            NOT NULL,
    note         TEXT            NULL,
    created_by   BIGINT UNSIGNED NOT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_runs_branch_month (branch_id, period_month),

    CONSTRAINT fk_payroll_runs_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
    CONSTRAINT fk_payroll_runs_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_run_lines (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id           BIGINT UNSIGNED NOT NULL,
    run_id              BIGINT UNSIGNED NOT NULL,
    user_id             BIGINT UNSIGNED NOT NULL,
    employee_code       VARCHAR(30)     NOT NULL,
    salary              DECIMAL(15,2)   NOT NULL,
    absent_days         DECIMAL(5,1)    NOT NULL DEFAULT 0.0,
    working_days        DECIMAL(5,1)    NOT NULL DEFAULT 0.0,
    screenshot_filename VARCHAR(64)     CHARACTER SET ascii COLLATE ascii_bin NULL,
    token_hash          CHAR(64)        CHARACTER SET ascii COLLATE ascii_bin NULL,
    token_expires_at    DATETIME        NULL,
    emailed_at          DATETIME        NULL,
    status              ENUM('draft','sent','confirmed','query') NOT NULL DEFAULT 'draft',
    signed_name         VARCHAR(120)    NULL,
    responded_at        DATETIME        NULL,
    response_ip         VARCHAR(45)     NULL,
    response_user_agent VARCHAR(255)    NULL,
    query_text          TEXT            NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_run_lines_run_user (run_id, user_id),
    UNIQUE KEY uq_payroll_run_lines_token (token_hash),
    KEY idx_payroll_run_lines_branch_run (branch_id, run_id),

    CONSTRAINT fk_payroll_run_lines_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
    CONSTRAINT fk_payroll_run_lines_run FOREIGN KEY (run_id) REFERENCES payroll_runs (id) ON DELETE CASCADE,
    CONSTRAINT fk_payroll_run_lines_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
