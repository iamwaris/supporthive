<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Models\Account;
use App\Services\ReceivablePayableBackfill;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;

/**
 * The one-time AR/AP-3 backfill of entries recorded before the register
 * posted to the ledger.
 *
 * Legacy rows are inserted directly, exactly as AR/AP-1 left them: no
 * account and no posting. The guarantees: open entries get their issue
 * posting, settled entries both postings, cancelled entries nothing; all
 * through the branch's Cash account, as the entry's creator; a branch with
 * no cash account is skipped and reported; a second run posts nothing.
 */
final class ReceivablePayableBackfillTest extends TestCase
{
    private int $branchId;
    private int $bareBranchId;
    private int $creatorId;
    private int $cashId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchId = BranchFixture::create('RPB-A');
        $this->bareBranchId = BranchFixture::create('RPB-NOCASH');
        $this->creatorId = $this->insertUser('rpb-creator@test.local', $this->branchId);
        $this->insertAccount($this->branchId, 'RPB Main Bank', 'bank');
        $this->insertAccount($this->branchId, 'RPB Petty', 'cash');
        $this->cashId = $this->insertAccount($this->branchId, 'Cash', 'cash');
        $this->insertAccount($this->bareBranchId, 'RPB Only Bank', 'bank');
        $this->insertAccount($this->bareBranchId, 'RPB Closed Till', 'cash', false);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        $_SESSION = [];
    }

    private function cleanUp(): void
    {
        $db = Database::instance();
        foreach (['receivables_payables', 'transactions', 'accounts', 'audit_log'] as $table) {
            $db->run(
                'DELETE x FROM ' . Database::identifier($table) . ' x
                 JOIN branches b ON b.id = x.branch_id WHERE b.name LIKE :n',
                ['n' => 'RPB-%']
            );
        }
        $db->run(
            'DELETE a FROM audit_log a JOIN users u ON u.id = a.user_id WHERE u.email LIKE :e',
            ['e' => 'rpb-%@test.local']
        );
        $db->delete('users', 'email LIKE :e', ['e' => 'rpb-%@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'RPB-%']);
    }

    private function insertUser(string $email, int $branchId): int
    {
        return Database::instance()->insert('users', [
            'name' => 'RPB ' . $email,
            'email' => $email,
            'password_hash' => password_hash('unused', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $branchId,
            'status' => 'active',
        ]);
    }

    private function insertAccount(int $branchId, string $name, string $type, bool $active = true): int
    {
        return Database::instance()->insert('accounts', [
            'branch_id' => $branchId,
            'name' => $name,
            'type' => $type,
            'opening_balance' => '5000.00',
            'opening_date' => '2026-01-01',
            'is_active' => $active ? 1 : 0,
        ]);
    }

    /** A row as AR/AP-1 wrote it: no account, no posting. */
    private function insertLegacy(int $branchId, string $kind, string $status, string $amount): int
    {
        $row = [
            'branch_id' => $branchId,
            'kind' => $kind,
            'party_name' => 'RPB ' . $kind . ' ' . $status,
            'reference_no' => 'LEG-' . $status,
            'amount' => $amount,
            'issue_date' => '2026-10-01',
            'status' => $status,
            'created_by' => $this->creatorId,
        ];
        if ($status === 'settled') {
            $row += [
                'settled_on' => '2026-10-10',
                'settled_by' => $this->creatorId,
                'settled_at' => '2026-10-10 12:00:00',
            ];
        }
        if ($status === 'cancelled') {
            $row += ['cancel_reason' => 'Duplicate', 'cancelled_by' => $this->creatorId];
        }

        return Database::instance()->insert('receivables_payables', $row);
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $row = Database::instance()->first('SELECT * FROM receivables_payables WHERE id = :id', ['id' => $id]);
        self::assertNotNull($row);

        return $row;
    }

    /** @return array<string,mixed> */
    private function transaction(int $id): array
    {
        $row = Database::instance()->first('SELECT * FROM transactions WHERE id = :id', ['id' => $id]);
        self::assertNotNull($row);

        return $row;
    }

    private function branchLedgerCount(int $branchId): int
    {
        return (int) Database::instance()->value(
            'SELECT COUNT(*) FROM transactions WHERE branch_id = :b',
            ['b' => $branchId]
        );
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @return list<int>
     */
    private static function ids(array $entries): array
    {
        return array_map(static fn (array $entry): int => (int) $entry['id'], $entries);
    }

    public function testTheCashAccountRuleIsDeterministic(): void
    {
        $accounts = new Account();
        self::assertSame($this->cashId, $accounts->cashAccountId($this->branchId), '"Cash" beats a lower id');
        self::assertNull($accounts->cashAccountId($this->bareBranchId), 'a closed cash account does not count');

        $tills = BranchFixture::create('RPB-TILLS');
        $first = $this->insertAccount($tills, 'RPB Till A', 'cash');
        $this->insertAccount($tills, 'RPB Till B', 'cash');
        self::assertSame($first, $accounts->cashAccountId($tills), 'otherwise the lowest id');
    }

    public function testOpenSettledAndCancelledEntriesAreBackfilledAndABranchWithoutCashIsSkipped(): void
    {
        $open = $this->insertLegacy($this->branchId, 'receivable', 'open', '300.00');
        $settled = $this->insertLegacy($this->branchId, 'payable', 'settled', '450.00');
        $cancelled = $this->insertLegacy($this->branchId, 'receivable', 'cancelled', '99.00');
        $stranded = $this->insertLegacy($this->bareBranchId, 'payable', 'open', '120.00');

        $dryRun = ReceivablePayableBackfill::run(true);
        self::assertContains($open, self::ids($dryRun['posted']));
        self::assertSame(0, $this->branchLedgerCount($this->branchId), 'a dry run writes nothing');

        $report = ReceivablePayableBackfill::run();

        $posted = array_values(array_filter(
            $report['posted'],
            fn (array $entry): bool => in_array($entry['id'], [$open, $settled, $cancelled, $stranded], true)
        ));
        self::assertSame([$open, $settled], self::ids($posted));
        self::assertSame([], $report['failed']);
        self::assertContains($stranded, self::ids($report['skipped']));
        $skip = array_values(array_filter($report['skipped'], static fn (array $e): bool => $e['id'] === $stranded))[0];
        self::assertSame($this->bareBranchId, $skip['branch_id']);
        self::assertStringContainsString('cash', $skip['reason']);

        // Open receivable: money lent out of Cash on its issue date.
        $openRow = $this->row($open);
        self::assertSame($this->cashId, (int) $openRow['account_id']);
        $issue = $this->transaction((int) $openRow['issue_transaction_id']);
        self::assertSame('receivable_issued', $issue['type']);
        self::assertSame(-1, (int) $issue['direction']);
        self::assertSame('300.00', $issue['amount']);
        self::assertSame('2026-10-01', $issue['transaction_date']);
        self::assertSame('posted', $issue['status']);
        self::assertSame($this->creatorId, (int) $issue['created_by']);
        self::assertSame($this->branchId, (int) $issue['branch_id']);
        self::assertStringContainsString('register #' . $open, (string) $issue['description']);
        self::assertNull($openRow['settlement_transaction_id']);

        // Settled payable: borrowed into Cash, then repaid on settled_on.
        $settledRow = $this->row($settled);
        $received = $this->transaction((int) $settledRow['issue_transaction_id']);
        $repaid = $this->transaction((int) $settledRow['settlement_transaction_id']);
        self::assertSame(['payable_received', 1, '2026-10-01'], [
            $received['type'], (int) $received['direction'], $received['transaction_date'],
        ]);
        self::assertSame(['payable_repaid', -1, '2026-10-10'], [
            $repaid['type'], (int) $repaid['direction'], $repaid['transaction_date'],
        ]);
        self::assertSame($this->cashId, (int) $settledRow['settlement_account_id']);
        self::assertSame(1, (int) Database::instance()->value(
            "SELECT COUNT(*) FROM audit_log WHERE entity_type = 'receivables_payables'
               AND entity_id = :id AND action = 'payable.backfilled' AND user_id = :u",
            ['id' => $settled, 'u' => $this->creatorId]
        ));

        // Cancelled and stranded entries are untouched.
        self::assertNull($this->row($cancelled)['issue_transaction_id']);
        self::assertNull($this->row($cancelled)['account_id']);
        self::assertNull($this->row($stranded)['issue_transaction_id']);
        self::assertSame(3, $this->branchLedgerCount($this->branchId));
        self::assertSame(0, $this->branchLedgerCount($this->bareBranchId), 'no account was invented');
        self::assertSame([], $_SESSION, 'the CLI identity is restored afterwards');

        // Running it again posts nothing new.
        $again = ReceivablePayableBackfill::run();
        self::assertSame([], array_values(array_intersect([$open, $settled, $cancelled], self::ids($again['posted']))));
        self::assertSame(3, $this->branchLedgerCount($this->branchId));
        self::assertContains($stranded, self::ids($again['skipped']), 'still reported until the branch has cash');
    }
}
