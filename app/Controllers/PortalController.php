<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;

/**
 * The employee's self-service area (`can:portal`, employees only): their own
 * read-only profile and the documents their branch has published. Nothing
 * here takes an id from the request — the profile is always Auth::id()'s
 * own, and the document list is always this branch's.
 */
final class PortalController extends Controller
{
    private const PER_PAGE = 20;
    private const RECENT_DOCUMENTS = 5;

    public function index(): void
    {
        $this->view('pages/portal/home', [
            'title' => 'My workspace',
            'nav' => 'portal',
            'pageTitle' => 'My workspace',
            'pageMeta' => 'Your profile and the documents your organisation has shared with you.',
            // Null only for an employee login made outside the Employees
            // screen (no profile row); the view shows an empty state then.
            'profile' => (new EmployeeProfile())->findWithUser((int) Auth::id()),
            'documents' => (new EmployeeDocument())->recent(self::RECENT_DOCUMENTS),
        ]);
    }

    public function documents(): void
    {
        [$term, $page] = EmployeeDocumentController::listFilters();

        $documents = new EmployeeDocument();
        $total = $documents->searchCount($term);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $this->view('pages/portal/documents', [
            'title' => 'Documents',
            'nav' => 'portal-documents',
            'pageTitle' => 'Documents',
            'pageMeta' => $total === 1 ? '1 document' : number_format($total) . ' documents',
            'documents' => $documents->search($term, $page, self::PER_PAGE),
            'term' => $term,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
        ]);
    }
}
