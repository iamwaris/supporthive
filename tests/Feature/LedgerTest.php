<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Domain\TransactionType;
use App\Services\LedgerQuery;
use App\Services\TransactionService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Tests\Support\BranchFixture;

/**
 * Ledger invariants.
 *
 * Every balance, dashboard figure and report in the application is an
 * aggregate over this one table, so these are the properties that everything
 * downstream quietly assumes. Several map directly onto the spec's acceptance
 * criteria: transfers must not inflate income or expenses, capital movements
 * stay out of operating results, and a posted row is voided rather than
 * deleted.
 */
final class LedgerTest extends TestCase
{
    private int $branchId;
    private int $userId;
    private int $bankId;
    private int $cashId;
    private int $expenseCategoryId;
    private int $incomeCategoryId;
    private int $partnerId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $db = Database::instance();

        $this->branchId = BranchFixture::create('LT');

        $this->userId = $db->insert('users', [
            'name' => 'Ledger Tester',
            'email' => 'ledger-tester@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
        ]);

        // Auth::id()/branchId() read the session, and posting is attributed
        // to a user within a branch.
        $_SESSION['_auth_user_id'] = $this->userId;
        $_SESSION['_active_branch_id'] = $this->branchId;

        $this->bankId = $db->insert('accounts', [
            'branch_id' => $this->branchId,
            'name' => 'LT Bank',
            'type' => 'bank',
            'opening_balance' => '1000.00',
            'opening_date' => '2026-01-01',
            'is_active' => 1,
        ]);
        $this->cashId = $db->insert('accounts', [
            'branch_id' => $this->branchId,
            'name' => 'LT Cash',
            'type' => 'cash',
            'opening_balance' => '0.00',
            'opening_date' => '2026-01-01',
            'is_active' => 1,
        ]);

        $this->expenseCategoryId = $db->insert('categories', [
            'branch_id' => $this->branchId,
            'name' => 'LT Expense Cat',
            'type' => 'expense',
            'is_active' => 1,
        ]);
        $this->incomeCategoryId = $db->insert('categories', [
            'branch_id' => $this->branchId,
            'name' => 'LT Income Cat',
            'type' => 'income',
            'is_active' => 1,
        ]);

