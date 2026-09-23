-- Local development database setup.
--
-- Run once as a MySQL admin user:
--   C:\xampp\mysql\bin\mysql.exe -u root -p < database/setup-local.sql
-- or paste it into phpMyAdmin's SQL tab.
--
-- This creates the schema and a least-privilege application user. The app
-- account deliberately has no DROP, CREATE, ALTER or GRANT: SQL injection in
-- application code then cannot destroy the schema, only touch rows. Schema
-- changes are applied by an admin user running database/migrate.php.
--
-- Change 'ChangeMe_local_only' before running, and put the same value in
-- DB_PASS in your .env. This password is for localhost only - production
-- credentials live in GitHub Actions secrets and never in a file.

CREATE DATABASE IF NOT EXISTS supporthive
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'supporthive'@'localhost'
    IDENTIFIED BY 'ChangeMe_local_only';

GRANT SELECT, INSERT, UPDATE, DELETE ON supporthive.* TO 'supporthive'@'localhost';

-- The migration runner needs to create tables, so grant DDL to it separately
-- only while migrating. For local convenience it is granted here; on the
-- production host, run migrations as the cPanel database user instead and keep
-- the runtime user restricted.
GRANT CREATE, ALTER, INDEX, REFERENCES ON supporthive.* TO 'supporthive'@'localhost';

FLUSH PRIVILEGES;

SELECT 'supporthive database and user ready' AS status;
