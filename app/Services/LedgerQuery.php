<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\TransactionType;
use InvalidArgumentException;

/**
 * Every read of the ledger goes through here.
 *
 * The reason this class exists is the decision to void by status flag rather
 * than by posting a reversing entry. That choice is simpler and keeps history
 * readable, but it has one sharp edge: any aggregate that forgets
 * `WHERE status = 'posted'` silently counts voided rows, and nothing visibly
 * breaks — the totals are just wrong.
 *
 * So the filter is applied in the constructor and there is no way to drop it
 * except includeVoided(), which has to be called by name. A hand-written
 * SUM() over `transactions` is a review failure; this is what to use instead.
 *
 * Filters are accumulated as bound parameters. Column names are fixed literals
 * in this file and never come from input, because an identifier cannot be
 * bound as a parameter.
 */
final class LedgerQuery
{
    /** @var list<string> */
    private array $conditions = [];

    /** @var array<string,mixed> */
    private array $params = [];

    private int $placeholderCount = 0;

    private string $orderBy = 'COALESCE(t.received_at, t.transaction_date) DESC, t.id DESC';

    private function __construct(bool $postedOnly)
    {
        if ($postedOnly) {
            $this->conditions[] = "t.status = 'posted'";
        }
    }

    /** The default and the one to use: voided rows are excluded. */
    public static function posted(): self
    {
        return new self(true);
    }

    /**
     * Include voided rows.
     *
     * Only for showing history to a person — the "include voided" filter on
     * the ledger screen. Never for a total that anyone will act on.
     */
    public static function includeVoided(): self
    {
        return new self(false);
    }

    public function voidedOnly(): self
    {
        $this->conditions[] = "t.status = 'void'";
        return $this;
    }

    /**
     * Money invoiced but not yet received.
     *
     * Deliberately separate from posted(): under a cash basis these are NOT
     * revenue and must never reach a profit figure. They belong on their own
     * list, and in the "expected income" tile.
     */
    public function pendingOnly(): self
    {
        $this->conditions[] = "t.status = 'pending'";
        return $this;
    }

    /**
     * Date-filters on the effective date: `received_at` when set, else
     * `transaction_date`. Cash basis means the date money arrived is the one
     * every report and balance filters on (TransactionService::post() sets
     * `received_at` for anything posted immediately; markReceived() sets it
     * the day a pending invoice is confirmed) — a row's original invoice date
     * must never keep it stuck in a month it did not actually land in.
     * `received_at` is null for every non-income type, so this is a no-op for
     * them: they always fall back to `transaction_date`.
     */
    public function between(?string $from, ?string $to): self
    {
        if ($from !== null && $from !== '') {
            $this->conditions[] = 'COALESCE(t.received_at, t.transaction_date) >= ' . $this->bind($this->date($from));
        }
        if ($to !== null && $to !== '') {
            $this->conditions[] = 'COALESCE(t.received_at, t.transaction_date) <= ' . $this->bind($this->date($to));
        }
        return $this;
    }

    public function account(?int $accountId): self
    {
        if ($accountId !== null && $accountId > 0) {
            $this->conditions[] = 't.account_id = ' . $this->bind($accountId);
        }
        return $this;
    }

    public function category(?int $categoryId): self
    {
        if ($categoryId !== null && $categoryId > 0) {
            // Selecting a parent category includes everything filed under it,
            // which is what a person means by "show me Salaries".
            $this->conditions[] = '(t.category_id = ' . $this->bind($categoryId)
                . ' OR t.category_id IN (SELECT id FROM categories WHERE parent_id = '
                . $this->bind($categoryId) . '))';
        }
        return $this;
    }

    public function partner(?int $partnerId): self
    {
        if ($partnerId !== null && $partnerId > 0) {
            $this->conditions[] = 't.partner_id = ' . $this->bind($partnerId);
        }
        return $this;
    }

    public function createdBy(?int $userId): self
    {
        if ($userId !== null && $userId > 0) {
            $this->conditions[] = 't.created_by = ' . $this->bind($userId);
        }
        return $this;
    }

    /** @param list<TransactionType>|list<string> $types */
    public function types(array $types): self
    {
        if ($types === []) {
            return $this;
        }

        $placeholders = [];
        foreach ($types as $type) {
            $value = $type instanceof TransactionType ? $type->value : (string) $type;

            if (TransactionType::tryFrom($value) === null) {
                throw new InvalidArgumentException("Unknown transaction type: {$value}");
            }

            $placeholders[] = $this->bind($value);
        }

        $this->conditions[] = 't.type IN (' . implode(', ', $placeholders) . ')';
        return $this;
    }

    /** Restrict to the types that belong in profit and loss — never transfers or capital. */
    public function profitAndLossOnly(): self
    {
        return $this->types(TransactionType::profitAndLossValues());
    }

