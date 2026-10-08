-- EMP: the `employee` role and its profile.
--
-- An employee is a login (users row, role 'employee') with no access to the
-- books: it reads its own profile and the documents its branch's admins
-- publish. The HR-shaped fields live in their own table rather than as
-- nullable columns on users, so the users table stays the same shape for
-- every other role.
--
-- branch_id is copied onto the profile (it always equals users.branch_id) so
-- every employee query can filter on the profile table's own column — the
-- same "every business table carries branch_id" rule the multi-branch
-- retrofit established.
--
-- Safe to re-run: MODIFY COLUMN to the same definition is a no-op, and the
-- table is CREATE ... IF NOT EXISTS.

ALTER TABLE users
    MODIFY COLUMN role ENUM('admin','partner','accountant','data_entry','super_admin','employee')
    NOT NULL DEFAULT 'partner';

CREATE TABLE IF NOT EXISTS employee_profiles (
    user_id      BIGINT UNSIGNED NOT NULL,
    branch_id    BIGINT UNSIGNED NOT NULL,
    phone        VARCHAR(30)     NULL,
    designation  VARCHAR(120)    NOT NULL,
    joining_date DATE            NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (user_id),
    KEY idx_employee_profiles_branch (branch_id),

    CONSTRAINT fk_employee_profiles_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_employee_profiles_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
