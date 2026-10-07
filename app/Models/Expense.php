<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use RuntimeException;

/**
 * Expense detail: the vendor and any note.
 *
 * No amount lives here. The ledger row holds it, and duplicating it is how the
 * two end up disagreeing.
 */
final class Expense
{
    public static function write(int $transactionId, ?string $vendor, ?string $notes): void
    {
        Database::instance()->insert('expenses', [
            'transaction_id' => $transactionId,
            'vendor' => self::tidyVendor($vendor),
            'notes' => $notes === null || trim($notes) === '' ? null : trim($notes),
        ]);
    }

    /**
     * Vendor names previously used, most-used first.
     *
     * Vendors are free text by decision D-5, which keeps entry fast but makes
     * vendor reporting only as good as the typing: "ABC Traders", "ABC
     * traders" and "ABC  Trading" become three vendors and the question "what
     * did we spend at ABC this year" stops having an answer.
     *
     * This is the mitigation - the field type-aheads from what has been typed
     * before, so repeat entries converge on one spelling. It recovers most of
     * the reporting benefit without a table to maintain.
     *
     * @return list<array{vendor:string,uses:int}>
     */
    public static function vendorSuggestions(string $term, int $limit = 8): array
    {
        $term = self::tidyVendor($term) ?? '';
        $limit = max(1, min($limit, 20));

        $branchId = Auth::branchId();
        if ($branchId === null) {
            throw new RuntimeException('No active branch — cannot read vendor history.');
        }

        $sql = 'SELECT e.vendor, COUNT(*) AS uses
                FROM expenses e
                JOIN transactions t ON t.id = e.transaction_id
                WHERE t.branch_id = :branch
                  AND e.vendor IS NOT NULL
                  AND e.vendor <> \'\'
                  AND t.status <> \'void\'';

        $params = ['branch' => $branchId];
        if ($term !== '') {
            $sql .= ' AND e.vendor LIKE :term';
            $params['term'] = $term . '%';
        }

        $sql .= ' GROUP BY e.vendor ORDER BY uses DESC, e.vendor ASC LIMIT :take';
        $params['take'] = $limit;

        $rows = Database::instance()->all($sql, $params);

        return array_map(
            static fn (array $row): array => [
                'vendor' => (string) $row['vendor'],
                'uses' => (int) $row['uses'],
            ],
            $rows
        );
    }

    /**
     * Everything an "expense added" email reports, in one read.
     *
     * Scoped to the branch the expense was posted in, so a stray id can never
     * describe another tenant's expense. from_recurring is true when the row
     * was posted by approving a recurring draft rather than typed in.
     *
     * @return array<string,mixed>|null
     */
    public static function notificationDetails(int $transactionId, int $branchId): ?array
    {
        return Database::instance()->first(
            "SELECT t.id, t.amount, t.transaction_date,
                    b.name AS branch_name,
                    c.name AS category_name,
                    pc.name AS parent_category_name,
                    e.vendor,
                    u.name AS created_by_name,
                    EXISTS (
                        SELECT 1 FROM recurring_occurrences ro
                        WHERE ro.transaction_id = t.id AND ro.branch_id = t.branch_id
                    ) AS from_recurring
             FROM transactions t
             JOIN branches b ON b.id = t.branch_id
             LEFT JOIN categories c ON c.id = t.category_id
             LEFT JOIN categories pc ON pc.id = c.parent_id
             LEFT JOIN expenses e ON e.transaction_id = t.id
             LEFT JOIN users u ON u.id = t.created_by
             WHERE t.id = :id AND t.branch_id = :branch AND t.type = :type",
            ['id' => $transactionId, 'branch' => $branchId, 'type' => 'expense']
        );
    }

    /** Trim and collapse whitespace, so " ABC   Traders " and "ABC Traders" are one vendor. */
    public static function tidyVendor(?string $vendor): ?string
    {
        if ($vendor === null) {
            return null;
        }

        $tidy = trim((string) preg_replace('/\s+/u', ' ', $vendor));

        return $tidy === '' ? null : mb_substr($tidy, 0, 160);
    }
}
