-- M3: the central transaction ledger.
--
-- One table records every movement of money, exactly once. Every balance,
-- dashboard figure, P&L line and report aggregates THIS table and nothing
-- else. Type-specific detail lives in satellite tables that link back here
-- and never hold an amount, so the two can never disagree.
--
-- Shape decisions, all of which get harder to change once rows exist:
--
--   amount      DECIMAL(15,2), always POSITIVE. Binary floats cannot
--               represent 0.10 exactly and the error compounds through every
--               aggregate, so money is never a float here.
--   direction   +1 money in, -1 money out. Stored rather than derived at
--               query time so a balance is a plain SUM(amount * direction)
--               instead of a CASE over the type list - which is the kind of
--               expression somebody eventually writes slightly differently.
--   status      'posted' or 'void'. Posted rows are never deleted or edited
--               into something else; a mistake is voided with a reason and
--               stays visible. Every aggregate must filter on this.
--   transfer_group
--               Links the two legs of a transfer. A transfer is recorded as
--               TWO rows (out of one account, into another) so that a balance
--               stays a simple SUM and neither leg touches the P&L.

CREATE TABLE IF NOT EXISTS transactions (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_date DATE            NOT NULL,
    type             ENUM(
                         'income',
                         'expense',
                         'transfer_in',
                         'transfer_out',
                         'partner_contribution',
                         'partner_withdrawal',
                         'profit_distribution'
                     ) NOT NULL,
    direction        TINYINT         NOT NULL,
    amount           DECIMAL(15,2)   NOT NULL,
    account_id       BIGINT UNSIGNED NOT NULL,
    category_id      BIGINT UNSIGNED NULL,
    partner_id       BIGINT UNSIGNED NULL,
    description      VARCHAR(255)    NOT NULL,
    reference_no     VARCHAR(80)     NULL,
    status           ENUM('posted','void') NOT NULL DEFAULT 'posted',
    transfer_group   CHAR(32)        NULL,
    void_reason      VARCHAR(255)    NULL,
    voided_by        BIGINT UNSIGNED NULL,
    voided_at        DATETIME        NULL,
    created_by       BIGINT UNSIGNED NOT NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modified_by      BIGINT UNSIGNED NULL,
    modified_at      DATETIME        NULL,

    PRIMARY KEY (id),

    -- The date+status pair leads because almost every query is "this period,
    -- posted only": dashboard tiles, P&L, cash flow, the ledger listing.
    KEY idx_tx_date_status (transaction_date, status),
    KEY idx_tx_account_date (account_id, status, transaction_date),
    KEY idx_tx_category_date (category_id, status, transaction_date),
    KEY idx_tx_type_date (type, status, transaction_date),
    KEY idx_tx_partner (partner_id, status),
    KEY idx_tx_transfer_group (transfer_group),
    KEY idx_tx_created_by (created_by, created_at),
    KEY idx_tx_reference (reference_no),

    CONSTRAINT fk_tx_account  FOREIGN KEY (account_id)  REFERENCES accounts   (id),
    CONSTRAINT fk_tx_category FOREIGN KEY (category_id) REFERENCES categories (id),
    CONSTRAINT fk_tx_partner  FOREIGN KEY (partner_id)  REFERENCES partners   (id),
    CONSTRAINT fk_tx_creator  FOREIGN KEY (created_by)  REFERENCES users      (id),
    CONSTRAINT fk_tx_voider   FOREIGN KEY (voided_by)   REFERENCES users      (id) ON DELETE SET NULL,
    CONSTRAINT fk_tx_modifier FOREIGN KEY (modified_by) REFERENCES users      (id) ON DELETE SET NULL,

    -- A negative or zero amount would make direction meaningless and let a
    -- row quietly reverse its own sign.
    CONSTRAINT chk_tx_amount    CHECK (amount > 0),
    CONSTRAINT chk_tx_direction CHECK (direction IN (-1, 1)),
    -- A void must say why. An unexplained reversal of a financial record is
    -- exactly what the audit requirement exists to prevent.
    CONSTRAINT chk_tx_void_reason CHECK (status <> 'void' OR void_reason IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