        $this->partnerId = $db->insert('partners', [
            'branch_id' => $this->branchId,
            'name' => 'LT Partner',
            'join_date' => '2024-01-01',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        $_SESSION = [];
    }

    private function cleanUp(): void
    {
        $db = Database::instance();
        $db->delete('audit_log', 'entity_type = :t', ['t' => 'transactions']);
        $db->delete('transactions', 'description LIKE :d', ['d' => 'LT %']);
        // Any leftovers referencing the fixtures must go before the fixtures.
        $db->run(
            'DELETE t FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             WHERE a.name LIKE :n',
            ['n' => 'LT %']
        );
        $db->delete('partners', 'name LIKE :n', ['n' => 'LT %']);
        $db->delete('categories', 'name LIKE :n', ['n' => 'LT %']);
        $db->delete('accounts', 'name LIKE :n', ['n' => 'LT %']);
        $db->delete('users', 'email = :e', ['e' => 'ledger-tester@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'LT %']);
    }

    private function postExpense(string $amount, string $date = '2026-09-10'): int
    {
        return TransactionService::post([
            'type' => TransactionType::Expense,
            'amount' => $amount,
            'account_id' => $this->bankId,
            'category_id' => $this->expenseCategoryId,
            'transaction_date' => $date,
            'description' => 'LT expense',
        ]);
    }

    private function postIncome(string $amount, string $date = '2026-09-10'): int
    {
        return TransactionService::post([
            'type' => TransactionType::Income,
            'amount' => $amount,
            'account_id' => $this->bankId,
            'category_id' => $this->incomeCategoryId,
            'transaction_date' => $date,
            'description' => 'LT income',
        ]);
    }

    // ------------------------------------------------------------- posting

    public function testPostingStoresTheAmountExactlyAsGiven(): void
    {
        $id = $this->postExpense('1234567.89');

        $stored = Database::instance()->value('SELECT amount FROM transactions WHERE id = :id', ['id' => $id]);

        self::assertSame('1234567.89', (string) $stored, 'the decimal must survive untouched');
    }

    public function testDirectionComesFromTheTypeNotTheCaller(): void
    {
        $db = Database::instance();

        $expense = $this->postExpense('100.00');
        $income = $this->postIncome('100.00');

        self::assertSame(-1, (int) $db->value('SELECT direction FROM transactions WHERE id = :id', ['id' => $expense]));
        self::assertSame(1, (int) $db->value('SELECT direction FROM transactions WHERE id = :id', ['id' => $income]));
    }

    public function testMalformedAmountsAreRejected(): void
    {
        foreach (['0', '-5', 'abc', '10.005', '', '1e5', '10.1.1'] as $bad) {
            try {
                $this->postExpense($bad);
                self::fail("amount '{$bad}' should have been rejected");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAmountsWithThousandSeparatorsAreAccepted(): void
    {
        // People paste "1,234.50" out of a spreadsheet.
        $id = $this->postExpense('1,234.50');
        $stored = Database::instance()->value('SELECT amount FROM transactions WHERE id = :id', ['id' => $id]);

        self::assertSame('1234.50', (string) $stored);
    }

    public function testAnIncomeCategoryCannotBeUsedOnAnExpense(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TransactionService::post([
            'type' => TransactionType::Expense,
            'amount' => '50.00',
            'account_id' => $this->bankId,
            'category_id' => $this->incomeCategoryId,
            'transaction_date' => '2026-09-10',
            'description' => 'LT wrong category type',
        ]);
    }

    public function testExpenseRequiresACategory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TransactionService::post([
            'type' => TransactionType::Expense,
            'amount' => '50.00',
            'account_id' => $this->bankId,
            'transaction_date' => '2026-09-10',
            'description' => 'LT no category',
        ]);
    }

    public function testCapitalMovementRequiresAPartner(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TransactionService::post([
            'type' => TransactionType::PartnerContribution,
            'amount' => '500.00',
            'account_id' => $this->bankId,
            'transaction_date' => '2026-09-10',
            'description' => 'LT no partner',
        ]);
    }

    public function testAClosedAccountCannotBeUsed(): void
    {
        Database::instance()->update('accounts', ['is_active' => 0], 'id = :id', ['id' => $this->cashId]);

        $this->expectException(InvalidArgumentException::class);

        TransactionService::post([
            'type' => TransactionType::Expense,
            'amount' => '10.00',
            'account_id' => $this->cashId,
            'category_id' => $this->expenseCategoryId,
            'transaction_date' => '2026-09-10',
            'description' => 'LT closed account',
        ]);
    }

    // ------------------------------------------------------------- transfers

    /**
     * A spec acceptance criterion: "Transfers do not inflate income/expenses."
     */
    public function testTransfersDoNotTouchProfitAndLoss(): void
    {
        $this->postIncome('1000.00');
        $this->postExpense('400.00');

        $profitBefore = LedgerQuery::posted()->profitAndLossOnly()->netAmount();

        TransactionService::postTransfer(
            $this->bankId,
            $this->cashId,
            '250.00',
            '2026-09-11',
            'LT transfer to cash'
        );

        $profitAfter = LedgerQuery::posted()->profitAndLossOnly()->netAmount();

        self::assertSame(
            (float) $profitBefore,
            (float) $profitAfter,
            'a transfer must not change profit by a single unit'
        );
        self::assertSame(600.0, (float) $profitAfter, '1000 in, 400 out');
    }

    public function testATransferNetsToZeroAcrossAccounts(): void
    {
        TransactionService::postTransfer($this->bankId, $this->cashId, '250.00', '2026-09-11', 'LT move');

        $net = LedgerQuery::posted()->types([TransactionType::TransferIn, TransactionType::TransferOut])->netAmount();

        self::assertSame(0.0, (float) $net, 'the two legs must cancel exactly');
    }

    public function testATransferMovesBothAccountBalances(): void
    {
        TransactionService::postTransfer($this->bankId, $this->cashId, '250.00', '2026-09-11', 'LT move');

        self::assertSame(750.0, (float) TransactionService::accountBalance($this->bankId), '1000 - 250');
        self::assertSame(250.0, (float) TransactionService::accountBalance($this->cashId), '0 + 250');
    }

    public function testATransferIsStoredAsTwoLinkedLegs(): void
    {
        $group = TransactionService::postTransfer($this->bankId, $this->cashId, '250.00', '2026-09-11', 'LT move');

        $legs = LedgerQuery::posted()->transferGroup($group)->count();

        self::assertSame(2, $legs);
    }

    public function testATransferToTheSameAccountIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TransactionService::postTransfer($this->bankId, $this->bankId, '100.00', '2026-09-11', 'LT nowhere');
    }

    // ----------------------------------------------------------------- void

    public function testVoidedRowsAreExcludedFromTotalsButStillExist(): void
    {
        $this->postExpense('100.00');
        $second = $this->postExpense('250.00');

        self::assertSame(350.0, (float) LedgerQuery::posted()->expensesOnly()->totalAmount());

        TransactionService::void($second, 'duplicate entry');

        self::assertSame(
            100.0,
            (float) LedgerQuery::posted()->expensesOnly()->totalAmount(),
            'a voided row must not count'
        );
        self::assertSame(
            350.0,
            (float) LedgerQuery::includeVoided()->expensesOnly()->totalAmount(),
            'but it must still be there to look at'
        );

        $row = Database::instance()->first('SELECT * FROM transactions WHERE id = :id', ['id' => $second]);
        self::assertSame('void', (string) $row['status']);
        self::assertSame('duplicate entry', (string) $row['void_reason']);
        self::assertNotNull($row['voided_at']);
        self::assertSame($this->userId, (int) $row['voided_by']);
    }

    public function testVoidingRequiresAReason(): void
    {
        $id = $this->postExpense('100.00');

        $this->expectException(InvalidArgumentException::class);
        TransactionService::void($id, '   ');
    }

    public function testVoidingIsNotRepeatable(): void
    {
        $id = $this->postExpense('100.00');
        TransactionService::void($id, 'first time');

        $this->expectException(RuntimeException::class);
        TransactionService::void($id, 'second time');
    }

    /**
     * Voiding one leg of a transfer would show money leaving one account and
     * never arriving in the other. Both legs go together.
     */
    public function testVoidingOneLegOfATransferVoidsBoth(): void
    {
        $group = TransactionService::postTransfer($this->bankId, $this->cashId, '250.00', '2026-09-11', 'LT move');

        $oneLeg = (int) Database::instance()->value(
            'SELECT id FROM transactions WHERE transfer_group = :g ORDER BY id LIMIT 1',
            ['g' => $group]
        );

        $voided = TransactionService::void($oneLeg, 'wrong accounts');

        self::assertSame(2, $voided, 'both legs must be voided');
        self::assertSame(0, LedgerQuery::posted()->transferGroup($group)->count());

        // And the balances must be back where they started.
        self::assertSame(1000.0, (float) TransactionService::accountBalance($this->bankId));
        self::assertSame(0.0, (float) TransactionService::accountBalance($this->cashId));
    }

    public function testVoidingIsAudited(): void
    {
        $id = $this->postExpense('100.00');
        TransactionService::void($id, 'keyed twice');

        $entries = Database::instance()->all(
            "SELECT action FROM audit_log WHERE entity_type = 'transactions' AND entity_id = :id",
            ['id' => $id]
        );

        $actions = array_column($entries, 'action');
        self::assertContains('transaction.posted', $actions);
        self::assertContains('transaction.voided', $actions);
    }

    // ------------------------------------------------------- capital and P&L

    /**
     * A spec acceptance criterion: contributions and withdrawals stay separate
     * from operating transactions.
     */
    public function testPartnerCapitalMovesTheBalanceButNotTheProfit(): void
    {
        $this->postIncome('1000.00');
        $this->postExpense('400.00');

        TransactionService::post([
            'type' => TransactionType::PartnerContribution,
            'amount' => '5000.00',
            'account_id' => $this->bankId,
            'partner_id' => $this->partnerId,
            'transaction_date' => '2026-09-12',
            'description' => 'LT capital in',
        ]);

        self::assertSame(
            600.0,
            (float) LedgerQuery::posted()->profitAndLossOnly()->netAmount(),
            'capital is not revenue'
        );
        self::assertSame(
            6600.0,
            (float) TransactionService::accountBalance($this->bankId),
            '1000 opening + 1000 income - 400 expense + 5000 capital'
        );
    }

    public function testWithdrawalIsNotAnOperatingExpense(): void
    {
        $this->postExpense('400.00');

        TransactionService::post([
            'type' => TransactionType::PartnerWithdrawal,
            'amount' => '300.00',
            'account_id' => $this->bankId,
            'partner_id' => $this->partnerId,
            'transaction_date' => '2026-09-12',
            'description' => 'LT drawing',
        ]);

        self::assertSame(
            400.0,
            (float) LedgerQuery::posted()->expensesOnly()->totalAmount(),
            'a drawing must not appear as a business expense'
        );
    }

    // ------------------------------------------------------------ atomicity

    /**
     * A failure inside the detail writer must leave no ledger row behind. A
     * half-recorded movement is not a state this system may reach.
     */
    public function testAFailingDetailWriterRollsBackTheLedgerRow(): void
    {
        $before = LedgerQuery::includeVoided()->count();

        try {
            TransactionService::post([
                'type' => TransactionType::Expense,
                'amount' => '99.00',
                'account_id' => $this->bankId,
                'category_id' => $this->expenseCategoryId,
                'transaction_date' => '2026-09-10',
                'description' => 'LT should not survive',
            ], static function (int $id): void {
                throw new RuntimeException('detail write failed');
            });
            self::fail('the exception should have propagated');
        } catch (RuntimeException) {
            self::assertSame($before, LedgerQuery::includeVoided()->count(), 'nothing may remain');
        }
    }

    public function testNestedTransactionsCommitOnceAndRollBackTogether(): void
    {
        $db = Database::instance();
        $before = LedgerQuery::includeVoided()->count();

        try {
            $db->transaction(function () use ($db): void {
                $this->postExpense('10.00');
                $db->transaction(function (): void {
                    $this->postExpense('20.00');
                    throw new RuntimeException('inner failure');
                });
            });
            self::fail('the failure should have propagated');
        } catch (RuntimeException) {
            self::assertSame(
                $before,
                LedgerQuery::includeVoided()->count(),
                'an inner failure must roll back the outer work too'
            );
        }
    }

    // ----------------------------------------------------------- pagination

    public function testListingPagesRatherThanLoadingEverything(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postExpense('1.00', '2026-09-' . str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT));
        }

        $first = LedgerQuery::posted()->expensesOnly()->page(1, 10);
        $second = LedgerQuery::posted()->expensesOnly()->page(2, 10);

        self::assertCount(10, $first);
        self::assertCount(10, $second);
        self::assertNotSame(
            array_column($first, 'id'),
            array_column($second, 'id'),
            'pages must not overlap'
        );
        self::assertSame(30, LedgerQuery::posted()->expensesOnly()->count());
    }

    public function testPageSizeIsCappedSoOneRequestCannotPullTheWholeTable(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postExpense('1.00');
        }

        // Asking for a million rows must not be honoured.
        $rows = LedgerQuery::posted()->expensesOnly()->page(1, 1000000);

        self::assertLessThanOrEqual(200, count($rows));
    }

    public function testAnUnknownOrderColumnIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LedgerQuery::posted()->orderBy('amount; DROP TABLE transactions');
    }

