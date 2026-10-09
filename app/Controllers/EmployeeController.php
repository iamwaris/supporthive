<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Session;
use App\Core\Validator;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalary;
use App\Models\User;
use App\Services\Audit;
use App\Services\EmployeeOnboarding;

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
        ]);
    }

    public function store(): void
    {
        $clean = $this->validate(self::RULES + EmployeeOnboarding::STARTING_SALARY_RULES, '/employees/new');
        $fields = EmployeeOnboarding::normalise($clean);

        if ((new User())->emailExists($fields['email'])) {
            $this->failWithFieldError('email', 'That email is already in use by another account.', '/employees/new');
        }

        $created = EmployeeOnboarding::create($fields, EmployeeOnboarding::startingSalary($clean));
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

        if ((new User())->emailExists($fields['email'], $id)) {
            $this->failWithFieldError('email', 'That email is already in use by another account.', $back);
        }

        (new EmployeeProfile())->updateWithLogin($id, $fields);

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

    private function failWithFieldError(string $field, string $message, string $back): never
    {
        $old = $_POST;
        unset($old[Csrf::FIELD]);
        Session::set('_old', $old);
        Session::flash('errors', [$field => [$message]]);
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
