<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Http;
use App\Core\Logger;
use App\Core\RememberMe;
use App\Core\Session;
use App\Core\Validator;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalary;
use App\Models\User;
use App\Services\Audit;
use App\Services\BankDetails;
use App\Services\EmployeeOnboarding;
use PDOException;

/**
 * Employee management, admin only (`can:administer`), scoped to the admin's
 * branch. Every action that takes an id resolves it through
 * EmployeeProfile::findWithUser() first, which only ever returns an employee
 * of this branch — an admin's id, a partner's id or another branch's
 * employee is a 404 here, never a record to act on.
 *
 * Employees are created with a one-time temporary password and must change
 * it on first sign-in, the same treatment UserController gives every login.
 */
final class EmployeeController extends Controller
{
    private const PER_PAGE = 20;

    private const RULES = [
        'name' => 'required|max:120',
        'email' => 'required|email|max:190',
    ] + EmployeeOnboarding::PROFILE_RULES;

    public function index(): void
    {
        $filters = Validator::make($this->queryStrings(['q', 'status', 'page']), [
            'q' => 'nullable|max:100',
            'status' => 'nullable|in:active,suspended',
            'page' => 'nullable|int|between:1,100000',
        ])->validated();

        // A field that failed validation is simply absent from validated(),
        // so a tampered filter falls back to its default instead of erroring.
        $term = isset($filters['q']) ? (string) $filters['q'] : null;
        $status = isset($filters['status']) ? (string) $filters['status'] : null;
        $page = isset($filters['page']) ? (int) $filters['page'] : 1;

        $employees = new EmployeeProfile();
        $total = $employees->searchCount($term, $status);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $rows = $employees->search($term, $status, $page, self::PER_PAGE);

        $this->view('pages/employees/index', [
            'title' => 'Employees',
            'nav' => 'employees',
            'pageTitle' => 'Employees',
            'pageMeta' => $total === 1 ? '1 employee' : number_format($total) . ' employees',
            'employees' => $rows,
            'salaries' => (new EmployeeSalary())->currentForUsers(
                array_map(static fn (array $row): int => (int) $row['id'], $rows)
            ),
            // Masked (****1234) — a list never shows a whole account number.
            'bankMasks' => self::bankMasks($rows),
            'filters' => ['q' => $term, 'status' => $status],
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
        ]);
    }

    public function create(): void
    {
        $this->view('pages/employees/form', [
            'title' => 'Add employee',
            'nav' => 'employees',
            'pageTitle' => 'Add employee',
            'pageMeta' => 'A one-time temporary password is shown once after saving.',
            'employee' => null,
            'defaults' => [
                'designation' => EmployeeOnboarding::DEFAULT_DESIGNATION,
                'salary' => EmployeeOnboarding::DEFAULT_STARTING_SALARY,
            ],
        ]);
    }

    public function store(): void
    {
        $clean = $this->validate(self::RULES + EmployeeOnboarding::STARTING_SALARY_RULES, '/employees/new');
        $fields = EmployeeOnboarding::normalise($clean);

        $conflicts = EmployeeOnboarding::conflicts($fields);
        if ($conflicts !== []) {
            $this->failWithFieldErrors($conflicts, '/employees/new');
        }

        try {
            $created = EmployeeOnboarding::create($fields, EmployeeOnboarding::startingSalary($clean));
        } catch (PDOException $e) {
            $this->failWithFieldErrors(EmployeeOnboarding::conflictsFromDuplicate($e, $fields), '/employees/new');
        }
        Session::flash(
            'success',
            EmployeeOnboarding::credentialsNotice($fields['name'], $fields['email'], $created['temporaryPassword'])
        );
        Http::redirect('/employees/' . $created['id']);
    }

    /** @param array<string,string> $params */
    public function show(array $params): void
    {
        $employee = $this->findOr404($params);
        $id = (int) $employee['id'];
        $salaries = new EmployeeSalary();

        $this->view('pages/employees/show', [
            'title' => (string) $employee['name'],
            'nav' => 'employees',
            'pageTitle' => (string) $employee['name'],
            'pageMeta' => (string) $employee['designation'],
            'employee' => $employee,
            'currentSalary' => $salaries->current($id),
            'upcomingSalary' => $salaries->upcoming($id),
            'salaryHistory' => $salaries->history($id),
            'bankDetails' => $this->bankDetailsOf($id),
        ]);
    }

    /**
     * The only way a salary changes: a new effective-dated row, audited.
     * There is no edit or delete — a mistake is corrected by recording the
     * right amount, which keeps the history honest.
     *
     * @param array<string,string> $params
     */
    public function changeSalary(array $params): void
    {
        $employee = $this->findOr404($params);
        $id = (int) $employee['id'];

        $clean = $this->validate(EmployeeSalary::CHANGE_RULES, '/employees/' . $id);

        (new EmployeeSalary())->record(
            $id,
            (string) $clean['amount'],
            (string) $clean['effective_from'],
            $clean['note'] === null ? null : (string) $clean['note']
        );
        Logger::security('Employee salary changed', ['user_id' => $id, 'by' => Auth::id()]);

        Session::flash('success', 'Salary updated.');
        Http::redirect('/employees/' . $id);
    }

    /** @param array<string,string> $params */
    public function edit(array $params): void
    {
        $employee = $this->findOr404($params);

        $this->view('pages/employees/form', [
            'title' => 'Edit employee',
            'nav' => 'employees',
            'pageTitle' => 'Edit ' . (string) $employee['name'],
            'pageMeta' => null,
            'employee' => $employee,
        ]);
    }

