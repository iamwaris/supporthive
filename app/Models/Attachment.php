<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Model;
use App\Core\Upload;
use App\Services\Audit;

/**
 * A receipt, invoice or payment proof linked to a transaction (Module 10).
 *
 * Holds no path derived from user input: stored_filename is whatever
 * App\Core\Upload::storePrivate() generated, and original_filename is
 * display-only, never used to touch the filesystem.
 */
final class Attachment extends Model
{
    protected string $table = 'attachments';

    /** @var list<string> */
    protected array $fillable = [
        'transaction_id', 'original_filename', 'stored_filename', 'mime', 'size', 'uploaded_by',
    ];

    /**
     * Store the uploaded file under storage/documents and record it against
     * a transaction. Throws (RuntimeException, from Upload::storePrivate())
     * on anything from an oversized file to a disallowed type — the caller
     * decides how to surface that.
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     */
    public function attachUpload(int $transactionId, array $file): void
    {
        $stored = Upload::storePrivate($file, 'attachments');
        $originalName = trim((string) ($file['name'] ?? ''));

        $name = $originalName === '' ? $stored['filename'] : mb_substr($originalName, 0, 255);
        $id = $this->create([
            'transaction_id' => $transactionId,
            'original_filename' => $name,
            'stored_filename' => $stored['filename'],
            'mime' => $stored['mime'],
            'size' => $stored['size'],
            'uploaded_by' => Auth::id(),
        ]);

        Audit::record('attachment.created', 'attachments', $id, null, [
            'transaction_id' => $transactionId,
            'original_filename' => $name,
            'mime' => $stored['mime'],
            'size' => $stored['size'],
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function forTransaction(int $transactionId): array
    {
        return $this->db()->all(
            'SELECT * FROM attachments WHERE transaction_id = :tid AND branch_id = :branch ORDER BY created_at ASC',
            ['tid' => $transactionId, 'branch' => $this->requireBranchId()]
        );
    }

    /**
     * One query for a page of listing rows rather than one per row.
     *
     * @param list<int> $transactionIds
     * @return array<int,list<array<string,mixed>>> keyed by transaction_id
     */
    public function forTransactions(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $placeholders = [];
        $params = ['branch' => $this->requireBranchId()];
        foreach (array_values($transactionIds) as $index => $id) {
            $placeholders[] = ':t' . $index;
            $params['t' . $index] = $id;
        }

        $rows = $this->db()->all(
            'SELECT * FROM attachments WHERE branch_id = :branch AND transaction_id IN ('
            . implode(', ', $placeholders) . ') ORDER BY created_at ASC',
            $params
        );

        $byTransaction = [];
        foreach ($rows as $row) {
            $byTransaction[(int) $row['transaction_id']][] = $row;
        }

        return $byTransaction;
    }
}
