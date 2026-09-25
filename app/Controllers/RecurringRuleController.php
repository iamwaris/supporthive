<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Session;
use App\Models\Account;
use App\Models\Category;
use App\Models\Customer;
use App\Models\RecurringRule;
use App\Services\Audit;
use App\Services\RecurringRuleService;
use InvalidArgumentException;

/**
 * CRUD for recurring-transaction rules — the schedule a rule generates
 * drafts from. Generation itself (the nightly cron) and the approval queue
 * for those drafts are separate features; this controller only creates,
 * edits, pauses and resumes the rules themselves.
 */
final class RecurringRuleController extends Controller
{
    private const FREQUENCIES = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'];
    private const MONTHLY_FAMILY = ['monthly', 'quarterly', 'yearly'];

    public function index(): void
    {
        $rules = new RecurringRule();
        $today = date('Y-m-d');

        $rows = $rules->allWithDetails();
        foreach ($rows as &$row) {
            $row['next_due_date'] = (int) $row['is_active'] === 1
                ? RecurringRuleService::nextOccurrenceDate($row, $today)
                : null;
        }
        unset($row);

        $categories = new Category();

        $this->view('pages/recurring-rules', [
            'title' => 'Recurring Rules',
            'nav' => 'recurring-rules',
            'pageTitle' => 'Recurring Rules',
            'pageMeta' => 'Schedules that generate draft transactions for review',
            'rules' => $rows,
            'accounts' => (new Account())->allOrdered(),
            'expenseTree' => $categories->tree('expense'),
            'incomeTree' => $categories->tree('income'),
            'customers' => (new Customer())->allOrdered(),
        ]);
    }

    public function store(): void
    {
        $back = '/recurring-rules';
        $this->normalizeCategoryField();
        $clean = $this->validate($this->rules(), $back);

        $type = (string) $clean['type'];
        $frequency = (string) $clean['frequency'];

        $this->assertScheduleShape($frequency, $clean['day_of_month'], $clean['day_of_week'], $back);
        $this->assertPositiveAmount((string) $clean['amount'], $back);
        $startDate = (string) $clean['start_date'];
        $endDate = $clean['end_date'] === null ? null : (string) $clean['end_date'];
        $this->assertDateOrder($startDate, $endDate, $back);

        $this->assertReferences(
            (int) $clean['account_id'],
            (int) $clean['category_id'],
            $type,
            $clean['customer_id'] === null ? null : (int) $clean['customer_id'],
            $back
        );

        $ruleData = $this->ruleDataFor($clean, $type, $frequency, $startDate, $endDate);
        $ruleData['is_active'] = 1;
        $ruleData['created_by'] = Auth::id();

        $rules = new RecurringRule();
        $id = $rules->create($ruleData);

        Logger::info('Recurring rule created', ['rule_id' => $id, 'frequency' => $frequency]);
        Audit::record('recurring_rule.created', 'recurring_rules', $id, null, $ruleData);
        Session::flash('success', (string) $ruleData['description'] . ' recurring rule created.');
        Http::redirect($back);
    }

    /** @param array<string,string> $params */
    public function update(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $rules = new RecurringRule();
        $existing = $rules->find($id);

        if ($existing === null) {
            Http::abort(404);
        }

        $back = '/recurring-rules';
        $this->normalizeCategoryField();
        $clean = $this->validate($this->rules(), $back);

        $type = (string) $clean['type'];
        $frequency = (string) $clean['frequency'];

        $this->assertScheduleShape($frequency, $clean['day_of_month'], $clean['day_of_week'], $back);
        $this->assertPositiveAmount((string) $clean['amount'], $back);
        $startDate = (string) $clean['start_date'];
        $endDate = $clean['end_date'] === null ? null : (string) $clean['end_date'];
        $this->assertDateOrder($startDate, $endDate, $back);

        $this->assertReferences(
            (int) $clean['account_id'],
            (int) $clean['category_id'],
            $type,
            $clean['customer_id'] === null ? null : (int) $clean['customer_id'],
            $back
        );

        $after = $this->ruleDataFor($clean, $type, $frequency, $startDate, $endDate);
        $rules->updateById($id, $after);

        Logger::info('Recurring rule updated', ['rule_id' => $id]);
        $diff = Audit::diff($existing, $after);
        Audit::record('recurring_rule.updated', 'recurring_rules', $id, $diff['before'], $diff['after']);
        Session::flash('success', (string) $after['description'] . ' updated.');
        Http::redirect($back);
    }

    /**
     * Pause/resume. Resuming sets last_generated_date to today (via
     * RecurringRuleService::toggleActive()) so the paused window is never
     * backfilled — see the migration's comment on that column.
     *
     * @param array<string,string> $params
     */
    public function toggle(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $rules = new RecurringRule();
        $rule = $rules->find($id);

        if ($rule === null) {
            Http::abort(404);
        }

        $wasActive = (int) $rule['is_active'] === 1;
        $after = RecurringRuleService::toggleActive($rules, $id, $wasActive);

        Audit::record(
            $wasActive ? 'recurring_rule.paused' : 'recurring_rule.resumed',
            'recurring_rules',
            $id,
            ['is_active' => $rule['is_active'], 'last_generated_date' => $rule['last_generated_date']],
            $after
        );

        Session::flash(
            'success',
            $wasActive
                ? (string) $rule['description'] . ' paused. It will not generate new drafts until resumed.'
                : (string) $rule['description'] . ' resumed.'
        );
        Http::redirect('/recurring-rules');
    }

