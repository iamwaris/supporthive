<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LedgerFilters;
use PHPUnit\Framework\TestCase;

/**
 * Pagination and chip links are built from the same filter set; a link that
 * drops or keeps the wrong param silently shows a different result set.
 */
final class LedgerFiltersTest extends TestCase
{
    /**
     * @param array<string,mixed> $overrides
     */
    private function filters(array $overrides = []): LedgerFilters
    {
        /** @var array{range:string, from:?string, to:?string, account_id:?int, category_id:?int, partner_id:?int, type:?string, status:string, q:?string, min:?string, max:?string, page:int} $filters */
        $filters = array_merge([
            'range' => 'all',
            'from' => null,
            'to' => null,
            'account_id' => null,
            'category_id' => null,
            'partner_id' => null,
            'type' => null,
            'status' => 'posted',
            'q' => null,
            'min' => null,
            'max' => null,
            'page' => 1,
        ], $overrides);

        return new LedgerFilters($filters, '/transactions');
    }

    public function testDefaultsProduceABareUrlNoChipsAndZeroCount(): void
    {
        $filters = $this->filters();

        self::assertSame('/transactions', $filters->href());
        self::assertSame([], $filters->chips([], [], [], []));
        self::assertSame(0, $filters->panelCount());
    }

    public function testPaginationKeepsEveryFilterAndDropsPageOne(): void
    {
        $filters = $this->filters([
            'range' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-27',
            'account_id' => 3, 'category_id' => 7, 'partner_id' => 2, 'type' => 'expense',
            'status' => 'all', 'q' => 'rent', 'min' => '10', 'max' => '500',
        ]);

        self::assertSame(
            [
                'from' => '2026-09-01', 'to' => '2026-09-27', 'q' => 'rent', 'type' => 'expense',
                'account_id' => 3, 'category_id' => 7, 'status' => 'all', 'partner_id' => 2,
                'min' => '10', 'max' => '500', 'page' => 2,
            ],
            $filters->query([], 2)
        );
        self::assertArrayNotHasKey('page', $filters->query([], 1));
    }

    public function testRelativePresetTravelsAsRangeNotResolvedDates(): void
    {
        $filters = $this->filters(['range' => 'this_month', 'from' => '2026-09-01', 'to' => '2026-09-30']);

        self::assertSame('/transactions?range=this_month&page=3', $filters->href([], 3));
    }

    public function testPostedStatusIsTheDefaultAndNeverChippedOrCounted(): void
    {
        $posted = $this->filters(['status' => 'posted']);
        $voided = $this->filters(['status' => 'void']);

        self::assertSame([], $posted->chips([], [], [], []));
        self::assertSame(0, $posted->panelCount());
        self::assertSame([['label' => 'Voided only', 'href' => '/transactions']], $voided->chips([], [], [], []));
        self::assertSame(1, $voided->panelCount());
    }

    public function testEachChipRemovesOnlyItsOwnFilterAndResetsThePage(): void
    {
        $filters = $this->filters([
            'range' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-27',
            'category_id' => 7, 'q' => 'rent', 'page' => 4,
        ]);

        $chips = $filters->chips([], [], [7 => 'Marketing'], []);

        self::assertSame(
            [
                ['label' => '1 Sep – 27 Sep 2026', 'href' => '/transactions?q=rent&category_id=7'],
                ['label' => 'Search: “rent”', 'href' => '/transactions?from=2026-09-01&to=2026-09-27&category_id=7'],
                ['label' => 'Marketing', 'href' => '/transactions?from=2026-09-01&to=2026-09-27&q=rent'],
            ],
            $chips
        );
    }

    public function testChipLabelsFallBackWhenALookupIsMissing(): void
    {
        $chips = $this->filters(['account_id' => 99, 'type' => 'expense', 'range' => 'last_month'])
            ->chips(['expense' => 'Expense'], [], [], []);

        self::assertSame(['Last month', 'Expense', 'Account #99'], array_column($chips, 'label'));
    }

    public function testPanelCountExcludesSearchAndDates(): void
    {
        $filters = $this->filters([
            'range' => 'this_month', 'q' => 'rent', 'type' => 'income', 'min' => '5', 'max' => '9',
        ]);

        self::assertSame(3, $filters->panelCount());
    }
}
