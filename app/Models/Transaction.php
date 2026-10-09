<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Reads of a single ledger row for purposes outside the ledger listing.
 *
 * Writes never come through here: TransactionService is the only way
 * anything changes `transactions`.
 */
final class Transaction
{
    /**
     * Everything a "transaction voided" email reports, in one read.
     *
     * Scoped to the branch the void happened in and to rows that really are
     * void, so a stray id can never describe another tenant's row or a row
     * whose void was rolled back. The party is whichever of partner,
     * customer or vendor the row's type carries.
     *
     * @return array<string,mixed>|null
     */
    public static function voidNotificationDetails(int $transactionId, int $branchId): ?array
    {
        return Database::instance()->first(
            "SELECT t.id, t.type, t.amount, t.transaction_date, t.description, t.reference_no,
                    t.void_reason, t.voided_at,
                    b.name AS branch_name,
                    a.name AS account_name,
                    c.name AS category_name,
                    pc.name AS parent_category_name,
                    p.name AS partner_name,
                    cu.name AS customer_name,
                    e.vendor,
                    vu.name AS voided_by_name
             FROM transactions t
             JOIN branches b ON b.id = t.branch_id
             LEFT JOIN accounts a ON a.id = t.account_id
             LEFT JOIN categories c ON c.id = t.category_id
             LEFT JOIN categories pc ON pc.id = c.parent_id
             LEFT JOIN partners p ON p.id = t.partner_id
             LEFT JOIN sales s ON s.transaction_id = t.id
             LEFT JOIN customers cu ON cu.id = s.customer_id
             LEFT JOIN expenses e ON e.transaction_id = t.id
             LEFT JOIN users vu ON vu.id = t.voided_by
             WHERE t.id = :id AND t.branch_id = :branch AND t.status = :status",
            ['id' => $transactionId, 'branch' => $branchId, 'status' => 'void']
        );
    }
}
