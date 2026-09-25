-- M8: AI usage log — one row per AI call actually made, so per-branch usage
-- can be rate-limited and reported on without asking the provider.
--
-- Deliberately records only who/what/when: no prompt, no response, no image.
-- Receipts and chat messages can contain the branch's financial detail, and
-- this table is read by ordinary reporting code, so nothing worth leaking is
-- written here in the first place.

CREATE TABLE IF NOT EXISTS ai_usage_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    branch_id   BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    feature     ENUM('receipt_scan','chat') NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- Every read is "usage for this branch in this window" — the quota check
    -- on each call and the admin usage panel both hit exactly this prefix.
    KEY idx_ai_usage_branch_created (branch_id, created_at),

    CONSTRAINT fk_ai_usage_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
    CONSTRAINT fk_ai_usage_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
