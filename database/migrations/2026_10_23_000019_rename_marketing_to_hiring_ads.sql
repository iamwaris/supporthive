-- Rename the expense category "Marketing" to "Hiring Ads" in every branch
-- (owner decision 2026-10-23).
--
-- A rename, not a new category: transactions, budgets and recurring rules all
-- reference categories by id, so every one of them carries over untouched and
-- historical reports simply show the new name.
--
-- Which rows: type 'expense' whose name, trimmed of spaces, equals
-- 'Marketing' under the column's own collation (utf8mb4_unicode_ci, so case
-- does not matter). Top-level and sub-categories alike. Income categories are
-- never touched — the type ENUM keeps the two lists separate everywhere else.
--
-- Which branches are skipped, left exactly as they are:
--   * a branch that already has an expense category named 'Hiring Ads'
--     (anywhere in its tree) — renaming would give it two;
--   * a branch with more than one matching 'Marketing' row — renaming both
--     would give it two 'Hiring Ads'. The unique key cannot catch either case
--     for top-level rows: parent_id is NULL there and NULLs never collide.
-- Skipped branches need a human decision; the SELECT at the end of this file
-- lists them.
--
-- Each rename writes one audit_log row shaped like Audit::record() would:
-- action 'category.renamed', entity 'categories' / id, the category's own
-- branch_id, meta {"before":{"name":...},"after":{"name":"Hiring Ads"}}.
-- user_id and ip are NULL — no person made this change, and the audit viewer
-- shows a NULL user as "System". user_agent names this migration in place of
-- the 'cli' that Audit::record() falls back to, so the row says where it
-- came from.
--
-- Safe to re-run on MySQL 8 and MariaDB 10.6+: after the first run nothing
-- matches, so a second run renames nothing and audits nothing.
--
-- No temporary table: the app database user is not guaranteed the CREATE
-- TEMPORARY TABLES privilege (the local one lacks it). Instead both
-- statements join the same derived table, which selects at most one id per
-- branch. MySQL refuses an UPDATE that reads its own target table in a
-- subquery (error 1093) unless that read is materialised first; a derived
-- table with GROUP BY can never be merged into the outer query, so it always
-- is. Both statements run in one transaction, and the INSERT ... SELECT
-- locks the rows it reads, so the audit rows and the rename cover the very
-- same ids. The _utf8mb4 introducers keep the literals comparable with the
-- column even when the file is imported over a connection with a different
-- charset (phpMyAdmin).

START TRANSACTION;

INSERT INTO audit_log (user_id, branch_id, action, entity_type, entity_id, ip, user_agent, meta)
SELECT
    NULL,
    c.branch_id,
    'category.renamed',
    'categories',
    c.id,
    NULL,
    'migration:2026_10_23_000019_rename_marketing_to_hiring_ads',
    JSON_OBJECT(
        'before', JSON_OBJECT('name', c.name),
        'after', JSON_OBJECT('name', _utf8mb4'Hiring Ads')
    )
FROM categories c
JOIN (
    SELECT MIN(m.id) AS id
    FROM categories m
    WHERE m.type = 'expense'
      AND TRIM(m.name) = _utf8mb4'Marketing' COLLATE utf8mb4_unicode_ci
      AND NOT EXISTS (
          SELECT 1 FROM categories h
          WHERE h.branch_id = m.branch_id
            AND h.type = 'expense'
            AND TRIM(h.name) = _utf8mb4'Hiring Ads' COLLATE utf8mb4_unicode_ci
      )
    GROUP BY m.branch_id
    HAVING COUNT(*) = 1
) target ON target.id = c.id;

UPDATE categories c
JOIN (
    SELECT MIN(m.id) AS id
    FROM categories m
    WHERE m.type = 'expense'
      AND TRIM(m.name) = _utf8mb4'Marketing' COLLATE utf8mb4_unicode_ci
      AND NOT EXISTS (
          SELECT 1 FROM categories h
          WHERE h.branch_id = m.branch_id
            AND h.type = 'expense'
            AND TRIM(h.name) = _utf8mb4'Hiring Ads' COLLATE utf8mb4_unicode_ci
      )
    GROUP BY m.branch_id
    HAVING COUNT(*) = 1
) target ON target.id = c.id
SET c.name = _utf8mb4'Hiring Ads';

COMMIT;

-- ---------------------------------------------------------------------------
-- For a human, after deploying: branches this migration deliberately skipped.
-- Read-only; run it by hand. An empty result means every branch was renamed.
--
-- SELECT c.branch_id, b.name AS branch, c.id, c.name, c.parent_id, c.is_active
-- FROM categories c
-- JOIN branches b ON b.id = c.branch_id
-- WHERE c.type = 'expense'
--   AND TRIM(c.name) = _utf8mb4'Marketing' COLLATE utf8mb4_unicode_ci
-- ORDER BY c.branch_id, c.id;
--
-- Any row it returns is a 'Marketing' that was left alone, because its branch
-- already has a 'Hiring Ads' expense category or has more than one
-- 'Marketing'. Merging those is an owner decision, not something to automate.
-- ---------------------------------------------------------------------------