    /** @param array<string,string> $params */
    public function update(array $params): void
    {
        $employee = $this->findOr404($params);
        $id = (int) $employee['id'];
        $back = '/employees/' . $id . '/edit';

        $fields = EmployeeOnboarding::normalise($this->validate(self::RULES, $back));

        $conflicts = EmployeeOnboarding::conflicts($fields, $id);
        if ($conflicts !== []) {
            $this->failWithFieldErrors($conflicts, $back);
        }

        try {
            (new EmployeeProfile())->updateWithLogin($id, $fields);
        } catch (PDOException $e) {
            $this->failWithFieldErrors(EmployeeOnboarding::conflictsFromDuplicate($e, $fields, $id), $back);
        }

        $before = array_intersect_key($employee, $fields);
        $diff = Audit::diff($before, $fields);
        if ($diff['after'] !== []) {
            Audit::record('employee.updated', 'users', $id, $diff['before'], $diff['after']);
        }
        Logger::security('Employee updated', ['user_id' => $id, 'by' => Auth::id()]);

        Session::flash('success', 'Employee updated.');
        Http::redirect('/employees/' . $id);
    }

    /**
     * Deactivates or reactivates the login. A deactivated employee cannot
     * sign in, and a session they already have open ends on its next request
     * (Auth::requireLogin() re-checks status every time).
     *
     * @param array<string,string> $params
     */
    public function toggleStatus(array $params): void
    {
        $employee = $this->findOr404($params);
        $id = (int) $employee['id'];
        $wasActive = (string) $employee['status'] === 'active';
        $newStatus = $wasActive ? 'suspended' : 'active';

        (new User())->updateById($id, ['status' => $newStatus]);
        if ($wasActive) {
            RememberMe::revokeAllForUser($id);
        }

        Logger::security($wasActive ? 'Employee deactivated' : 'Employee reactivated', [
            'user_id' => $id,
            'by' => Auth::id(),
        ]);
        Audit::record(
            $wasActive ? 'employee.deactivated' : 'employee.reactivated',
            'users',
            $id,
            ['status' => $employee['status']],
            ['status' => $newStatus]
        );
        Session::flash('success', (string) $employee['name'] . ($wasActive ? ' deactivated.' : ' reactivated.'));
        Http::redirect('/employees/' . $id);
    }

    /** @param array<string,string> $params */
    public function resetPassword(array $params): void
    {
        $employee = $this->findOr404($params);
        $id = (int) $employee['id'];

        $tempPassword = bin2hex(random_bytes(9));
        (new User())->updateById($id, ['password_hash' => Auth::hash($tempPassword), 'must_change_password' => 1]);
        RememberMe::revokeAllForUser($id);

        Logger::security('Employee password reset', ['user_id' => $id, 'by' => Auth::id()]);
        Audit::record('employee.password_reset', 'users', $id, null, null);
        Session::flash(
            'success',
            'New temporary password for ' . (string) $employee['name'] . ': ' . $tempPassword
            . ' — shown once; the employee must change it on next sign-in.'
        );
        Http::redirect('/employees/' . $id);
    }

    /**
     * Bank name, account number and IBAN: admin-only. Each field is
     * optional; a blank field clears it. The audit row says which fields
     * changed and shows only masked values.
     *
     * @param array<string,string> $params
     */
    public function updateBankDetails(array $params): void
    {
        $employee = $this->findOr404($params);
        $id = (int) $employee['id'];

        $clean = $this->validate(
            BankDetails::RULES,
            '/employees/' . $id . '#bank-details',
            BankDetails::normaliseInput($_POST),
            BankDetails::NOT_FLASHED
        );

        $before = $this->bankDetailsOf($id);
        (new EmployeeProfile())->updateBankDetails($id, BankDetails::from($clean));

        $change = BankDetails::auditChange($before, $clean);
        if ($change !== null) {
            Audit::record('employee.bank_details_changed', 'users', $id, null, $change);
        }
        Logger::security('Employee bank details changed', ['user_id' => $id, 'by' => Auth::id()]);

        Session::flash('success', 'Bank details saved.');
        Http::redirect('/employees/' . $id . '#bank-details');
    }

    /** @return array{bank_name:?string,bank_account_number:?string,bank_iban:?string} */
    private function bankDetailsOf(int $id): array
    {
        return BankDetails::from((new EmployeeProfile())->bankDetails($id) ?? []);
    }

    /**
     * @param list<array<string,mixed>> $rows EmployeeProfile::search() rows
     * @return array<int,string> user id => masked account number or IBAN
     */
    private static function bankMasks(array $rows): array
    {
        $masks = [];
        foreach ($rows as $row) {
            $mask = BankDetails::maskedSummary($row);
            if ($mask !== null) {
                $masks[(int) $row['id']] = $mask;
            }
        }

        return $masks;
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private function findOr404(array $params): array
    {
        $employee = (new EmployeeProfile())->findWithUser((int) ($params['id'] ?? 0));
        if ($employee === null) {
            Http::abort(404);
        }

        return $employee;
    }

    /** @param array<string,string> $messages field => message */
    private function failWithFieldErrors(array $messages, string $back): never
    {
        $old = $_POST;
        unset($old[Csrf::FIELD]);
        Session::set('_old', $old);
        Session::flash('errors', array_map(static fn (string $message): array => [$message], $messages));
        Session::flash('error', 'Please correct the highlighted fields.');
        Http::redirect($back);
    }

    /**
     * Only string query values reach the Validator; an array (?q[]=x) is
     * dropped rather than cast.
     *
     * @param list<string> $keys
     * @return array<string,string>
     */
    private function queryStrings(array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            if (isset($_GET[$key]) && is_string($_GET[$key])) {
                $values[$key] = $_GET[$key];
            }
        }

        return $values;
    }
}
