-- Force a password change on first login for any newly created account.
--
-- DEFAULT 0 is deliberate: every EXISTING row gets 0 (no forced change) when
-- this column is added, so no current session is locked out retroactively.
-- Only code that creates or resets a password (scripts/create-user.php, and
-- any future in-app user creation) sets it to 1 going forward — see
-- App\Core\Auth::requireLogin(), which is where it is enforced.
ALTER TABLE users
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;
