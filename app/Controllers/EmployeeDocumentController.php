<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Session;
use App\Core\Validator;
use App\Models\EmployeeDocument;
use RuntimeException;

/**
 * Documents an admin publishes to every employee of their branch.
 *
 * index/store/destroy are admin only (`can:administer`); view/download are
 * open to anyone who may read employee documents (`can:employee-documents`:
 * employees, plus the admins checking what they published). Every id is
 * resolved through EmployeeDocument::find(), which is branch-scoped, and the
 * file is streamed from storage/documents — a client-supplied path never
 * reaches the filesystem.
 */
final class EmployeeDocumentController extends Controller
{
    private const PER_PAGE = 20;

    public function index(): void
    {
        [$term, $page] = self::listFilters();

        $documents = new EmployeeDocument();
        $total = $documents->searchCount($term);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $this->view('pages/employee-documents', [
            'title' => 'Employee Documents',
            'nav' => 'employee-documents',
            'pageTitle' => 'Employee Documents',
            'pageMeta' => 'PDFs every employee of this branch can preview and download.',
            'documents' => $documents->search($term, $page, self::PER_PAGE),
            'term' => $term,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
        ]);
    }

    public function store(): void
    {
        $clean = $this->validate([
            'title' => 'required|max:150',
            'description' => 'nullable|max:1000',
        ], '/employee-documents');

        $file = $_FILES['document'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->failUpload('Choose a PDF to upload.');
        }

        try {
            /** @var array{name:string,type:string,tmp_name:string,error:int,size:int} $file */
            (new EmployeeDocument())->storeUpload([
                'title' => trim((string) $clean['title']),
                'description' => $clean['description'] === null ? null : trim((string) $clean['description']),
            ], $file);
        } catch (RuntimeException $e) {
            // Upload's messages are written for the user ("larger than the
            // 10 MB limit", "not allowed"); nothing internal is in them.
            $this->failUpload($e->getMessage());
        }

        Session::flash('success', 'Document published to every employee of this branch.');
        Http::redirect('/employee-documents');
    }

    /** @param array<string,string> $params */
    public function destroy(array $params): void
    {
        if (!(new EmployeeDocument())->deleteWithFile((int) ($params['id'] ?? 0))) {
            Http::abort(404);
        }

        Session::flash('success', 'Document deleted. Employees can no longer open it.');
        Http::redirect('/employee-documents');
    }

    /** Preview: opens inline, as a top-level page in a new tab. @param array<string,string> $params */
    public function preview(array $params): never
    {
        $this->stream($params, true);
    }

    /** @param array<string,string> $params */
    public function download(array $params): never
    {
        $this->stream($params, false);
    }

    /**
     * Search term and page for a document list; a tampered value falls back
     * to its default. Shared with the employee portal's list.
     *
     * @return array{0:?string,1:int}
     */
    public static function listFilters(): array
    {
        $input = [];
        foreach (['q', 'page'] as $key) {
            if (isset($_GET[$key]) && is_string($_GET[$key])) {
                $input[$key] = $_GET[$key];
            }
        }

        $filters = Validator::make($input, [
            'q' => 'nullable|max:100',
            'page' => 'nullable|int|between:1,100000',
        ])->validated();

        return [
            isset($filters['q']) ? (string) $filters['q'] : null,
            isset($filters['page']) ? (int) $filters['page'] : 1,
        ];
    }

    /** @param array<string,string> $params */
    private function stream(array $params, bool $inline): never
    {
        $id = (int) ($params['id'] ?? 0);
        $document = (new EmployeeDocument())->find($id);
        if ($document === null) {
            Http::abort(404);
        }

        try {
            $path = EmployeeDocument::filePath($document);
        } catch (RuntimeException $e) {
            Logger::error('Employee document has an invalid stored filename', ['document_id' => $id]);
            Http::abort(404);
        }

        if (!is_file($path)) {
            Logger::error('Employee document file missing from storage', ['document_id' => $id]);
            Http::abort(404);
        }

        Http::sendFile($path, 'application/pdf', (string) $document['original_filename'], $inline);
    }

    private function failUpload(string $message): never
    {
        $old = $_POST;
        unset($old[Csrf::FIELD]);
        Session::set('_old', $old);
        Session::flash('errors', ['document' => [$message]]);
        Session::flash('error', 'The document was not uploaded.');
        Http::redirect('/employee-documents');
    }
}
