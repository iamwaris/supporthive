<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Domain\ReceivablePayableKind;
use App\Domain\ReceivablePayableRules;

/**
 * Rows of the receivables / payables register.
 *
 * Reads only. Every write goes through App\Services\ReceivablePayableService,
 * which owns the state rules and the audit trail. Every query here carries
 * both the branch and the kind, so an id from another branch — or a payable
 * id typed under /receivables — is simply not found.
 */
final class ReceivablePayable extends Model
{
    protected string $table = 'receivables_payables';

    /** @var list<string> */
    protected array $fillable = [
        'kind', 'party_name', 'reference_no', 'description', 'amount', 'account_id', 'issue_transaction_id',
        'issue_date', 'due_date', 'status', 'settled_on', 'settlement_account_id', 'settlement_transaction_id',
        'settlement_note', 'settled_by', 'settled_at',
        'cancel_reason', 'cancelled_by', 'cancelled_at', 'created_by', 'modified_by', 'modified_at',
    ];

    /** Status filter values the list page accepts; 'all' means no status condition. */
    public const FILTERS = [
        ReceivablePayableRules::STATUS_OPEN,
        ReceivablePayableRules::STATUS_SETTLED,
        ReceivablePayableRules::STATUS_CANCELLED,
        'all',
    ];

    /** @return array<string,mixed>|null */
    public function findOfKind(int $id, ReceivablePayableKind $kind): ?array
    {
        $row = $this->find($id);

        return $row !== null && (string) $row['kind'] === $kind->value ? $row : null;
    }

    /** @return list<array<string,mixed>> newest issue first, with its accounts and who settled/cancelled it */
    public function listing(
        ReceivablePayableKind $kind,
        string $status,
        string $search,
        int $page,
        int $perPage
    ): array {
        [$where, $params] = $this->listingConditions($kind, $status, $search);
        $params['limit'] = max(1, min($perPage, 100));
        $params['offset'] = max(0, ($page - 1) * $params['limit']);

        return $this->db()->all(
            'SELECT r.*, su.name AS settled_by_name, cu.name AS cancelled_by_name,
                    a.name AS account_name, sa.name AS settlement_account_name
             FROM receivables_payables r
             LEFT JOIN accounts a ON a.id = r.account_id
             LEFT JOIN accounts sa ON sa.id = r.settlement_account_id
             LEFT JOIN users su ON su.id = r.settled_by
             LEFT JOIN users cu ON cu.id = r.cancelled_by
             WHERE ' . $where . '
             ORDER BY r.issue_date DESC, r.id DESC
             LIMIT :limit OFFSET :offset',
            $params
        );
    }

    public function listingCount(ReceivablePayableKind $kind, string $status, string $search): int
    {
        [$where, $params] = $this->listingConditions($kind, $status, $search);

        return (int) $this->db()->value('SELECT COUNT(*) FROM receivables_payables r WHERE ' . $where, $params);
    }

    /**
     * Open and overdue totals for the summary cards. Unaffected by the list's
     * filters on purpose: the cards answer "what is outstanding", whatever
     * page of history is being looked at.
     *
     * @return array{open_total:string,open_count:int,overdue_total:string,overdue_count:int}
     */
    public function summary(ReceivablePayableKind $kind, string $today): array
    {
        $row = $this->db()->first(
            "SELECT COALESCE(SUM(amount), 0) AS open_total,
                    COUNT(*) AS open_count,
                    COALESCE(SUM(CASE WHEN due_date < :today1 THEN amount ELSE 0 END), 0) AS overdue_total,
                    COALESCE(SUM(CASE WHEN due_date < :today2 THEN 1 ELSE 0 END), 0) AS overdue_count
             FROM receivables_payables
             WHERE branch_id = :branch AND kind = :kind AND status = 'open'",
            [
                'branch' => $this->requireBranchId(),
                'kind' => $kind->value,
                'today1' => $today,
                'today2' => $today,
            ]
        ) ?? [];

        return [
            'open_total' => (string) ($row['open_total'] ?? '0'),
            'open_count' => (int) ($row['open_count'] ?? 0),
            'overdue_total' => (string) ($row['overdue_total'] ?? '0'),
            'overdue_count' => (int) ($row['overdue_count'] ?? 0),
        ];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function listingConditions(ReceivablePayableKind $kind, string $status, string $search): array
    {
        $conditions = ['r.branch_id = :branch', 'r.kind = :kind'];
        $params = ['branch' => $this->requireBranchId(), 'kind' => $kind->value];

        if (in_array($status, ReceivablePayableRules::STATUSES, true)) {
            $conditions[] = 'r.status = :status';
            $params['status'] = $status;
        }

        if ($search !== '') {
            $conditions[] = 'r.party_name LIKE :search';
            $params['search'] = '%' . addcslashes($search, '%_\\') . '%';
        }

        return [implode(' AND ', $conditions), $params];
    }
}