    public function revenueOnly(): self
    {
        return $this->types(TransactionType::revenueValues());
    }

    public function expensesOnly(): self
    {
        return $this->types(TransactionType::expenseValues());
    }

    /** Free-text across the fields a person actually remembers. */
    public function search(?string $term): self
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $this;
        }

        $like = $this->bind('%' . $term . '%');
        $this->conditions[] = '(t.description LIKE ' . $like . ' OR t.reference_no LIKE ' . $like . ')';
        return $this;
    }

    public function amountBetween(?string $min, ?string $max): self
    {
        if ($min !== null && $min !== '' && is_numeric($min)) {
            $this->conditions[] = 't.amount >= ' . $this->bind($min);
        }
        if ($max !== null && $max !== '' && is_numeric($max)) {
            $this->conditions[] = 't.amount <= ' . $this->bind($max);
        }
        return $this;
    }

    public function transferGroup(string $group): self
    {
        $this->conditions[] = 't.transfer_group = ' . $this->bind($group);
        return $this;
    }

    /** Column is matched against a fixed allow-list: an identifier cannot be bound. */
    public function orderBy(string $column, string $direction = 'DESC'): self
    {
        $allowed = [
            'date' => 'COALESCE(t.received_at, t.transaction_date)',
            'amount' => 't.amount',
            'created' => 't.created_at',
            'type' => 't.type',
        ];

        if (!isset($allowed[$column])) {
            throw new InvalidArgumentException("Cannot order by: {$column}");
        }

        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        $this->orderBy = $allowed[$column] . ' ' . $direction . ', t.id ' . $direction;

        return $this;
    }

    /** Net movement: positive money in, negative money out. */
    public function netAmount(): string
    {
        $value = Database::instance()->value(
            'SELECT COALESCE(SUM(t.amount * t.direction), 0) FROM transactions t WHERE ' . $this->where(),
            $this->params
        );

        return (string) ($value ?? '0.00');
    }

    /** Unsigned total of the matching rows. */
    public function totalAmount(): string
    {
        $value = Database::instance()->value(
            'SELECT COALESCE(SUM(t.amount), 0) FROM transactions t WHERE ' . $this->where(),
            $this->params
        );

        return (string) ($value ?? '0.00');
    }

    public function count(): int
    {
        return (int) Database::instance()->value(
            'SELECT COUNT(*) FROM transactions t WHERE ' . $this->where(),
            $this->params
        );
    }

    /**
     * A page of rows with the names a listing needs, joined rather than
     * fetched per row — N+1 on a transaction list is felt immediately.
     *
     * @return list<array<string,mixed>>
     */
    public function page(int $page = 1, int $perPage = 25): array
    {
        $perPage = max(1, min($perPage, 200));
        $offset = max(0, ($page - 1) * $perPage);

        $sql = 'SELECT t.*,
                       a.name  AS account_name,
                       c.name  AS category_name,
                       pc.name AS parent_category_name,
                       p.name  AS partner_name,
                       u.name  AS created_by_name,
                       v.name  AS voided_by_name
                FROM transactions t
                JOIN accounts a         ON a.id  = t.account_id
                LEFT JOIN categories c  ON c.id  = t.category_id
                LEFT JOIN categories pc ON pc.id = c.parent_id
                LEFT JOIN partners p    ON p.id  = t.partner_id
                JOIN users u            ON u.id  = t.created_by
                LEFT JOIN users v       ON v.id  = t.voided_by
                WHERE ' . $this->where() . '
                ORDER BY ' . $this->orderBy . '
                LIMIT :take OFFSET :skip';

        return Database::instance()->all($sql, $this->params + ['take' => $perPage, 'skip' => $offset]);
    }

    /**
     * Totals grouped by category, for the expense-by-category breakdown.
     *
     * @return list<array<string,mixed>>
     */
    public function groupedByCategory(): array
    {
        $sql = 'SELECT COALESCE(pc.id, c.id) AS category_id,
                       COALESCE(pc.name, c.name) AS category_name,
                       SUM(t.amount) AS total,
                       COUNT(*) AS entries
                FROM transactions t
                LEFT JOIN categories c  ON c.id  = t.category_id
                LEFT JOIN categories pc ON pc.id = c.parent_id
                WHERE ' . $this->where() . '
                GROUP BY COALESCE(pc.id, c.id), COALESCE(pc.name, c.name)
                ORDER BY total DESC';

        return Database::instance()->all($sql, $this->params);
    }

    private function where(): string
    {
        return $this->conditions === [] ? '1' : implode(' AND ', $this->conditions);
    }

    private function bind(mixed $value): string
    {
        $key = 'p' . $this->placeholderCount++;
        $this->params[$key] = $value;

        return ':' . $key;
    }

    private function date(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new InvalidArgumentException("Not a valid date: {$value}");
        }

        return date('Y-m-d', $timestamp);
    }
}
