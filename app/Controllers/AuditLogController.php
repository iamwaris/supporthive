<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\User;
use App\Services\Audit;

/**
 * M7-4: the audit log viewer. Read-only, admin only (`can:administer`), and
 * scoped to the acting admin's own branch — every App\Services\Audit query
 * behind this screen filters on Auth::branchId() the same way every other
 * branch-scoped read in the app does.
 */
final class AuditLogController extends Controller
{
    private const PER_PAGE = 25;

    public function index(): void
    {
        $filters = $this->readFilters();
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $total = Audit::searchCount($filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        $this->view('pages/audit-log', [
            'title' => 'Audit Log',
            'nav' => 'audit-log',
            'pageTitle' => 'Audit Log',
            'pageMeta' => $total === 1 ? '1 entry' : number_format($total) . ' entries',
            'rows' => Audit::search($filters, $page, self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
            'filters' => $filters,
            'actions' => Audit::distinctActions(),
            'entityTypes' => Audit::distinctEntityTypes(),
            'users' => (new User())->allOrdered(),
        ]);
    }

    /** @return array{entity_type:?string,action:?string,user_id:?int,from:?string,to:?string,page:int} */
    private function readFilters(): array
    {
        return [
            'entity_type' => $this->stringOrNull($_GET['entity_type'] ?? null),
            'action' => $this->stringOrNull($_GET['action'] ?? null),
            'user_id' => $this->intOrNull($_GET['user_id'] ?? null),
            'from' => $this->stringOrNull($_GET['from'] ?? null),
            'to' => $this->stringOrNull($_GET['to'] ?? null),
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
