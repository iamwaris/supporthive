<?php

/**
 * Forward-only SQL migration runner.
 *
 * Usage (from the project root):
 *   php database/migrate.php            apply pending migrations
 *   php database/migrate.php --status    list applied / pending
 *
 * CLI only: shared hosting has no shell, so run it locally against the remote
 * database, or import the .sql files through phpMyAdmin. It must never be
 * reachable over HTTP.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$db = Database::instance();

$db->run(
    'CREATE TABLE IF NOT EXISTS migrations (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        filename   VARCHAR(255) NOT NULL,
        batch      INT UNSIGNED NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_migrations_filename (filename)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = array_column($db->all('SELECT filename FROM migrations'), 'filename');
$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files);

$pending = array_values(array_filter(
    $files,
    static fn (string $f): bool => !in_array(basename($f), $applied, true)
));

if (in_array('--status', $argv, true)) {
    echo "Applied:\n";
    foreach ($applied as $name) {
        echo "  [x] {$name}\n";
    }
    echo "Pending:\n";
    foreach ($pending as $file) {
        echo '  [ ] ' . basename($file) . "\n";
    }
    exit(0);
}

if ($pending === []) {
    echo "Nothing to migrate.\n";
    exit(0);
}

$batch = (int) $db->value('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations');

foreach ($pending as $file) {
    $name = basename($file);
    $sql = (string) file_get_contents($file);

    echo "Applying {$name} ... ";

    try {
        // DDL is not transactional in MySQL, so each file must be individually
        // safe to re-run: write CREATE TABLE IF NOT EXISTS / ADD COLUMN guards.
        $db->pdo()->exec($sql);
        $db->insert('migrations', ['filename' => $name, 'batch' => $batch]);
        echo "ok\n";
    } catch (Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, $e->getMessage() . "\n");
        fwrite(STDERR, "Migration halted. Fix the file and re-run.\n");
        exit(1);
    }
}

echo "Done (batch {$batch}).\n";
