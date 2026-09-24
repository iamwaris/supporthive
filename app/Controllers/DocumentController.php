<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Http;
use App\Core\Session;
use App\Models\Attachment;
use RuntimeException;

/**
 * Receipts, invoices and payment proofs (Module 10 / decision D-4).
 *
 * Files live in storage/documents, outside the web root entirely. show()
 * resolves an attachment **id** to a file path through the database and
 * streams the bytes itself — a client-supplied path never reaches the
 * filesystem, which is what keeps this immune to path traversal.
 */
final class DocumentController extends Controller
{
    /** Attach a file to an existing transaction. @param array<string,string> $params */
    public function store(array $params): void
    {
        $transactionId = (int) ($params['id'] ?? 0);

        if (!$this->transactionInBranch($transactionId)) {
            Http::abort(404);
        }

        $file = $_FILES['document'] ?? null;
        if (!is_array($file)) {
            Session::flash('error', 'Choose a file to attach.');
            Http::redirect('/transactions');
        }

        try {
            /** @var array{name:string,type:string,tmp_name:string,error:int,size:int} $file */
            (new Attachment())->attachUpload($transactionId, $file);
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            Http::redirect('/transactions');
        }

        Session::flash('success', 'File attached.');
        Http::redirect('/transactions');
    }

    /** @param array<string,string> $params */
    public function show(array $params): never
    {
        $id = (int) ($params['id'] ?? 0);

        // find() is branch-scoped (App\Core\Model), so an id from another
        // branch simply does not exist from here — the ownership check IS
        // the query, not an extra condition to remember.
        $attachment = (new Attachment())->find($id);
        if ($attachment === null) {
            Http::abort(404);
        }

        $path = STORAGE_PATH . '/documents/attachments/' . $attachment['stored_filename'];
        if (!is_file($path)) {
            Http::abort(404);
        }

        $filename = self::safeFilename((string) $attachment['original_filename']);

        header('Content-Type: ' . (string) $attachment['mime']);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, no-cache');

        readfile($path);
        exit;
    }

    private static function safeFilename(string $filename): string
    {
        return preg_replace('/[^A-Za-z0-9 ._-]/', '-', $filename) ?? 'document';
    }

    private function transactionInBranch(int $transactionId): bool
    {
        $branchId = Auth::branchId();
        if ($branchId === null || $transactionId <= 0) {
            return false;
        }

        return (int) Database::instance()->value(
            'SELECT COUNT(*) FROM transactions WHERE id = :id AND branch_id = :branch',
            ['id' => $transactionId, 'branch' => $branchId]
        ) > 0;
    }
}
