-- Multi-branch retrofit. One deployment, one database, every business table
-- scoped by branch_id — chosen over a database-per-branch split because it
-- needs no extra hosting privileges (no CREATE DATABASE) and no per-branch
-- migration/backup story. See docs/PLAN.md for the decision record.
--
-- `expenses` and `sales` are deliberately NOT given their own branch_id:
-- every query against them already goes through an id list that came from an
-- already-scoped `transactions` read (LedgerQuery, or a JOIN back to
-- transactions in the same query), so scoping them a second time would be a
-- second source of truth for the same fact.

-- ---------------------------------------------------------------------------
-- Branches themselves. A super admin (users.role = 'super_admin') is not a
-- member of any branch and manages this table directly; every other user
-- belongs to exactly one.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS branches (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(120) NOT NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_branch_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every table below existed before branches did, so there is real data to
-- carry forward: create a default branch and backfill every row into it
-- before any column is made NOT NULL.
INSERT INTO branches (name)
    SELECT 'Default' WHERE NOT EXISTS (SELECT 1 FROM branches LIMIT 1);
SET @default_branch_id = (SELECT id FROM branches ORDER BY id LIMIT 1);

-- ---------------------------------------------------------------------------
-- users — branch_id NULLABLE. NULL means super admin: not tied to any
-- branch, the only role allowed to read/write this table without one.
-- ---------------------------------------------------------------------------
ALTER TABLE users
    MODIFY COLUMN role ENUM('admin','partner','accountant','data_entry','super_admin')
    NOT NULL DEFAULT 'partner';

ALTER TABLE users
    ADD COLUMN branch_id BIGINT UNSIGNED NULL AFTER role;
UPDATE users SET branch_id = @default_branch_id WHERE branch_id IS NULL;
ALTER TABLE users
    ADD CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
    ADD KEY idx_users_branch (branch_id);

-- ---------------------------------------------------------------------------
-- accounts, categories, customers, partners — master data. Uniqueness moves
-- from global to per-branch (two branches may each have an account named
-- "Main Bank").
-- ---------------------------------------------------------------------------
ALTER TABLE accounts
    ADD COLUMN branch_id BIGINT UNSIGNED NULL AFTER id;
UPDATE accounts SET branch_id = @default_branch_id;
ALTER TABLE accounts
    MODIFY COLUMN branch_id BIGINT UNSIGNED NOT NULL,
    DROP INDEX uq_account_name,
    ADD UNIQUE KEY uq_account_name (branch_id, name),
    ADD CONSTRAINT fk_accounts_branch FOREIGN KEY (branch_id) REFERENCES branches (id);

ALTER TABLE categories
    ADD COLUMN branch_id BIGINT UNSIGNED NULL AFTER id;
UPDATE categories SET branch_id = @default_branch_id;
-- uq_category_name (parent_id, type, name) is what supports the
-- self-referencing fk_category_parent FOREIGN KEY (parent_id) today — its
-- replacement leads with branch_id instead, so parent_id is no longer a
-- left prefix. idx_categories_parent replaces that support explicitly;
-- dropping the old unique key without it fails with error 1025.
ALTER TABLE categories
    MODIFY COLUMN branch_id BIGINT UNSIGNED NOT NULL,
    ADD KEY idx_categories_parent (parent_id),
    DROP INDEX uq_category_name,
    ADD UNIQUE KEY uq_category_name (branch_id, parent_id, type, name),
    ADD CONSTRAINT fk_categories_branch FOREIGN KEY (branch_id) REFERENCES branches (id);

ALTER TABLE customers
    ADD COLUMN branch_id BIGINT UNSIGNED NULL AFTER id;
UPDATE customers SET branch_id = @default_branch_id;
ALTER TABLE customers
    MODIFY COLUMN branch_id BIGINT UNSIGNED NOT NULL,
    ADD KEY idx_customers_branch (branch_id),
    ADD CONSTRAINT fk_customers_branch FOREIGN KEY (branch_id) REFERENCES branches (id);

ALTER TABLE partners
    ADD COLUMN branch_id BIGINT UNSIGNED NULL AFTER id;
UPDATE partners SET branch_id = @default_branch_id;
ALTER TABLE partners
    MODIFY COLUMN branch_id BIGINT UNSIGNED NOT NULL,
    ADD KEY idx_partners_branch (branch_id),
    ADD CONSTRAINT fk_partners_branch FOREIGN KEY (branch_id) REFERENCES branches (id);

-- partner_shares is queried standalone (ShareService::splitOn() et al. do not
-- join partners), so it needs its own column rather than inheriting scope
-- through partner_id.
ALTER TABLE partner_shares
    ADD COLUMN branch_id BIGINT UNSIGNED NULL AFTER id;
UPDATE partner_shares SET branch_id = @default_branch_id;
ALTER TABLE partner_shares
    MODIFY COLUMN branch_id BIGINT UNSIGNED NOT NULL,
    ADD KEY idx_shares_branch (branch_id),
    ADD CONSTRAINT fk_shares_branch FOREIGN KEY (branch_id) REFERENCES branches (id);

-- ---------------------------------------------------------------------------
-- transactions — the ledger itself. Every report/dashboard/balance query
-- goes through LedgerQuery, so this one column is what makes all of them
-- branch-safe at once.
-- ---------------------------------------------------------------------------
ALTER TABLE transactions
    ADD COLUMN branch_id BIGINT UNSIGNED NULL AFTER id;
UPDATE transactions SET branch_id = @default_branch_id;
ALTER TABLE transactions
    MODIFY COLUMN branch_id BIGINT UNSIGNED NOT NULL,
    ADD KEY idx_tx_branch_date_status (branch_id, transaction_date, status),
    ADD CONSTRAINT fk_tx_branch FOREIGN KEY (branch_id) REFERENCES branches (id);

-- ---------------------------------------------------------------------------
-- budgets, profit_distributions — reporting/control tables queried directly.
-- ---------------------------------------------------------------------------
ALTER TABLE budgets
    ADD COLUMN branch_id BIGINT UNSIGNED NULL AFTER id;
UPDATE budgets SET branch_id = @default_branch_id;
ALTER TABLE budgets
    MODIFY COLUMN branch_id BIGINT UNSIGNED NOT NULL,
    DROP INDEX uq_budget_period_category,
    ADD UNIQUE KEY uq_budget_period_category (branch_id, year, month, category_id),
    ADD CONSTRAINT fk_budgets_branch FOREIGN KEY (branch_id) REFERENCES branches (id);

ALTER TABLE profit_distributions
    ADD COLUMN branch_id BIGINT UNSIGNED NULL AFTER id;
UPDATE profit_distributions SET branch_id = @default_branch_id;
ALTER TABLE profit_distributions
    MODIFY COLUMN branch_id BIGINT UNSIGNED NOT NULL,
    ADD KEY idx_distribution_branch (branch_id),
    ADD CONSTRAINT fk_distributions_branch FOREIGN KEY (branch_id) REFERENCES branches (id);

-- ---------------------------------------------------------------------------
-- audit_log — branch_id NULLABLE. A super admin's own actions (creating a
-- branch, switching into one) happen outside any branch context.
-- ---------------------------------------------------------------------------
ALTER TABLE audit_log
    ADD COLUMN branch_id BIGINT UNSIGNED NULL AFTER user_id,
    ADD KEY idx_audit_branch (branch_id),
    ADD CONSTRAINT fk_audit_branch FOREIGN KEY (branch_id) REFERENCES branches (id);

-- ---------------------------------------------------------------------------
-- settings — one row per (branch, key) instead of one global row per key.
-- Settings::string()/int() already take a code-level default, so a branch
-- with no override for a key simply falls back to that default.
-- ---------------------------------------------------------------------------
ALTER TABLE settings
    ADD COLUMN branch_id BIGINT UNSIGNED NULL FIRST;
UPDATE settings SET branch_id = @default_branch_id;
ALTER TABLE settings
    MODIFY COLUMN branch_id BIGINT UNSIGNED NOT NULL,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (branch_id, setting_key),
    ADD CONSTRAINT fk_settings_branch FOREIGN KEY (branch_id) REFERENCES branches (id);
