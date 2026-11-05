<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EmployeeController;
use App\Controllers\PortalController;
use App\Controllers\UserController;
use App\Core\Config;
use App\Core\Database;
use App\Models\EmployeeProfile;
use App\Services\BankDetails;
use App\Services\EmployeeOnboarding;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\BranchFixture;
use Tests\Support\ControllerActionRunner;

/**
 * Employee ID (employee_code) and bank details on an employee (JOIN-1).
 *
 * The guarantees: the Employee ID is optional, letters/digits/hyphens, at
 * most 30, and unique within a branch (case-insensitively) but reusable in
 * another; every admin creation form opens with the owner's defaults; bank
 * details are encrypted at rest, shown in full only on the admin's employee
 * page, masked in the list, absent from the employee's own portal, never in
 * an audit row or the session in plain text, and an undecryptable value is
 * "Unavailable", not an error page.
 */
final class EmployeeCodeAndBankDetailsTest extends TestCase
{
    private const IBAN = 'PK36SCBL0000001123456702';
    private const ACCOUNT = '987654321012';
    private const BANK = 'Ecbd Test Bank';

    private int $branchAId;
    private int $branchBId;
    private int $adminAId;
    private int $adminBId;
    private int $employeeAId;

    protected function setUp(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('The sodium extension is not loaded; enable extension=sodium in php.ini.');
        }

        $_SESSION = [];
        $this->cleanUp();

        $this->branchAId = BranchFixture::create('ECBD-A');
        $this->branchBId = BranchFixture::create('ECBD-B');
        $this->adminAId = $this->insertUser('ecbd-admin-a@test.local', 'admin', $this->branchAId);
        $this->adminBId = $this->insertUser('ecbd-admin-b@test.local', 'admin', $this->branchBId);

