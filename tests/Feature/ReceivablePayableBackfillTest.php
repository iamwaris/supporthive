<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Domain\ReceivablePayableKind;
use App\Models\Account;
use App\Services\ReceivablePayableBackfill;
use App\Services\ReceivablePayableService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\BranchFixture;

/**
 * The one-time AR/AP-3 backfill of entries recorded before the register
 * posted to the ledger.
 *
 * Legacy rows are inserted directly, exactly as AR/AP-1 left them: no
 * account and no posting. The guarantees: open entries get their issue
 * posting, settled entries whatever they are missing, cancelled entries
 * nothing; all through the branch's account named "Cash" (never a guessed
 * one), as the entry's creator; a branch without it is skipped with its
 * candidates listed; a failing entry rolls back alone; a second run posts
 * nothing. Every run here is scoped to this test's branches, since the test
 * database is shared.
 */
final class ReceivablePayableBackfillTest extends TestCase
{
    private int $branchId;
    private int $bareBranchId;
    private int $creatorId;
    private int $cashId;
    private int $pettyId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchId = BranchFixture::create('RPB-A');
        $this->bareBranchId = BranchFixture::create('RPB-NOCASH');
        $this->creatorId = $this->insertUser('rpb-creator@test.local', $this->branchId);
        $this->insertAccount($this->branchId, 'RPB Main Bank', 'bank');
        $this->pettyId = $this->insertAccount($this->branchId, 'RPB Petty', 'cash');
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

