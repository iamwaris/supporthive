<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Http;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Validator;
use App\Domain\TransactionType;
use App\Services\AiAvailability;
use App\Services\AnthropicClient;
use App\Services\LedgerQuery;
use App\Services\ReportService;
use InvalidArgumentException;
use RuntimeException;

/**
 * Conversational Q&A over the ledger, spec M8.
 *
 * The model never gets raw database access: it can only reach the same
 * read-only aggregation layer the reports screens use (LedgerQuery /
 * ReportService), through five fixed tool definitions. Every tool argument is
 * revalidated here before it reaches those services — defense in depth, since
 * the services themselves are already parameterised-SQL safe — and the
 * session's own branch/user identity is never taken from model input, only
 * from Auth. The system prompt additionally instructs the model to never
 * state a figure that did not come back from a tool call, but that is a
 * behavioural nudge, not a security boundary; the boundary is that every
 * number the model *can* see already came from a branch-scoped query.
 *
 * The same system prompt also carries a short static reference describing
 * the app's own screens, so the assistant can answer "how do I..." /
 * "what is..." questions about LedgerHive itself without a tool call — that
 * content is inert prose, not a data source, and the never-invent-a-number
 * rule above still governs any figure, even inside an app-help answer.
 */
final class AiChatController extends Controller
{
    private const MAX_ITERATIONS = 4;
    private const MAX_HISTORY_PAIRS = 10;
    private const MAX_HISTORY_CHARS = 8000;

    private const SYSTEM_PROMPT = 'You are the assistant for LedgerHive, a small-business bookkeeping app. You '
        . 'answer two kinds of question: (1) real financial data from this ledger, and (2) how to use the app.'
        . "\n\n"
        . 'FINANCIAL DATA — this rule is absolute and applies to every number, in either kind of question: you '
        . 'must never state a number that did not come from a tool result. Always call the appropriate tool '
        . 'before answering any question that involves an amount, total, or balance. If a question mixes '
        . '"how do I..." with a real figure (e.g. "what is my Marketing budget"), still call a tool for the '
        . 'data part — never recall a number from memory or from the app reference below.'
        . "\n\n"
        . 'APP REFERENCE — static knowledge of the app\'s own screens, for "how do I" / "what is" questions; '
        . 'no tool call needed for these, and do not invent anything not listed here:'
        . "\n"
        . '- Dashboard: overview of recent activity and key figures.'
        . "\n"
        . '- Expenses: record an expense against a category and account; an optional receipt file can be '
        . 'attached. "Scan receipt" on the form uses AI to pre-fill vendor/amount/date/category from an '
        . 'uploaded receipt image or PDF — it is a suggestion only, never saved automatically; the user still '
        . 'reviews and submits the form.'
        . "\n"
        . '- Income: record income as received or pending. Only received income counts as revenue and affects '
        . 'an account balance; marking a pending invoice received is what makes it count.'
        . "\n"
        . '- Transfers: move money between the business\'s own accounts, posted as two linked legs. Never '
        . 'income or expense, and never affects profit.'
        . "\n"
        . '- All Transactions: the full ledger, filterable and paginated.'
        . "\n"
        . '- Accounts: cash, bank, credit card, petty cash, or other; each balance is computed live from the '
        . 'ledger, never stored.'
        . "\n"
        . '- Customers: contact records referenced when recording income.'
        . "\n"
        . '- Budgets: a monthly amount per top-level expense category, with an alert threshold percentage; '
        . 'spend-vs-budget is computed live against the ledger, not stored.'
        . "\n"
        . '- Partners & Profit: partner records, plus the ownership split (a percentage per partner, effective '
        . 'from a date) used only to allocate profit — never to split expenses.'
        . "\n"
        . '- Capital Movements: partner contributions (money a partner puts in) and withdrawals (money a '
        . 'partner takes out). Changes an account balance but is never revenue or an expense.'
        . "\n"
        . '- Profit Distribution: three separate, audited steps — calculate a batch for a period, approve it, '
        . 'then distribute it, which posts the payout to the ledger for every partner in the batch.'
        . "\n"
        . '- Reports: profit & loss, cash flow, expense by category, budget vs actual, account balances, '
        . 'partner statement, partner contributions, partner withdrawals, profit distribution, daily '
        . 'transactions, income, and expenses — each exportable as CSV or PDF.'
        . "\n"
        . '- Categories: expense or income categories, with one level of subcategories. Never deleted, only '
        . 'toggled active/inactive, so past transactions keep the category they were filed under.'
        . "\n"
        . '- AI Settings (Settings > AI Settings, admins only): turn AI features on or off and store the '
        . 'Anthropic API key they use; a "Test" button confirms the key works. Both receipt scanning and this '
        . 'chat assistant require AI enabled with a working key for the branch.'
        . "\n"
        . '- Users, Audit Log, Settings and AI Settings are visible to admins only; other roles will not see '
        . 'them in the sidebar.';

