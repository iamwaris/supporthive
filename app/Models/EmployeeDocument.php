<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Model;
use App\Core\Upload;
use App\Services\Audit;
use RuntimeException;
use Throwable;

/**
 * A PDF an admin publishes to every employee of their branch.
 *
 * Branch-scoped through App\Core\Model like every other table, so find($id)
 * is the ownership check for preview, download and delete alike. The file
 * lives in storage/documents/employee-documents (outside the web root) and
 * is only ever located through filePath(), from the generated
 * stored_filename — the uploader's original filename is display-only.
 */
final class EmployeeDocument extends Model
{
    private const SUBDIRECTORY = 'employee-documents';

    protected string $table = 'employee_documents';

    /** @var list<string> */
    protected array $fillable = [
        'title', 'description', 'original_filename', 'stored_filename', 'size', 'uploaded_by',
    ];

    /**
     * Store the PDF, then record it. Throws RuntimeException (from
     * Upload::storePrivatePdf()) for anything from an oversized file to a
     * non-PDF; if the insert itself fails the stored file is removed so no
     * orphan is left on disk.
     *
     * @param array{title:string,description:?string} $clean
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     */
    public function storeUpload(array $clean, array $file): int
    {
        $stored = Upload::storePrivatePdf($file, self::SUBDIRECTORY);
        $originalName = trim((string) ($file['name'] ?? ''));
        $originalName = $originalName === '' ? $stored['filename'] : mb_substr($originalName, 0, 255);

        try {
            $id = $this->create([
                'title' => $clean['title'],
                'description' => $clean['description'],
                'original_filename' => $originalName,
                'stored_filename' => $stored['filename'],
                'size' => $stored['size'],
                'uploaded_by' => Auth::id(),
            ]);
        } catch (Throwable $e) {
            $orphan = self::filePath(['stored_filename' => $stored['filename']]);
            if (is_file($orphan)) {
                unlink($orphan);
            }
            throw $e;
        }

        Audit::record('employee_document.created', 'employee_documents', $id, null, [
            'title' => $clean['title'],
            'original_filename' => $originalName,
            'size' => $stored['size'],
        ]);

        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function search(?string $term, int $page, int $perPage): array
    {
        $perPage = max(1, min($perPage, 100));
        [$where, $params] = $this->searchConditions($term);

        return $this->db()->all(
            "SELECT d.id, d.title, d.description, d.original_filename, d.size, d.created_at,
                    u.name AS uploaded_by_name
             FROM employee_documents d
             LEFT JOIN users u ON u.id = d.uploaded_by
             WHERE {$where}
             ORDER BY d.created_at DESC, d.id DESC
             LIMIT :take OFFSET :skip",
            $params + ['take' => $perPage, 'skip' => max(0, ($page - 1) * $perPage)]
        );
    }

    public function searchCount(?string $term): int
    {
        [$where, $params] = $this->searchConditions($term);

        return (int) $this->db()->value("SELECT COUNT(*) FROM employee_documents d WHERE {$where}", $params);
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit): array
    {
        return $this->search(null, 1, $limit);
    }

    /**
     * Delete the row, and its file once the delete is durable — removing the
     * file first would leave a row pointing at nothing if the delete failed.
     */
    public function deleteWithFile(int $id): bool
    {
        $document = $this->find($id);
        if ($document === null) {
            return false;
        }

        $path = self::filePath($document);

        Database::instance()->transaction(function (Database $db) use ($id, $document, $path): void {
            $this->deleteById($id);

            Audit::record('employee_document.deleted', 'employee_documents', $id, [
                'title' => $document['title'],
                'original_filename' => $document['original_filename'],
            ], null);

            $db->afterCommit(static function () use ($path, $id): void {
                if (is_file($path) && !unlink($path)) {
                    Logger::error('Employee document file could not be removed', ['document_id' => $id]);
                }
            });
        });

        return true;
    }

    /**
     * The one place a stored filename becomes a filesystem path. The pattern
     * is exactly what App\Core\Upload generates, so a tampered row can never
     * point outside the employee-documents directory.
     *
     * @param array<string,mixed> $document
     */
    public static function filePath(array $document): string
    {
        $name = (string) ($document['stored_filename'] ?? '');
        if (preg_match('/^[a-f0-9]{32}\.pdf$/', $name) !== 1) {
            throw new RuntimeException('Invalid stored filename for an employee document.');
        }

        return STORAGE_PATH . '/documents/' . self::SUBDIRECTORY . '/' . $name;
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function searchConditions(?string $term): array
    {
        $conditions = ['d.branch_id = :branch'];
        $params = ['branch' => $this->requireBranchId()];

        $term = trim((string) $term);
        if ($term !== '') {
            // One placeholder per use: a repeated named placeholder fails with
            // HY093 under native prepares (see LedgerQuery::search()).
            $pattern = '%' . addcslashes($term, '%_\\') . '%';
            $conditions[] = '(d.title LIKE :q1 OR d.description LIKE :q2)';
            $params += ['q1' => $pattern, 'q2' => $pattern];
        }

        return [implode(' AND ', $conditions), $params];
    }
}