    /**
     * A row as AR/AP-1 wrote it: no account, no posting.
     *
     * @param array<string,mixed> $extra
     */
    private function insertLegacy(int $branchId, string $kind, string $status, string $amount, array $extra = []): int
    {
        $row = $extra + [
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

    /**
     * @param array{branches:list<array<string,mixed>>} $report
     * @return array<string,mixed>
     */
    private static function branchLine(array $report, int $branchId): array
    {
        foreach ($report['branches'] as $branch) {
            if ($branch['branch_id'] === $branchId) {
                return $branch;
            }
        }
        self::fail('branch #' . $branchId . ' is not in the report');
    }

    /** @return array{0:int,1:string} exit code and output of the real CLI script */
    private static function runScript(string ...$arguments): array
    {
        $environment = getenv() + ['LEDGERHIVE_TESTING' => '1'];
        $environment['LEDGERHIVE_TESTING'] = '1';
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/backfill-receivables-payables.php', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $environment
        );
        if ($process === false) {
            throw new RuntimeException('Could not start the backfill script.');
        }
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    public function testOnlyTheActiveCashAccountNamedCashIsUsed(): void
    {
        $accounts = new Account();
        self::assertSame($this->cashId, $accounts->cashAccountId($this->branchId), '"Cash", not the lower-id petty');
        self::assertNull($accounts->cashAccountId($this->bareBranchId), 'a closed till is no Cash account');

        $tills = BranchFixture::create('RPB-TILLS');
        $this->insertAccount($tills, 'RPB Till A', 'cash');
        $this->insertAccount($tills, 'RPB Till B', 'cash');
        self::assertNull($accounts->cashAccountId($tills), 'no fallback to the lowest-id cash account');

        $renamed = BranchFixture::create('RPB-CLOSEDCASH');
        $this->insertAccount($renamed, 'Cash', 'cash', false);
        self::assertNull($accounts->cashAccountId($renamed), 'a closed "Cash" does not count');
    }

    public function testOpenSettledAndCancelledEntriesAreBackfilledThroughCash(): void
    {
        $open = $this->insertLegacy($this->branchId, 'receivable', 'open', '300.00');
        $settled = $this->insertLegacy($this->branchId, 'payable', 'settled', '450.00');
        $cancelled = $this->insertLegacy($this->branchId, 'receivable', 'cancelled', '99.00');

        $dryRun = ReceivablePayableBackfill::run(true, $this->branchId);
        self::assertSame([$open, $settled], self::ids($dryRun['posted']));
        self::assertSame([$this->cashId, $this->cashId], array_column($dryRun['posted'], 'account_id'));
        self::assertSame(['Cash', 'Cash'], array_column($dryRun['posted'], 'account_name'));
        self::assertSame(0, $this->branchLedgerCount($this->branchId), 'a dry run writes nothing');

        $report = ReceivablePayableBackfill::run(false, $this->branchId);

        self::assertSame([$open, $settled], self::ids($report['posted']));
        self::assertSame([], $report['failed']);
        $branch = self::branchLine($report, $this->branchId);
        self::assertSame($this->cashId, $branch['account_id']);
        self::assertSame('Cash', $branch['account_name']);
        self::assertCount(2, $branch['entries']);

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

        self::assertNull($this->row($cancelled)['issue_transaction_id']);
        self::assertNull($this->row($cancelled)['account_id']);
        self::assertSame(3, $this->branchLedgerCount($this->branchId));
        self::assertSame(0, (int) Database::instance()->value(
            'SELECT COUNT(*) FROM transactions WHERE account_id = :petty',
            ['petty' => $this->pettyId]
        ), 'the other cash account is never used');
        self::assertSame([], $_SESSION, 'the CLI identity is restored afterwards');

        // Running it again posts nothing new.
        $again = ReceivablePayableBackfill::run(false, $this->branchId);
        self::assertSame([], $again['posted']);
        self::assertSame([], $again['branches'], 'nothing left to post');
        self::assertSame(3, $this->branchLedgerCount($this->branchId));
    }

    public function testABranchWithoutItsCashAccountIsSkippedWithItsCandidatesListed(): void
    {
        $stranded = $this->insertLegacy($this->bareBranchId, 'payable', 'open', '120.00');

        $report = ReceivablePayableBackfill::run(false, $this->bareBranchId);

        self::assertSame([], $report['posted']);
        $branch = self::branchLine($report, $this->bareBranchId);
        self::assertNull($branch['account_id']);
        self::assertSame([$stranded], self::ids($branch['entries']));
        self::assertSame([['name' => 'RPB Closed Till', 'is_active' => false]], array_map(
            static fn (array $c): array => ['name' => $c['name'], 'is_active' => $c['is_active']],
            $branch['candidates']
        ));
        self::assertNull($this->row($stranded)['issue_transaction_id']);
        self::assertSame(0, $this->branchLedgerCount($this->bareBranchId), 'no account was invented');
    }

    public function testAFailingEntryRollsBackAloneAndTheRunCarriesOn(): void
    {
        $foreignAccount = $this->insertAccount($this->bareBranchId, 'RPB Foreign', 'bank');
        // A settlement account from another branch makes the settlement
        // posting fail after the issue posting succeeded in the same entry.
        $broken = $this->insertLegacy($this->branchId, 'receivable', 'settled', '70.00', [
            'settlement_account_id' => $foreignAccount,
        ]);
        $healthy = $this->insertLegacy($this->branchId, 'payable', 'open', '80.00');

        $report = ReceivablePayableBackfill::run(false, $this->branchId);

        self::assertSame([$broken], self::ids($report['failed']));
        self::assertSame([$healthy], self::ids($report['posted']), 'the next entry still posts');
        $brokenRow = $this->row($broken);
        self::assertNull($brokenRow['issue_transaction_id'], 'its issue posting was rolled back too');
        self::assertNull($brokenRow['account_id']);
        self::assertSame(0, (int) Database::instance()->value(
            'SELECT COUNT(*) FROM transactions WHERE description LIKE :d',
            ['d' => '%(register #' . $broken . ')']
        ));
        self::assertSame(0, (int) Database::instance()->value(
            "SELECT COUNT(*) FROM audit_log WHERE entity_type = 'receivables_payables' AND entity_id = :id",
            ['id' => $broken]
        ));
        self::assertSame([], $_SESSION);
    }

    public function testASettledEntryWithOnlyItsIssuePostingGetsJustTheSettlement(): void
    {
        $_SESSION = ['_auth_user_id' => $this->creatorId, '_active_branch_id' => $this->branchId];
        $id = ReceivablePayableService::record(ReceivablePayableKind::Receivable, [
            'party_name' => 'RPB Half Done',
            'reference_no' => null,
            'description' => null,
            'amount' => '60',
            'issue_date' => '2026-10-02',
            'due_date' => null,
            'account_id' => $this->pettyId,
        ]);
        $_SESSION = [];
        Database::instance()->update(
            'receivables_payables',
            ['status' => 'settled', 'settled_on' => '2026-10-12'],
            'id = :id',
            ['id' => $id]
        );
        $issueBefore = $this->transaction((int) $this->row($id)['issue_transaction_id']);

        $report = ReceivablePayableBackfill::run(false, $this->branchId);

        self::assertSame([$id], self::ids($report['posted']));
        self::assertNull($report['posted'][0]['issue_transaction_id'], 'no second issue posting');
        $row = $this->row($id);
        self::assertSame($issueBefore, $this->transaction((int) $row['issue_transaction_id']));
        self::assertSame($this->pettyId, (int) $row['account_id'], 'the issue account is kept');
        $settlement = $this->transaction((int) $row['settlement_transaction_id']);
        self::assertSame('receivable_collected', $settlement['type']);
        self::assertSame('2026-10-12', $settlement['transaction_date']);
        self::assertSame($this->cashId, (int) $settlement['account_id']);
    }

    public function testTheScriptPrintsTheAccountsAndExitsZeroOneOrTwo(): void
    {
        $this->insertLegacy($this->branchId, 'receivable', 'open', '10.00');
        [$dryCode, $dryOutput] = self::runScript('--dry-run', '--branch=' . $this->branchId);
        self::assertSame(0, $dryCode, $dryOutput);
        self::assertStringContainsString('via account #' . $this->cashId . ' "Cash"', $dryOutput);
        self::assertStringContainsString('branch #' . $this->branchId, $dryOutput);

        [$code, $output] = self::runScript('--branch=' . $this->branchId);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('posted receivable', $output);
        self::assertStringContainsString('"Cash"', $output);

        $this->insertLegacy($this->bareBranchId, 'payable', 'open', '20.00');
        [$skipCode, $skipOutput] = self::runScript('--branch=' . $this->bareBranchId);
        self::assertSame(2, $skipCode, $skipOutput);
        self::assertStringContainsString('SKIPPED branch #' . $this->bareBranchId, $skipOutput);
        self::assertStringContainsString('"RPB Closed Till" (closed)', $skipOutput);

        $foreignAccount = $this->insertAccount($this->bareBranchId, 'RPB Foreign', 'bank');
        $this->insertLegacy($this->branchId, 'receivable', 'settled', '30.00', [
            'settlement_account_id' => $foreignAccount,
        ]);
        [$failCode, $failOutput] = self::runScript('--branch=' . $this->branchId);
        self::assertSame(1, $failCode, $failOutput);
        self::assertStringContainsString('FAILED receivable', $failOutput);
    }
}