    /**
     * The form renders two category <select>s — one built from the expense
     * tree, one from the income tree — because expense and income use
     * different category sets (App\Models\Category::tree() per type, same
     * as ExpenseController/IncomeController). Alpine shows only the one
     * matching the selected type, but both are still present in the DOM;
     * giving them distinct names here and merging the right one into
     * `category_id` before validation means the submission is correct by
     * construction, whether or not Alpine ran, rather than depending on
     * client-side visibility to keep only one of two same-named fields
     * from being posted.
     */
    private function normalizeCategoryField(): void
    {
        $type = (string) ($_POST['type'] ?? '');
        $suffix = $type === 'income' ? 'income' : 'expense';
        $_POST['category_id'] = $_POST['category_id_' . $suffix] ?? null;
    }

    /** @return array<string,string> */
    private function rules(): array
    {
        return [
            'type' => 'required|in:income,expense',
            'description' => 'required|max:255',
            'amount' => 'required|numeric',
            'account_id' => 'required|int',
            'category_id' => 'required|int',
            'frequency' => 'required|in:' . implode(',', self::FREQUENCIES),
            'day_of_month' => 'nullable|int|between:1,31',
            'day_of_week' => 'nullable|int|between:0,6',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date',
            'vendor' => 'nullable|max:160',
            'customer_id' => 'nullable|int',
            'reference_no' => 'nullable|max:80',
            'notes' => 'nullable|max:2000',
        ];
    }

    /**
     * The CHECK constraints (chk_recurring_rule_schedule) require exactly
     * one of day_of_month/day_of_week depending on frequency. Validator has
     * no declarative "required when" rule, so this is checked by hand and
     * flashed the same per-field way Controller::validate() does.
     */
    private function assertScheduleShape(
        string $frequency,
        ?string $dayOfMonth,
        ?string $dayOfWeek,
        string $back
    ): void {
        $errors = [];

        if (in_array($frequency, self::MONTHLY_FAMILY, true) && $dayOfMonth === null) {
            $errors['day_of_month'] = ['Day of month is required for this frequency.'];
        }
        if ($frequency === 'weekly' && $dayOfWeek === null) {
            $errors['day_of_week'] = ['Day of week is required for this frequency.'];
        }

        if ($errors !== []) {
            $this->failWithErrors($errors, $back);
        }
    }

    /** Validator's 'numeric' rule allows zero and negatives; the CHECK constraint (amount > 0) does not. */
    private function assertPositiveAmount(string $amount, string $back): void
    {
        if ((float) $amount <= 0) {
            $this->failWithErrors(['amount' => ['Amount must be greater than zero.']], $back);
        }
    }

    private function assertDateOrder(string $startDate, ?string $endDate, string $back): void
    {
        if ($endDate !== null && $endDate < $startDate) {
            $this->failWithErrors(['end_date' => ['End date must be on or after the start date.']], $back);
        }
    }

    private function assertReferences(
        int $accountId,
        int $categoryId,
        string $type,
        ?int $customerId,
        string $back
    ): void {
        try {
            RecurringRuleService::assertReferencesUsable($accountId, $categoryId, $type, $customerId);
        } catch (InvalidArgumentException $e) {
            Session::set('_old', $_POST);
            Session::flash('error', $e->getMessage());
            Http::redirect($back);
        }
    }

    /**
     * Build the row to persist. vendor/customer_id are nulled for whichever
     * side the type does not apply to, and day_of_month/day_of_week for
     * whichever side the frequency does not apply to — regardless of what
     * was actually submitted for the inapplicable field — so the row always
     * satisfies chk_recurring_rule_detail and chk_recurring_rule_schedule
     * rather than depending on the form UI to have hidden it correctly.
     *
     * @param array<string,mixed> $clean
     * @return array<string,mixed>
     */
    private function ruleDataFor(
        array $clean,
        string $type,
        string $frequency,
        string $startDate,
        ?string $endDate
    ): array {
        return [
            'type' => $type,
            'description' => (string) $clean['description'],
            'amount' => (string) $clean['amount'],
            'account_id' => (int) $clean['account_id'],
            'category_id' => (int) $clean['category_id'],
            'vendor' => $type === 'expense' && $clean['vendor'] !== null ? (string) $clean['vendor'] : null,
            'customer_id' => $type === 'income' && $clean['customer_id'] !== null ? (int) $clean['customer_id'] : null,
            'reference_no' => $clean['reference_no'] === null ? null : (string) $clean['reference_no'],
            'notes' => $clean['notes'] === null ? null : (string) $clean['notes'],
            'frequency' => $frequency,
            'day_of_month' => in_array($frequency, self::MONTHLY_FAMILY, true) ? (int) $clean['day_of_month'] : null,
            'day_of_week' => $frequency === 'weekly' ? (int) $clean['day_of_week'] : null,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }

    /** @param array<string,list<string>> $errors */
    private function failWithErrors(array $errors, string $back): never
    {
        Session::set('_old', $_POST);
        Session::flash('errors', $errors);
        Session::flash('error', 'Please correct the highlighted fields.');
        Http::redirect($back);
    }
}
