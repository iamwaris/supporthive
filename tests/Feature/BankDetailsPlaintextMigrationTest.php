<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use PHPUnit\Framework\TestCase;

/**
 * Migration 000024 adds plain bank columns to employee_profiles and
 * joiner_submissions and deliberately leaves the old *_enc columns alone
 * (dropping them is a separate, pending cleanup). The file is executed
 * exactly the way database/migrate.php executes it (one PDO::exec of the
 * whole file); the test schema already has it applied, so this is a re-run.
 */
final class BankDetailsPlaintextMigrationTest extends TestCase
{
    private const MIGRATION = '2026_11_05_000024_bank_details_plaintext.sql';
    private const TABLES = ['employee_profiles', 'joiner_submissions'];

    /** @return array<string,string> column => type, for the bank columns of $table */
    private static function bankColumns(string $table): array
    {
        $rows = Database::instance()->all(
            "SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME LIKE 'bank%'
             ORDER BY ORDINAL_POSITION",
            ['t' => $table]
        );

        return array_column($rows, 'COLUMN_TYPE', 'COLUMN_NAME');
    }

    private function runMigration(): void
    {
        $path = dirname(__DIR__, 2) . '/database/migrations/' . self::MIGRATION;
        Database::instance()->pdo()->exec((string) file_get_contents($path));
    }

    public function testReRunningIsSafeAndKeepsBothThePlainAndTheOldEncColumns(): void
    {
        $before = array_map(self::bankColumns(...), self::TABLES);

        $this->runMigration();
        $this->runMigration();

        foreach (self::TABLES as $index => $table) {
            $columns = self::bankColumns($table);
            self::assertSame($before[$index], $columns, "{$table} is unchanged by a re-run");
            self::assertSame('varchar(100)', $columns['bank_name'] ?? null, $table);
            self::assertSame('varchar(24)', $columns['bank_account_number'] ?? null, $table);
            self::assertSame('varchar(24)', $columns['bank_iban'] ?? null, $table);
            foreach (['bank_name_enc', 'bank_account_number_enc', 'bank_iban_enc'] as $kept) {
                self::assertArrayHasKey($kept, $columns, "{$table}.{$kept} is left for the later cleanup");
            }
        }
    }
}
