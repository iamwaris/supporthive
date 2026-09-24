<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use RuntimeException;

/**
 * Income detail: which customer, which invoice.
 *
 * No amount here either — and no payment status: whether the money has arrived
 * is the ledger row's `status`, so there is exactly one answer to "has this
 * been received".
 */
final class Sale
{
    public static function write(int $transactionId, ?int $customerId, ?string $invoiceNo, ?string $notes): void
    {
        Database::instance()->insert('sales', [
            'transaction_id' => $transactionId,
            'customer_id' => $customerId !== null && $customerId > 0 ? $customerId : null,
            'invoice_no' => $invoiceNo === null || trim($invoiceNo) === '' ? null : trim($invoiceNo),
            'notes' => $notes === null || trim($notes) === '' ? null : trim($notes),
        ]);
    }

    /** Invoice numbers should not silently repeat; the caller decides what to do about it. */
    public static function invoiceExists(string $invoiceNo): bool
    {
        $branchId = Auth::branchId();
        if ($branchId === null) {
            throw new RuntimeException('No active branch — cannot check invoice numbers.');
        }

        return (int) Database::instance()->value(
            'SELECT COUNT(*) FROM sales s
             JOIN transactions t ON t.id = s.transaction_id
             WHERE t.branch_id = :branch AND s.invoice_no = :inv AND t.status <> \'void\'',
            ['branch' => $branchId, 'inv' => trim($invoiceNo)]
        ) > 0;
    }
}
