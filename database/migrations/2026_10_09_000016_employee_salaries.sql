-- EMP-9: an employee's salary, as an effective-dated, append-only history.
--
-- A change is a new row, never an UPDATE: the app has no update or delete
-- path for this table, so every past amount stays readable and every change
-- carries who recorded it and from which date it applies. The current salary
-- is the latest row whose effective_from is not in the future (ties broken
-- by id), so a raise can be recorded today and scheduled for next month.
--
-- Admin-only data: App\Models\EmployeeSalary is the only code that reads it,
-- and nothing on the employee's own portal does.
--
-- amount is DECIMAL(15,2) like every other money column (docs/PLAN.md §3,
-- "Money is DECIMAL(15,2)"); the form caps it at 9,999,999,999.99.
--
-- branch_id is copied in (it always equals the employee's branch) so every
-- query filters on this table's own column, as employee_profiles does.
-- created_by is not cascaded: BranchController::destroy deletes a branch's
-- salary rows before its users, as it does for employee_documents.
--
-- Safe to re-run: CREATE ... IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS employee_salaries (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id      BIGINT UNSIGNED NOT NULL,
    user_id        BIGINT UNSIGNED NOT NULL,
    amount         DECIMAL(15,2)   NOT NULL,
    effective_from DATE            NOT NULL,
    note           VARCHAR(255)    NULL,
    created_by     BIGINT UNSIGNED NOT NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    -- current(), upcoming() and history() all read one employee's rows in
    -- effective-date order.
    KEY idx_employee_salaries_user_effective (user_id, effective_from, id),
    KEY idx_employee_salaries_branch (branch_id),

    CONSTRAINT fk_employee_salaries_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
    CONSTRAINT fk_employee_salaries_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_employee_salaries_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
