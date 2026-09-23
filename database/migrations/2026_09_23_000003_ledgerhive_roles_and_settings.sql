-- M1: LedgerHive roles and application settings.
--
-- The scaffold shipped generic support-desk roles (agent/customer). This
-- product has partners and accountants. V1 assigns only admin and partner;
-- the other two are carried in the enum so adding them later is data rather
-- than another ALTER on a table that will by then hold real users.

ALTER TABLE users
    MODIFY COLUMN role ENUM('admin','partner','accountant','data_entry')
    NOT NULL DEFAULT 'partner';

-- Key/value application settings. Typed so the UI can render the right
-- control and the reader can cast safely.
CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(60)  NOT NULL,
    setting_value TEXT         NULL,
    value_type    ENUM('string','int','decimal','bool','date') NOT NULL DEFAULT 'string',
    updated_by    BIGINT UNSIGNED NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value, value_type) VALUES
    ('company_name',       'LedgerHive',  'string'),
    ('currency_code',      'PKR',         'string'),
    ('currency_symbol',    'Rs',          'string'),
    ('fiscal_year_start',  '7',           'int'),
    ('budget_alert_pct',   '80',          'int'),
    ('approval_threshold', '0',           'decimal')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