    public function ask(): void
    {
        if (!AiAvailability::enabledForCurrentBranch()) {
            // Same "the endpoint does not exist" posture as scan-receipt when
            // AI is off for this branch.
            Http::abort(404);
        }

        RateLimiter::guard('ai_chat:' . Auth::id(), 20, 3600);

        $input = $this->requestBody();

        $validator = Validator::make($input, [
            'question' => 'required|max:500',
        ]);

        if ($validator->fails()) {
            $this->json(['error' => $validator->firstError('question') ?? 'Invalid request.'], 422);
        }

        $question = (string) $validator->validated()['question'];
        $history = $this->cleanHistory(is_array($input['history'] ?? null) ? $input['history'] : []);

        $messages = [];
        foreach ($history as $pair) {
            $messages[] = ['role' => 'user', 'content' => $pair['question']];
            $messages[] = ['role' => 'assistant', 'content' => $pair['answer']];
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        try {
            [$answer, $toolsCalled] = $this->converse($messages);
        } catch (RuntimeException) {
            $this->json(['error' => 'AI service is currently unavailable.'], 502);
        }

        Database::instance()->insert('ai_usage_log', [
            'branch_id' => Auth::branchId(),
            'user_id' => Auth::id(),
            'feature' => 'chat',
        ]);

        // Deliberately no question/answer text here — see the ai_usage_log
        // migration's own comment: nothing worth leaking belongs in a table
        // (or a log line) read by ordinary reporting/ops code.
        Logger::info('AI chat answered', [
            'branch_id' => Auth::branchId(),
            'tools_called' => $toolsCalled,
        ]);

        $this->json(['answer' => $answer]);
    }

    /**
     * Runs the tool-use loop against the Anthropic client and returns the
     * final answer text plus the (name-only) list of tools that were called.
     *
     * @param array<int,array<string,mixed>> $messages
     * @return array{0:string,1:list<string>}
     */
    private function converse(array $messages): array
    {
        $client = AnthropicClient::forCurrentBranch();
        $tools = $this->toolDefinitions();
        $toolsCalled = [];

        for ($iteration = 0; $iteration < self::MAX_ITERATIONS; $iteration++) {
            $response = $client->createMessage($messages, self::SYSTEM_PROMPT, $tools, 1024);

            if (($response['stop_reason'] ?? null) !== 'tool_use') {
                return [$this->extractText($response), $toolsCalled];
            }

            $content = $response['content'] ?? [];
            $content = is_array($content) ? $content : [];

            $messages[] = ['role' => 'assistant', 'content' => $content];

            $resultBlocks = [];
            foreach ($content as $block) {
                if (!is_array($block) || ($block['type'] ?? null) !== 'tool_use') {
                    continue;
                }

                $name = (string) ($block['name'] ?? '');
                $toolUseId = (string) ($block['id'] ?? '');
                $toolInput = is_array($block['input'] ?? null) ? $block['input'] : [];

                $toolsCalled[] = $name;
                $result = $this->executeTool($name, $toolInput);

                $resultBlocks[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $toolUseId,
                    'content' => json_encode($result, JSON_THROW_ON_ERROR),
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $resultBlocks];
        }

        return ["I wasn't able to fully answer that — try a more specific question.", $toolsCalled];
    }

    /**
     * Dispatches one tool call by name to the read-only reporting layer.
     * Every argument is validated before it reaches LedgerQuery/ReportService
     * — an unparseable date or an out-of-range type never gets that far. An
     * unknown tool name is rejected the same way a bad argument is: an error
     * payload handed back to the model, never a silent no-op and never an
     * uncaught exception.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function executeTool(string $name, array $input): array
    {
        try {
            return match ($name) {
                'get_profit_loss' => ReportService::profitLoss(
                    $this->requireDate($input, 'from'),
                    $this->requireDate($input, 'to')
                ),
                'get_cash_flow' => ReportService::cashFlow(
                    $this->requireDate($input, 'from'),
                    $this->requireDate($input, 'to')
                ),
                'get_category_totals' => $this->categoryTotals(
                    $this->requireDate($input, 'from'),
                    $this->requireDate($input, 'to'),
                    $this->requireEnum($input, 'type', ['expense', 'income'])
                ),
                'get_account_balances' => ReportService::accountBalancesAsOf(
                    $this->optionalDate($input, 'as_of')
                ),
                'get_partner_totals' => ReportService::totalsByPartner(
                    $this->requirePartnerTransactionType($input),
                    $this->requireDate($input, 'from'),
                    $this->requireDate($input, 'to')
                ),
                default => ['error' => "Unknown tool: {$name}"],
            };
        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** @return array<string,mixed> */
    private function categoryTotals(string $from, string $to, string $type): array
    {
        $query = LedgerQuery::posted()->between($from, $to);
        $query = $type === 'expense' ? $query->expensesOnly() : $query->revenueOnly();

        return ['byCategory' => $query->groupedByCategory()];
    }

    private function requirePartnerTransactionType(array $input): TransactionType
    {
        $value = $this->requireEnum($input, 'type', ['partner_contribution', 'partner_withdrawal']);
        $type = TransactionType::tryFrom($value);
        if ($type === null) {
            throw new InvalidArgumentException("Invalid partner transaction type: {$value}");
        }

        return $type;
    }

    /** @param array<string,mixed> $input */
    private function requireDate(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || strtotime($value) === false) {
            throw new InvalidArgumentException("Invalid or missing '{$key}' date.");
        }

        return date('Y-m-d', (int) strtotime($value));
    }

    /** @param array<string,mixed> $input */
    private function optionalDate(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strtotime($value) === false) {
            throw new InvalidArgumentException("Invalid '{$key}' date.");
        }

        return date('Y-m-d', (int) strtotime($value));
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string>        $allowed
     */
    private function requireEnum(array $input, string $key, array $allowed): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Invalid '{$key}': must be one of " . implode(', ', $allowed) . '.');
        }

