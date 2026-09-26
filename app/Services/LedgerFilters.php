<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The All Transactions filter set as URLs: pagination links that keep every
 * filter, "remove this one filter" chip links, and the active-filter count.
 *
 * Kept out of the view so the "which params survive which link" rules live in
 * one tested place — a pagination link that silently drops a filter shows
 * page 2 of a different result set.
 *
 * `status=posted` is the default (it is what a bare /transactions shows), so
 * it is never put in a URL, never chipped and never counted.
 */
final class LedgerFilters
{
    /** Chip/param groups: removing a chip removes every param in its group. */
    private const GROUPS = [
        'range' => ['range', 'from', 'to'],
        'q' => ['q'],
        'type' => ['type'],
        'account_id' => ['account_id'],
        'category_id' => ['category_id'],
        'status' => ['status'],
        'partner_id' => ['partner_id'],
        'min' => ['min'],
        'max' => ['max'],
    ];

    /**
     * @param array{
     *     range:string, from:?string, to:?string, account_id:?int, category_id:?int,
     *     partner_id:?int, type:?string, status:string, q:?string,
     *     min:?string, max:?string, page:int
     * } $filters as normalised by TransactionController, dates already resolved
     */
    public function __construct(private readonly array $filters, private readonly string $basePath)
    {
    }

    /**
     * Query params for the current filters, minus the named groups.
     *
     * A relative preset is carried as `range` (so page 2 of "This month" is
     * still this month tomorrow); a custom range as the original from/to.
     *
     * @param list<string> $without group keys from GROUPS
     * @return array<string,string|int>
     */
    public function query(array $without = [], int $page = 1): array
    {
        $range = $this->filters['range'];
        $params = [
            'range' => DateRangePreset::isRelative($range) ? $range : null,
            'from' => $range === DateRangePreset::CUSTOM ? $this->filters['from'] : null,
            'to' => $range === DateRangePreset::CUSTOM ? $this->filters['to'] : null,
            'q' => $this->filters['q'],
            'type' => $this->filters['type'],
            'account_id' => $this->filters['account_id'],
            'category_id' => $this->filters['category_id'],
            'status' => $this->filters['status'] === 'posted' ? null : $this->filters['status'],
            'partner_id' => $this->filters['partner_id'],
            'min' => $this->filters['min'],
            'max' => $this->filters['max'],
            'page' => $page > 1 ? $page : null,
        ];

        foreach ($without as $group) {
            foreach (self::GROUPS[$group] ?? [] as $param) {
                unset($params[$param]);
            }
        }

        return array_filter($params, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param list<string> $without */
    public function href(array $without = [], int $page = 1): string
    {
        $query = $this->query($without, $page);

        return $this->basePath . ($query === [] ? '' : '?' . http_build_query($query));
    }

    /** Filters that live inside the collapsed Filters panel, for its count badge. */
    public function panelCount(): int
    {
        $keys = ['type', 'account_id', 'category_id', 'partner_id', 'min', 'max'];
        $count = count(array_filter($keys, fn (string $key): bool => $this->filters[$key] !== null));

        return $count + ($this->filters['status'] === 'posted' ? 0 : 1);
    }

    /**
     * One removable chip per non-default filter, in toolbar order.
     *
     * Names come from the caller's lookups; an id with no match (a deleted
     * account in an old bookmark) still gets a chip so it can be removed.
     *
     * @param array<string,string> $typeLabels
     * @param array<int,string>    $accountNames
     * @param array<int,string>    $categoryNames
     * @param array<int,string>    $partnerNames
     * @return list<array{label:string, href:string}>
     */
    public function chips(array $typeLabels, array $accountNames, array $categoryNames, array $partnerNames): array
    {
        $filters = $this->filters;
        $labels = [];

        if ($filters['range'] !== DateRangePreset::ALL_TIME) {
            $labels['range'] = DateRangePreset::isRelative($filters['range'])
                ? DateRangePreset::options()[$filters['range']]
                : DateRangePreset::label($filters['from'], $filters['to']);
        }
        if ($filters['q'] !== null) {
            $labels['q'] = 'Search: “' . $filters['q'] . '”';
        }
        if ($filters['type'] !== null) {
            $labels['type'] = $typeLabels[$filters['type']] ?? $filters['type'];
        }
        if ($filters['account_id'] !== null) {
            $labels['account_id'] = $accountNames[$filters['account_id']] ?? 'Account #' . $filters['account_id'];
        }
        if ($filters['category_id'] !== null) {
            $labels['category_id'] = $categoryNames[$filters['category_id']]
                ?? 'Category #' . $filters['category_id'];
        }
        if ($filters['status'] !== 'posted') {
            $labels['status'] = $filters['status'] === 'void' ? 'Voided only' : 'Include voided';
        }
        if ($filters['partner_id'] !== null) {
            $labels['partner_id'] = $partnerNames[$filters['partner_id']] ?? 'Partner #' . $filters['partner_id'];
        }
        if ($filters['min'] !== null) {
            $labels['min'] = 'Min ' . $filters['min'];
        }
        if ($filters['max'] !== null) {
            $labels['max'] = 'Max ' . $filters['max'];
        }

        $chips = [];
        foreach ($labels as $group => $label) {
            $chips[] = ['label' => $label, 'href' => $this->href([$group])];
        }

        return $chips;
    }
}
