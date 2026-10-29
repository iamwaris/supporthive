-- AUTH-1: "Remember me on this device" (decision 2026-10-29, docs/PLAN.md §5).
--
-- Split-token persistent login. The cookie carries `selector:validator`; only
-- the selector is stored as-is (it is a lookup key, not a secret) and the
-- validator is stored as its sha256, so a database leak cannot be replayed as
-- a login. Every successful use sets consumed_at on the row (a conditional
-- UPDATE, so two racing requests cannot both win) and issues a fresh one.
-- The consumed row is kept until it expires: presenting it again later means
-- the cookie was copied, and every token for that user is revoked. See
-- App\Core\RememberMe, which also deletes every row for a user on a password
-- change or reset, deactivation, or role change. Expired rows, consumed or
-- not, are pruned by scripts/maintenance.php.
--
-- user_agent is for display only (a future "signed-in devices" list); it is
-- never used to decide anything.
--
-- A brand-new table, so CREATE TABLE IF NOT EXISTS alone makes this safe to
-- re-run on both MySQL 8 and MariaDB 10.6+ — no information_schema guards
-- are needed the way 000018's ALTERs needed them.
CREATE TABLE IF NOT EXISTS remember_tokens (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    selector       CHAR(24)        NOT NULL,
    validator_hash CHAR(64)        NOT NULL,
    expires_at     DATETIME        NOT NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at   DATETIME        NULL,
    consumed_at    DATETIME        NULL,
    user_agent     VARCHAR(255)    NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_remember_tokens_selector (selector),
    KEY idx_remember_tokens_user (user_id),
    KEY idx_remember_tokens_expires (expires_at),
    CONSTRAINT fk_remember_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
