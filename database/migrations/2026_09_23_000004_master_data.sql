-- M2: master data — partners, ownership history, categories, accounts, customers.
--
-- Everything a transaction needs to exist before it can be recorded. No money
-- lives in these tables; they are the things money gets attributed to.

-- ---------------------------------------------------------------------------
-- Partners
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS partners (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(120) NOT NULL,
    email      VARCHAR(190) NULL,
    phone      VARCHAR(40)  NULL,
    join_date  DATE         NOT NULL,
    status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
    notes      TEXT         NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_partners_status (status, name),
    CONSTRAINT fk_partners_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Ownership shares, effective-dated.
--
-- Deliberately NOT a percentage column on `partners`. Profit distribution has
-- to answer "what was the split during March?", and a single mutable column
-- cannot: changing it would silently rewrite the basis of every past payout.
--
-- A "split" is the set of rows whose period covers a given date. effective_to
-- is NULL while a row is current, and is closed off when a new split starts.
--
-- share_bp is BASIS POINTS OF A PERCENT — 40.0000% is stored as 400000, and
-- 100% as 1000000. Integers, so validating that a split sums to exactly 100%
-- is exact. The same check on DECIMAL or float invites a 99.99999 that passes
-- or a 100.00 that does not.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS partner_shares (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    partner_id     BIGINT UNSIGNED NOT NULL,
    share_bp       INT UNSIGNED NOT NULL,
    effective_from DATE         NOT NULL,
    effective_to   DATE         NULL,
    created_by     BIGINT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_share_partner_from (partner_id, effective_from),
    KEY idx_share_period (effective_from, effective_to),
    CONSTRAINT fk_shares_partner FOREIGN KEY (partner_id) REFERENCES partners (id) ON DELETE CASCADE,
    CONSTRAINT fk_shares_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_share_bp CHECK (share_bp > 0 AND share_bp <= 1000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Categories — self-referencing one level, typed so an income category can
-- never be picked on an expense form.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(80)  NOT NULL,
    type       ENUM('expense','income') NOT NULL,
    parent_id  BIGINT UNSIGNED NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_category_name (parent_id, type, name),
    KEY idx_category_type (type, is_active, sort_order),
    CONSTRAINT fk_category_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Accounts. opening_balance is DECIMAL, never FLOAT: binary floats cannot
-- represent 0.10 exactly and the error compounds through every aggregate.
-- The live balance is derived (opening + movements), never stored.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS accounts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(120) NOT NULL,
    type            ENUM('cash','bank','credit_card','petty_cash','other') NOT NULL DEFAULT 'bank',
    opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    opening_date    DATE         NOT NULL,
    institution     VARCHAR(120) NULL,
    reference       VARCHAR(80)  NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    notes           TEXT         NULL,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_account_name (name),
    KEY idx_account_active (is_active, type),
    CONSTRAINT fk_accounts_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Customers, referenced by income. Vendors are deliberately free text on the
-- expense row instead (decision D-5).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(160) NOT NULL,
    contact_name VARCHAR(120) NULL,
    email        VARCHAR(190) NULL,
    phone        VARCHAR(40)  NULL,
    is_active    TINYINT(1)   NOT NULL DEFAULT 1,
    notes        TEXT         NULL,
    created_by   BIGINT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customer_active (is_active, name),
    CONSTRAINT fk_customers_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Starter expense categories from the specification, plus a minimal income
-- set. Re-runnable: INSERT IGNORE against the unique key.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO categories (name, type, parent_id, sort_order) VALUES
    ('Salaries',        'expense', NULL, 10),
    ('Office Rent',     'expense', NULL, 20),
    ('Electricity',     'expense', NULL, 30),
    ('Internet',        'expense', NULL, 40),
    ('Marketing',       'expense', NULL, 50),
    ('Transportation',  'expense', NULL, 60),
    ('Software',        'expense', NULL, 70),
    ('Equipment',       'expense', NULL, 80),
    ('Food',            'expense', NULL, 90),
    ('Office Supplies', 'expense', NULL, 100),
    ('Utilities',       'expense', NULL, 110),
    ('Miscellaneous',   'expense', NULL, 120),
    ('Service Revenue', 'income',  NULL, 10),
    ('Product Sales',   'income',  NULL, 20),
    ('Other Income',    'income',  NULL, 30);
