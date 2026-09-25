-- M8: AI assistant settings, one row per branch.
--
-- Settings are per (branch_id, setting_key) since migration 000008, so these
-- seed one row for every branch that exists today; a branch created later
-- falls back to the code-level default in App\Services\Settings until an
-- admin saves the form.
--
-- ai_enabled is off ('0') for every branch on purpose: the feature must be
-- switched on deliberately, per branch, after a key is stored.
-- ai_anthropic_api_key_encrypted is seeded NULL — the ciphertext is written
-- by the application, never by a migration, so no secret lives in this file.

INSERT INTO settings (branch_id, setting_key, setting_value, value_type)
    SELECT id, 'ai_enabled', '0', 'bool' FROM branches
ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO settings (branch_id, setting_key, setting_value, value_type)
    SELECT id, 'ai_anthropic_api_key_encrypted', NULL, 'string' FROM branches
ON DUPLICATE KEY UPDATE setting_key = setting_key;
