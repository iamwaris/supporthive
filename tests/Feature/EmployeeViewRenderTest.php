<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\View;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Services\Access;
use PHPUnit\Framework\TestCase;
use Tests\Support\BranchFixture;

/**
 * Renders every employee screen through the real layout, as the role that
 * sees it. Catches template errors, and pins down what the shell shows an
 * employee: only the "My workspace" navigation and no AI assistant, since
 * both lead to routes an employee is denied.
 */
final class EmployeeViewRenderTest extends TestCase
{
    private int $branchId;
    private int $adminId;
    private int $employeeId;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $db = Database::instance();
        $this->branchId = BranchFixture::create('EVR');
        $this->adminId = $db->insert('users', [
            'name' => 'EVR Admin',
            'email' => 'evr-admin@test.local',
            'password_hash' => password_hash('unused', PASSWORD_DEFAULT),
            'role' => 'admin',
            'branch_id' => $this->branchId,
            'status' => 'active',
        ]);

        $_SESSION = ['_auth_user_id' => $this->adminId, '_active_branch_id' => $this->branchId];
        $this->employeeId = (new EmployeeProfile())->createWithLogin([
            'name' => 'EVR <b>Employee</b>',
            'email' => 'evr-employee@test.local',
            'phone' => '+92 300 1234567',
            'designation' => 'Analyst',
            'joining_date' => '2026-03-01',
        ], password_hash('unused', PASSWORD_DEFAULT));
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        $_SESSION = [];
    }

    private function cleanUp(): void
    {
        $db = Database::instance();
        $db->run(
            'DELETE d FROM employee_documents d JOIN branches b ON b.id = d.branch_id WHERE b.name LIKE :n',
            ['n' => 'EVR%']
        );
        $db->run(
            'DELETE a FROM audit_log a JOIN branches b ON b.id = a.branch_id WHERE b.name LIKE :n',
            ['n' => 'EVR%']
        );
        $db->delete('users', 'email LIKE :e', ['e' => 'evr-%@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'EVR%']);
    }

    /** @param array<string,mixed> $data */
    private function render(string $template, array $data): string
    {
        $user = Database::instance()->first('SELECT * FROM users WHERE id = :id', ['id' => $_SESSION['_auth_user_id']]);

        return View::capture($template, $data + [
            'authUser' => $user,
            'flash' => ['success' => null, 'error' => null],
            'errors' => [],
            'title' => 'Test',
            'nav' => '',
        ]);
    }

    private function seedDocument(): void
    {
        (new EmployeeDocument())->create([
            'title' => 'EVR <script>Handbook</script>',
            'description' => "Line one\nLine two",
            'original_filename' => 'handbook.pdf',
            'stored_filename' => bin2hex(random_bytes(16)) . '.pdf',
            'size' => 2048,
            'uploaded_by' => $this->adminId,
        ]);
    }

    public function testAdminScreensRenderAndEscapeUserData(): void
    {
        $this->seedDocument();
        $employee = (new EmployeeProfile())->findWithUser($this->employeeId);
        self::assertNotNull($employee);
        $list = ['total' => 1, 'page' => 1, 'pages' => 1, 'perPage' => 20];

        $index = $this->render('pages/employees/index', $list + [
            'employees' => (new EmployeeProfile())->search(null, null, 1, 20),
            'filters' => ['q' => null, 'status' => null],
        ]);
        $show = $this->render('pages/employees/show', ['employee' => $employee]);
        $create = $this->render('pages/employees/form', ['employee' => null]);
        $edit = $this->render('pages/employees/form', ['employee' => $employee]);
        $documents = $this->render('pages/employee-documents', $list + [
            'documents' => (new EmployeeDocument())->search(null, 1, 20),
            'term' => null,
        ]);

        foreach ([$index, $show, $edit] as $html) {
            self::assertStringContainsString('EVR &lt;b&gt;Employee&lt;/b&gt;', $html);
            self::assertStringNotContainsString('<b>Employee</b>', $html);
        }
        self::assertStringContainsString('Add employee', $create);
        self::assertStringContainsString('value="2026-03-01"', $edit);
        self::assertStringContainsString('EVR &lt;script&gt;Handbook&lt;/script&gt;', $documents);
        self::assertStringContainsString('Line one<br />', $documents);
        self::assertStringContainsString('(opens in new tab)', $documents);
        self::assertStringContainsString('/delete', $documents);
        $peopleLink = 'href="' . url('/employees') . '"';
        self::assertStringContainsString($peopleLink, $index, 'admin sidebar has the People group');
    }

    public function testEmployeeShellShowsOnlyTheWorkspaceAndNoAssistant(): void
    {
        $this->seedDocument();
        $_SESSION = ['_auth_user_id' => $this->employeeId, '_active_branch_id' => $this->branchId];

        $home = $this->render('pages/portal/home', [
            'nav' => 'portal',
            'profile' => (new EmployeeProfile())->findWithUser($this->employeeId),
            'documents' => (new EmployeeDocument())->recent(5),
        ]);
        $documents = $this->render('pages/portal/documents', [
            'nav' => 'portal-documents',
            'documents' => [],
            'term' => 'nothing-matches',
            'total' => 0,
            'page' => 1,
            'pages' => 1,
            'perPage' => 20,
        ]);
        $missingProfile = $this->render('pages/portal/home', ['profile' => null, 'documents' => []]);

        self::assertStringContainsString('My workspace', $home);
        self::assertStringContainsString('Analyst', $home);
        self::assertStringContainsString('(opens in new tab)', $home);
        self::assertStringNotContainsString('/delete', $home, 'employees get no delete control');
        self::assertStringNotContainsString('href="' . url('/dashboard') . '"', $home);
        self::assertStringNotContainsString('href="' . url('/expenses') . '"', $home);
        self::assertStringNotContainsString('aiChatWidget', $home);
        self::assertStringContainsString('No documents match', $documents);
        self::assertStringContainsString('has not been set up yet', $missingProfile);
        self::assertStringContainsString('No documents have been shared with you yet.', $missingProfile);
    }

    /**
     * Add user offers Employee with its profile fields, keeps a failed
     * submit's input and shows each field's own error; the per-row edit
     * selects still never offer Employee.
     */
    public function testUsersAddFormOffersEmployeeWithItsFields(): void
    {
        $_SESSION['_old'] = ['role' => 'employee', 'name' => 'EVR New', 'phone' => '<0300>', 'joining_date' => ''];

        $html = $this->render('pages/users', [
            'users' => (new User())->nonEmployeesOrdered(),
            'roles' => Access::ASSIGNABLE,
            'creatableRoles' => Access::CREATABLE,
            'errors' => ['designation' => ['The designation field is required.']],
        ]);

        self::assertStringContainsString('<option value="employee" selected>', $html);
        self::assertSame(1, substr_count($html, 'value="employee"'), 'only the Add user select offers Employee');
        self::assertStringContainsString('Employee details', $html);
        self::assertStringContainsString('Only needed for employees.', $html);
        foreach (['new-u-designation', 'new-u-phone', 'new-u-joining-date'] as $inputId) {
            self::assertStringContainsString('<label for="' . $inputId . '"', $html);
        }
        self::assertStringContainsString('id="new-u-designation-error" class="error"', $html);
        self::assertStringContainsString('value="&lt;0300&gt;"', $html, 'old() input is kept and escaped');
        self::assertStringContainsString('href="' . url('/employees') . '"', $html);
        self::assertStringContainsString('Manage employees', $html);
    }
}
