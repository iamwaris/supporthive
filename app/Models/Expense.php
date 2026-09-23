<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

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

        $sql = 'SELECT e.vendor, COUNT(*) AS uses
                FROM expenses e
                JOIN transactions t ON t.id = e.transaction_id
                WHERE e.vendor IS NOT NULL
                  AND e.vendor <> \'\'
                  AND t.status <> \'void\'';

        $params = [];
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