        return $value;
    }

    private function extractText(array $response): string
    {
        $content = $response['content'] ?? [];
        $content = is_array($content) ? $content : [];

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                return (string) ($block['text'] ?? '');
            }
        }

        return "I wasn't able to fully answer that — try a more specific question.";
    }

    /**
     * Caps history to the last N pairs, then trims oldest-first until the
     * combined character budget is met — a person pasting a long back-and-
     * forth must not be able to balloon the request sent (and paid for) on
     * every follow-up question.
     *
     * @param list<mixed> $raw
     * @return list<array{question:string,answer:string}>
     */
    private function cleanHistory(array $raw): array
    {
        $pairs = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $question = $entry['question'] ?? null;
            $answer = $entry['answer'] ?? null;
            if (!is_string($question) || !is_string($answer) || $question === '' || $answer === '') {
                continue;
            }
            $pairs[] = ['question' => $question, 'answer' => $answer];
        }

        $pairs = array_slice($pairs, -self::MAX_HISTORY_PAIRS);

        $total = 0;
        foreach ($pairs as $pair) {
            $total += mb_strlen($pair['question']) + mb_strlen($pair['answer']);
        }

        while ($total > self::MAX_HISTORY_CHARS && $pairs !== []) {
            $dropped = array_shift($pairs);
            $total -= mb_strlen($dropped['question']) + mb_strlen($dropped['answer']);
        }

        return $pairs;
    }

    /**
     * Accepts either a JSON body or classic form-encoded POST, mirroring how
     * the rest of the app reads $_POST but letting the Alpine widget send
     * `history` as real JSON rather than a flattened form field.
     *
     * @return array<string,mixed>
     */
    private function requestBody(): array
    {
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }

    /** @return list<array<string,mixed>> */
    private function toolDefinitions(): array
    {
        $dateProps = [
            'from' => ['type' => 'string', 'description' => 'Start date, YYYY-MM-DD.'],
            'to' => ['type' => 'string', 'description' => 'End date, YYYY-MM-DD.'],
        ];

        return [
            [
                'name' => 'get_profit_loss',
                'description' => 'Get revenue, expenses, net profit, and expense-by-category breakdown for a date '
                    . 'range.',
                'input_schema' => ['type' => 'object', 'properties' => $dateProps, 'required' => ['from', 'to']],
            ],
            [
                'name' => 'get_cash_flow',
                'description' => 'Get cash inbound, outbound, net movement and pending income for a date range.',
                'input_schema' => ['type' => 'object', 'properties' => $dateProps, 'required' => ['from', 'to']],
            ],
            [
                'name' => 'get_category_totals',
                'description' => 'Get totals grouped by category for either expenses or income over a date range.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => $dateProps + [
                        'type' => ['type' => 'string', 'enum' => ['expense', 'income']],
                    ],
                    'required' => ['from', 'to', 'type'],
                ],
            ],
            [
                'name' => 'get_account_balances',
                'description' => 'Get every active account balance as of a date (omit for the current balance).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'as_of' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD, or null for now.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'get_partner_totals',
                'description' => 'Get partner contribution or withdrawal totals, grouped by partner, for a date range.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => $dateProps + [
                        'type' => ['type' => 'string', 'enum' => ['partner_contribution', 'partner_withdrawal']],
                    ],
                    'required' => ['from', 'to', 'type'],
                ],
            ],
        ];
    }
}
