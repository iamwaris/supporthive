<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\AiChatController;
use App\Core\Database;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\BranchFixture;

/**
 * Pins down AiChatController::executeTool()'s dispatch table: each of the 5
 * whitelisted tool names must route to its real service call with correctly
 * typed/parsed arguments, and an unknown name must come back as an error
 * payload for the model to see — never a silent no-op, never an uncaught
 * exception reaching the Anthropic loop.
 *
 * executeTool() is `private`, with no public caller worth testing through
 * (a full HTTP round trip would require a real Anthropic API call). Since
 * AiChatController is `final` per CLAUDE.md's "final class by default" rule
 * — extension points are reserved for Controller/Model — a test subclass is
 * not an option here, so this reaches the method via ReflectionMethod
 * instead, the least invasive way to test a private method with no existing
 * project precedent to follow.
 *
 * Mirrors the branch-per-test fixture pattern from ReportServiceTest: the
 * dispatched services (LedgerQuery/ReportService) are real, branch-scoped
 * reads — this test proves the dispatch and argument validation, not the
 * report math itself (ReportServiceTest already owns that).
 */
final class AiChatControllerToolDispatchTest extends TestCase
{
    private int $branchId;
    private ReflectionMethod $executeTool;
    private AiChatController $controller;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchId = BranchFixture::create('AICD');
        $userId = Database::instance()->insert('users', [
            'name' => 'AI Chat Dispatch Tester',
            'email' => 'ai-chat-dispatch-tester@test.local',
            'password_hash' => password_hash('unused-in-this-test', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
        ]);
        $_SESSION['_auth_user_id'] = $userId;
        $_SESSION['_active_branch_id'] = $this->branchId;

        $this->controller = new AiChatController();
        $this->executeTool = new ReflectionMethod(AiChatController::class, 'executeTool');
        $this->executeTool->setAccessible(true);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        $_SESSION = [];
    }

    private function cleanUp(): void
    {
        $db = Database::instance();
        $db->delete('users', 'email = :e', ['e' => 'ai-chat-dispatch-tester@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'AICD %']);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function callTool(string $name, array $input): array
    {
        /** @var array<string,mixed> $result */
        $result = $this->executeTool->invoke($this->controller, $name, $input);

        return $result;
    }

    public function testGetProfitLossRoutesToReportService(): void
    {
        $result = $this->callTool('get_profit_loss', ['from' => '2026-01-01', 'to' => '2026-01-31']);

        self::assertArrayNotHasKey('error', $result);
        self::assertArrayHasKey('revenue', $result);
        self::assertArrayHasKey('expenses', $result);
        self::assertArrayHasKey('net', $result);
        self::assertArrayHasKey('byCategory', $result);
    }

    public function testGetCashFlowRoutesToReportService(): void
    {
        $result = $this->callTool('get_cash_flow', ['from' => '2026-01-01', 'to' => '2026-01-31']);

        self::assertArrayNotHasKey('error', $result);
        self::assertArrayHasKey('inbound', $result);
        self::assertArrayHasKey('outbound', $result);
        self::assertArrayHasKey('pendingIncome', $result);
    }

    public function testGetCategoryTotalsRoutesToLedgerQueryForExpenses(): void
    {
        $result = $this->callTool(
            'get_category_totals',
            ['from' => '2026-01-01', 'to' => '2026-01-31', 'type' => 'expense']
        );

        self::assertArrayNotHasKey('error', $result);
        self::assertArrayHasKey('byCategory', $result);
        self::assertIsArray($result['byCategory']);
    }

    public function testGetCategoryTotalsRoutesToLedgerQueryForIncome(): void
    {
        $result = $this->callTool(
            'get_category_totals',
            ['from' => '2026-01-01', 'to' => '2026-01-31', 'type' => 'income']
        );

        self::assertArrayNotHasKey('error', $result);
        self::assertArrayHasKey('byCategory', $result);
    }

    public function testGetCategoryTotalsRejectsAnInvalidType(): void
    {
        $result = $this->callTool(
            'get_category_totals',
            ['from' => '2026-01-01', 'to' => '2026-01-31', 'type' => 'transfer_in']
        );

        self::assertArrayHasKey('error', $result);
    }

    public function testGetAccountBalancesRoutesToReportServiceWithNullAsOf(): void
    {
        $result = $this->callTool('get_account_balances', []);

        self::assertArrayNotHasKey('error', $result);
        self::assertArrayHasKey('total', $result);
        self::assertArrayHasKey('accounts', $result);
    }

    public function testGetAccountBalancesRoutesToReportServiceWithAnAsOfDate(): void
    {
        $result = $this->callTool('get_account_balances', ['as_of' => '2026-01-15']);

        self::assertArrayNotHasKey('error', $result);
        self::assertArrayHasKey('total', $result);
    }

    public function testGetPartnerTotalsRoutesToReportService(): void
    {
        // ReportService::totalsByPartner() returns a plain list of rows (not
        // wrapped), so the dispatch result here is that list itself.
        $result = $this->callTool(
            'get_partner_totals',
            ['from' => '2026-01-01', 'to' => '2026-01-31', 'type' => 'partner_contribution']
        );

        self::assertArrayNotHasKey('error', $result);
    }

    public function testGetPartnerTotalsRejectsAnOutOfRangeType(): void
    {
        // Not one of the two partner-capital types the tool allows, even
        // though it is a real TransactionType value.
        $result = $this->callTool(
            'get_partner_totals',
            ['from' => '2026-01-01', 'to' => '2026-01-31', 'type' => 'expense']
        );

        self::assertArrayHasKey('error', $result);
    }

    public function testRejectsMissingOrUnparseableDates(): void
    {
        $result = $this->callTool('get_profit_loss', ['from' => 'not-a-date', 'to' => '2026-01-31']);

        self::assertArrayHasKey('error', $result);
    }

    public function testUnknownToolNameIsRejectedRatherThanSilentlyNoOpOrThrowing(): void
    {
        $result = $this->callTool('drop_all_transactions', ['from' => '2026-01-01', 'to' => '2026-01-31']);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('drop_all_transactions', $result['error']);
    }
}