    public function testEveryPostedQueryFiltersOutVoidedRows(): void
    {
        // Guards the one sharp edge of voiding by status flag: the filter is
        // applied in the constructor, so it cannot be forgotten.
        $reflection = new ReflectionClass(LedgerQuery::class);
        $query = LedgerQuery::posted();
        $conditions = $reflection->getProperty('conditions')->getValue($query);

        self::assertContains("t.status = 'posted'", $conditions);
    }

    /**
     * Regression: markReceived() sets `received_at` to today but left
     * `transaction_date` at the original invoice date, and every date filter
     * in LedgerQuery filtered on `transaction_date` alone. A pending invoice
     * from a prior period that got marked received today therefore never
     * appeared in today's period — silently stuck under its invoice month
     * instead of the month the money actually arrived, contradicting the
     * cash-basis rule this app is built around (D-2).
     */
    public function testMarkingAPendingInvoiceReceivedCountsItInThePeriodItWasReceivedIn(): void
    {
        $invoiceDate = date('Y-m-d', strtotime('-2 months'));
        $id = TransactionService::post([
            'type' => TransactionType::Income,
            'amount' => '999.00',
            'account_id' => $this->bankId,
            'category_id' => $this->incomeCategoryId,
            'transaction_date' => $invoiceDate,
            'description' => 'LT pending invoice',
            'status' => 'pending',
        ]);

        $receivedToday = date('Y-m-d');
        TransactionService::markReceived($id, $receivedToday);

        $thisMonthFrom = date('Y-m-01');
        $thisMonthTo = date('Y-m-t');

        $rows = LedgerQuery::posted()->revenueOnly()->between($thisMonthFrom, $thisMonthTo)->page(1, 200);
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

        self::assertContains($id, $ids, 'a just-received invoice must count in the period it was received in');

        // And it must NOT still be counted under its original invoice month,
        // which is what the pre-fix behaviour did.
        $invoiceMonthFrom = date('Y-m-01', strtotime($invoiceDate));
        $invoiceMonthTo = date('Y-m-t', strtotime($invoiceDate));
        $staleRows = LedgerQuery::posted()->revenueOnly()->between($invoiceMonthFrom, $invoiceMonthTo)->page(1, 200);
        $staleIds = array_map(static fn (array $row): int => (int) $row['id'], $staleRows);

        self::assertNotContains($id, $staleIds, 'a received invoice must not double-count under its invoice month');
    }
}