        $_SESSION = $this->sessionA();
        $this->employeeAId = (new EmployeeProfile())->createWithLogin([
            'name' => 'Ecbd Employee',
            'email' => 'ecbd-employee@test.local',
            'phone' => null,
            'designation' => 'Technician',
            'joining_date' => '2026-01-15',
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
            'DELETE s FROM employee_salaries s JOIN branches b ON b.id = s.branch_id WHERE b.name LIKE :n',
            ['n' => 'ECBD-%']
        );
        $db->run(
            'DELETE a FROM audit_log a JOIN branches b ON b.id = a.branch_id WHERE b.name LIKE :n',
            ['n' => 'ECBD-%']
        );
        $db->run(
            'DELETE a FROM audit_log a JOIN users u ON u.id = a.user_id WHERE u.email LIKE :e',
            ['e' => 'ecbd-%@test.local']
        );
        $db->delete('users', 'email LIKE :e', ['e' => 'ecbd-%@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'ECBD-%']);
    }

    private function insertUser(string $email, string $role, ?int $branchId): int
    {
        return Database::instance()->insert('users', [
            'name' => 'Ecbd ' . $email,
            'email' => $email,
            'password_hash' => password_hash('unused', PASSWORD_DEFAULT),
            'role' => $role,
            'branch_id' => $branchId,
            'status' => 'active',
        ]);
    }

    /** @return array<string,int> */
    private function sessionA(): array
    {
        return ['_auth_user_id' => $this->adminAId, '_active_branch_id' => $this->branchAId];
    }

    /** @return array<string,int> */
    private function sessionB(): array
    {
        return ['_auth_user_id' => $this->adminBId, '_active_branch_id' => $this->branchBId];
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed>|null $session
     * @param array<string,string> $params
     * @return array{status:int,body:string,session:array<string,mixed>}
     */
    private function act(
        string $controller,
        string $method,
        array $post = [],
        ?array $session = null,
        array $params = [],
        string $httpMethod = 'POST'
    ): array {
        return ControllerActionRunner::run(
            $controller,
            $method,
            $session ?? $this->sessionA(),
            $post,
            [],
            $httpMethod,
            $params
        );
    }

    /**
     * @param array<string,string> $overrides
     * @return array<string,string>
     */
    private function newEmployeePost(string $email, array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Ecbd New Hire',
            'email' => $email,
            'employee_code' => '',
            'phone' => '',
            'designation' => EmployeeOnboarding::DEFAULT_DESIGNATION,
            'joining_date' => '2026-09-01',
            'salary' => EmployeeOnboarding::DEFAULT_STARTING_SALARY,
        ];
    }

    /** @return array<string,mixed>|null */
    private function profileByEmail(string $email): ?array
    {
        return Database::instance()->first(
            'SELECT p.* FROM employee_profiles p JOIN users u ON u.id = p.user_id WHERE u.email = :e',
            ['e' => $email]
        );
    }

    /** @return array<string,mixed> */
    private function rawProfile(int $userId): array
    {
        return (array) Database::instance()->first(
            'SELECT * FROM employee_profiles WHERE user_id = :id',
            ['id' => $userId]
        );
    }

    private function saveBankDetails(): void
    {
        $result = $this->act(EmployeeController::class, 'updateBankDetails', [
            'bank_name' => self::BANK,
            'bank_account_number' => '9876 5432 1012',
            'bank_iban' => 'pk36 scbl 0000 0011 2345 6702',
        ], null, ['id' => (string) $this->employeeAId]);

        self::assertSame(302, $result['status']);
        self::assertArrayNotHasKey('errors', $result['session']['_flash'] ?? []);
    }

    // --- Employee ID ----------------------------------------------------------

    public function testEmployeeIdIsUniquePerBranchIgnoringCaseButReusableInAnotherBranch(): void
    {
        $first = $this->act(EmployeeController::class, 'store', $this->newEmployeePost(
            'ecbd-first@test.local',
            ['employee_code' => 'EMP-001']
        ));
        self::assertSame(302, $first['status']);
        self::assertSame('EMP-001', $this->profileByEmail('ecbd-first@test.local')['employee_code'] ?? null);

        $clash = $this->act(EmployeeController::class, 'store', $this->newEmployeePost(
            'ecbd-clash@test.local',
            ['employee_code' => 'emp-001']
        ));
        self::assertSame(302, $clash['status']);
        self::assertArrayHasKey('employee_code', $clash['session']['_flash']['errors'] ?? []);
        self::assertNull($this->profileByEmail('ecbd-clash@test.local'));

        $otherBranch = $this->act(EmployeeController::class, 'store', $this->newEmployeePost(
            'ecbd-other@test.local',
            ['employee_code' => 'EMP-001']
        ), $this->sessionB());
        self::assertSame(302, $otherBranch['status']);
        self::assertSame('EMP-001', $this->profileByEmail('ecbd-other@test.local')['employee_code'] ?? null);
    }

    public function testEmployeeIdAllowsOnlyLettersDigitsAndHyphensUpTo30AndIsOptional(): void
    {
        foreach (['EMP 001', 'EMP_001', 'EMP/1', str_repeat('A', 31)] as $index => $bad) {
            $email = 'ecbd-bad' . $index . '@test.local';
            $result = $this->act(EmployeeController::class, 'store', $this->newEmployeePost(
                $email,
                ['employee_code' => $bad]
            ));
            self::assertArrayHasKey('employee_code', $result['session']['_flash']['errors'] ?? [], $bad);
            self::assertNull($this->profileByEmail($email), $bad . ' must create nothing');
        }

        $this->act(EmployeeController::class, 'store', $this->newEmployeePost(
            'ecbd-long@test.local',
            ['employee_code' => str_repeat('A', 30)]
        ));
        self::assertSame(str_repeat('A', 30), $this->profileByEmail('ecbd-long@test.local')['employee_code'] ?? null);

        $this->act(EmployeeController::class, 'store', $this->newEmployeePost('ecbd-none@test.local'));
        $profile = $this->profileByEmail('ecbd-none@test.local');
        self::assertNotNull($profile);
        self::assertNull($profile['employee_code']);
    }

    public function testAdminCanSetAndChangeAnExistingEmployeesIdButNotToATakenOne(): void
    {
        $edit = fn (string $code): array => $this->act(EmployeeController::class, 'update', [
            'name' => 'Ecbd Employee',
            'email' => 'ecbd-employee@test.local',
            'employee_code' => $code,
            'phone' => '',
            'designation' => 'Technician',
            'joining_date' => '2026-01-15',
        ], null, ['id' => (string) $this->employeeAId]);

        self::assertNull($this->rawProfile($this->employeeAId)['employee_code']);
        self::assertSame(302, $edit('E-7')['status']);
        self::assertSame('E-7', $this->rawProfile($this->employeeAId)['employee_code']);
        // Saving its own code again is not a clash.
        self::assertArrayNotHasKey('errors', $edit('E-7')['session']['_flash'] ?? []);

        $this->act(EmployeeController::class, 'store', $this->newEmployeePost(
            'ecbd-second@test.local',
            ['employee_code' => 'E-8']
        ));
        self::assertArrayHasKey('employee_code', $edit('e-8')['session']['_flash']['errors'] ?? []);
        self::assertSame('E-7', $this->rawProfile($this->employeeAId)['employee_code']);
    }

    public function testTheUsersScreenAlsoChecksTheEmployeeId(): void
    {
        $this->act(EmployeeController::class, 'store', $this->newEmployeePost(
            'ecbd-first@test.local',
            ['employee_code' => 'U-1']
        ));

        $result = $this->act(UserController::class, 'store', $this->newEmployeePost(
            'ecbd-via-users@test.local',
            ['employee_code' => 'U-1', 'role' => 'employee']
        ));

        self::assertArrayHasKey('employee_code', $result['session']['_flash']['errors'] ?? []);
        self::assertNull($this->profileByEmail('ecbd-via-users@test.local'));
    }

    public function testListAndProfileShowTheEmployeeId(): void
    {
        Database::instance()->update(
            'employee_profiles',
            ['employee_code' => 'SHOWN-42'],
            'user_id = :id',
            ['id' => $this->employeeAId]
        );

        $index = $this->act(EmployeeController::class, 'index', [], null, [], 'GET');
        $show = $this->act(EmployeeController::class, 'show', [], null, ['id' => (string) $this->employeeAId], 'GET');

        self::assertStringContainsString('SHOWN-42', $index['body']);
        self::assertStringContainsString('Employee ID', $show['body']);
        self::assertStringContainsString('SHOWN-42', $show['body']);
    }

    // --- Defaults -------------------------------------------------------------

    public function testEveryAdminCreationFormOpensWithTheDefaults(): void
    {
        $create = $this->act(EmployeeController::class, 'create', [], null, [], 'GET');
        $users = $this->act(UserController::class, 'index', [], null, [], 'GET');

        foreach ([$create['body'], $users['body']] as $body) {
            self::assertStringContainsString('value="' . EmployeeOnboarding::DEFAULT_DESIGNATION . '"', $body);
            self::assertStringContainsString('value="' . EmployeeOnboarding::DEFAULT_STARTING_SALARY . '"', $body);
        }
        self::assertSame('Fronter', EmployeeOnboarding::DEFAULT_DESIGNATION);
        self::assertSame('35000', EmployeeOnboarding::DEFAULT_STARTING_SALARY);
    }

    public function testAManualAddWithTheDefaultsWritesTheFirstSalaryRowFromTheJoiningDate(): void
    {
        $this->act(EmployeeController::class, 'store', $this->newEmployeePost('ecbd-default@test.local'));

        $profile = $this->profileByEmail('ecbd-default@test.local');
        self::assertNotNull($profile);
        self::assertSame('Fronter', $profile['designation']);

        $salaries = Database::instance()->all(
            'SELECT amount, effective_from FROM employee_salaries WHERE user_id = :id',
            ['id' => $profile['user_id']]
        );
        self::assertCount(1, $salaries);
        self::assertSame('35000.00', (string) $salaries[0]['amount']);
        self::assertSame('2026-09-01', (string) $salaries[0]['effective_from']);
    }

    public function testDefaultsAreEditableOnAManualAdd(): void
    {
        $this->act(EmployeeController::class, 'store', $this->newEmployeePost(
            'ecbd-edited@test.local',
            ['designation' => 'Closer', 'salary' => '42000']
        ));

        $profile = $this->profileByEmail('ecbd-edited@test.local');
        self::assertSame('Closer', $profile['designation'] ?? null);
        self::assertSame('42000.00', (string) Database::instance()->value(
            'SELECT amount FROM employee_salaries WHERE user_id = :id',
            ['id' => $profile['user_id'] ?? 0]
        ));
    }

    // --- Bank details ---------------------------------------------------------

    public function testBankDetailsAreEncryptedAtRestAndDecryptToTheNormalisedValues(): void
    {
        $this->saveBankDetails();

        $row = $this->rawProfile($this->employeeAId);
        $rawColumns = implode('|', [$row['bank_name_enc'], $row['bank_account_number_enc'], $row['bank_iban_enc']]);
        foreach ([self::IBAN, self::ACCOUNT, self::BANK, '6702', '1012'] as $plain) {
            self::assertStringNotContainsString($plain, $rawColumns);
        }

        $details = BankDetails::decrypt($row, 'users', $this->employeeAId);
        self::assertSame(self::BANK, $details['bank_name']);
        self::assertSame(self::ACCOUNT, $details['bank_account_number']);
        self::assertSame(self::IBAN, $details['bank_iban']);
        self::assertSame([], $details['unavailable']);
    }

    public function testFullValuesOnlyOnTheAdminPageMaskedInTheListAndAbsentFromThePortal(): void
    {
        $this->saveBankDetails();

        $show = $this->act(EmployeeController::class, 'show', [], null, ['id' => (string) $this->employeeAId], 'GET');
        self::assertSame(200, $show['status']);
        self::assertStringContainsString(self::IBAN, $show['body']);
        self::assertStringContainsString(self::ACCOUNT, $show['body']);

        $index = $this->act(EmployeeController::class, 'index', [], null, [], 'GET');
        self::assertStringContainsString('****1012', $index['body']);
        self::assertStringNotContainsString(self::ACCOUNT, $index['body']);
        self::assertStringNotContainsString(self::IBAN, $index['body']);

        $portal = $this->act(
            PortalController::class,
            'index',
            [],
            ['_auth_user_id' => $this->employeeAId, '_active_branch_id' => $this->branchAId],
            [],
            'GET'
        );
        self::assertSame(200, $portal['status']);
        foreach ([self::IBAN, self::ACCOUNT, self::BANK, '****', 'Bank details'] as $hidden) {
            self::assertStringNotContainsString($hidden, $portal['body']);
        }
    }

    public function testTheAuditRowNamesTheChangeWithMaskedValuesOnly(): void
    {
        $this->saveBankDetails();

        $meta = (string) Database::instance()->value(
            "SELECT meta FROM audit_log WHERE action = 'employee.bank_details_changed' AND entity_id = :id",
            ['id' => $this->employeeAId]
        );
        self::assertNotSame('', $meta);
        foreach ([self::IBAN, self::ACCOUNT, self::BANK] as $plain) {
            self::assertStringNotContainsString($plain, $meta);
        }

        $decoded = json_decode($meta, true);
        self::assertIsArray($decoded);
        self::assertSame('****6702', $decoded['after']['bank_iban'] ?? null);
        self::assertSame('****1012', $decoded['after']['bank_account_number'] ?? null);
        self::assertEqualsCanonicalizing(
            ['bank_name', 'bank_account_number', 'bank_iban'],
            $decoded['after']['changed'] ?? []
        );
    }

    public function testAnInvalidIbanStoresNothingAndIsNeverFlashedBack(): void
    {
        $result = $this->act(EmployeeController::class, 'updateBankDetails', [
            'bank_name' => self::BANK,
            'bank_account_number' => self::ACCOUNT,
            'bank_iban' => 'PK37SCBL0000001123456702',
        ], null, ['id' => (string) $this->employeeAId]);

        self::assertSame(302, $result['status']);
        self::assertArrayHasKey('bank_iban', $result['session']['_flash']['errors'] ?? []);
        self::assertSame(self::BANK, $result['session']['_old']['bank_name'] ?? null);
        self::assertArrayNotHasKey('bank_iban', $result['session']['_old'] ?? []);
        self::assertArrayNotHasKey('bank_account_number', $result['session']['_old'] ?? []);

        $row = $this->rawProfile($this->employeeAId);
        self::assertNull($row['bank_name_enc']);
        self::assertNull($row['bank_account_number_enc']);
        self::assertNull($row['bank_iban_enc']);
    }

    public function testEmptyFieldsClearStoredBankDetails(): void
    {
        $this->saveBankDetails();

        $this->act(EmployeeController::class, 'updateBankDetails', [
            'bank_name' => self::BANK,
            'bank_account_number' => '',
            'bank_iban' => '',
        ], null, ['id' => (string) $this->employeeAId]);

        $row = $this->rawProfile($this->employeeAId);
        self::assertNotNull($row['bank_name_enc']);
        self::assertNull($row['bank_account_number_enc']);
        self::assertNull($row['bank_iban_enc']);
    }

    public function testAnotherBranchsAdminCannotReadOrChangeBankDetails(): void
    {
        $this->saveBankDetails();
        $before = $this->rawProfile($this->employeeAId);

        $show = $this->act(EmployeeController::class, 'show', [], $this->sessionB(), [
            'id' => (string) $this->employeeAId,
        ], 'GET');
        $change = $this->act(EmployeeController::class, 'updateBankDetails', [
            'bank_name' => 'Other',
            'bank_account_number' => '111111',
            'bank_iban' => '',
        ], $this->sessionB(), ['id' => (string) $this->employeeAId]);

        self::assertSame(404, $show['status']);
        self::assertStringNotContainsString(self::IBAN, $show['body']);
        self::assertSame(404, $change['status']);
        $after = $this->rawProfile($this->employeeAId);
        self::assertSame($before['bank_account_number_enc'], $after['bank_account_number_enc']);
    }

    public function testAnUndecryptableValueShowsUnavailableInsteadOfAnError(): void
    {
        Database::instance()->update(
            'employee_profiles',
            ['bank_iban_enc' => base64_encode(random_bytes(60))],
            'user_id = :id',
            ['id' => $this->employeeAId]
        );

        $show = $this->act(EmployeeController::class, 'show', [], null, ['id' => (string) $this->employeeAId], 'GET');
        $index = $this->act(EmployeeController::class, 'index', [], null, [], 'GET');

        self::assertSame(200, $show['status']);
        self::assertStringContainsString('Unavailable', $show['body']);
        self::assertSame(200, $index['status']);
        self::assertStringContainsString('Unavailable', $index['body']);
    }

    // --- Duplicate-key race ---------------------------------------------------

    /**
     * Two requests both pass conflicts(), then the second write hits the
     * unique key. Reproduced here by doing exactly that write after the
     * conflicting row exists: the genuine PDOException must come back as the
     * same field error conflicts() gives, not an error page.
     */
    public function testALostRaceOnTheEmployeeIdBecomesAFieldError(): void
    {
        $other = (new EmployeeProfile())->createWithLogin([
            'name' => 'Ecbd Other',
            'email' => 'ecbd-other@test.local',
            'phone' => null,
            'designation' => 'Technician',
            'joining_date' => null,
            'employee_code' => 'RACE-1',
        ], password_hash('unused', PASSWORD_DEFAULT));
        self::assertGreaterThan(0, $other);
        $fields = ['email' => 'ecbd-employee@test.local', 'employee_code' => 'RACE-1'];

        try {
            Database::instance()->update(
                'employee_profiles',
                ['employee_code' => 'RACE-1'],
                'user_id = :id',
                ['id' => $this->employeeAId]
            );
            self::fail('The unique key must reject a second RACE-1 in the branch.');
        } catch (PDOException $e) {
            self::assertSame(
                ['employee_code' => 'Another employee in this branch already has that Employee ID.'],
                EmployeeOnboarding::conflictsFromDuplicate($e, $fields, $this->employeeAId)
            );
        }
    }

    public function testALostRaceOnTheEmailBecomesAFieldError(): void
    {
        try {
            $this->insertUser('ecbd-employee@test.local', 'employee', $this->branchAId);
            self::fail('The unique key must reject a second login with this email.');
        } catch (PDOException $e) {
            self::assertSame(
                ['email' => 'That email is already in use by another account.'],
                EmployeeOnboarding::conflictsFromDuplicate(
                    $e,
                    ['email' => 'ecbd-employee@test.local', 'employee_code' => null]
                )
            );
        }
    }

    public function testOtherDatabaseFailuresAreNotDisguisedAsFieldErrors(): void
    {
        // A foreign-key failure (SQLSTATE 23000 too, but not a duplicate).
        try {
            Database::instance()->insert('employee_profiles', [
                'user_id' => 999999999,
                'branch_id' => $this->branchAId,
                'designation' => 'Nobody',
            ]);
            self::fail('The foreign key must reject an unknown user.');
        } catch (PDOException $e) {
            try {
                $fields = ['email' => 'ecbd-new@test.local', 'employee_code' => null];
                EmployeeOnboarding::conflictsFromDuplicate($e, $fields);
                self::fail('A non-duplicate failure must be rethrown.');
            } catch (PDOException $rethrown) {
                self::assertSame($e, $rethrown);
            }
        }

        // A duplicate that no longer matches a field (nothing to show) is rethrown too.
        try {
            $this->insertUser('ecbd-employee@test.local', 'employee', $this->branchAId);
        } catch (PDOException $e) {
            $this->expectException(PDOException::class);
            $fields = ['email' => 'ecbd-free@test.local', 'employee_code' => null];
            EmployeeOnboarding::conflictsFromDuplicate($e, $fields);
        }
    }

    // --- Missing key ----------------------------------------------------------

    public function testAFormWithNoBankDetailsNeverNeedsTheKey(): void
    {
        $items = new ReflectionProperty(Config::class, 'items');
        $original = $items->getValue();
        self::assertIsArray($original);
        $withoutKey = $original;
        $withoutKey['app']['encryption_key'] = '';
        $items->setValue(null, $withoutKey);

        try {
            self::assertSame(
                ['bank_name_enc' => null, 'bank_account_number_enc' => null, 'bank_iban_enc' => null],
                BankDetails::encrypt(['bank_name' => null, 'bank_account_number' => '', 'bank_iban' => null])
            );

            $this->expectException(RuntimeException::class);
            BankDetails::encrypt(['bank_name' => self::BANK, 'bank_account_number' => null, 'bank_iban' => null]);
        } finally {
            $items->setValue(null, $original);
        }
    }
}
