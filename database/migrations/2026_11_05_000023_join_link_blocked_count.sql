-- JOIN-1 review: how many submissions through a branch's joining link were
-- turned away as automated (honeypot filled, or sent faster than a person
-- could fill the form). A count only, with no personal data, shown on the
-- admin's /joiners link panel so a real joiner caught by mistake is
-- noticed. Reset to 0 whenever the link is regenerated: it counts per link.
--
-- Safe to re-run on MySQL 8 and MariaDB 10.6+: the ADD COLUMN is guarded by
-- an information_schema lookup and run through PREPARE, as in 000018/000021.

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_join_links'
        AND COLUMN_NAME = 'blocked_count') = 0,
    'ALTER TABLE employee_join_links ADD COLUMN blocked_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_active',
    'DO 0'
);
PREPARE ddl FROM @ddl;
EXECUTE ddl;
DEALLOCATE PREPARE ddl;
