<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\BranchController;
use App\Controllers\EmployeeDocumentController;
use App\Core\Database;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\BranchFixture;
use Tests\Support\ControllerActionRunner;

/**
 * Employee documents (App\Models\EmployeeDocument, EmployeeDocumentController).
 *
 * The guarantees: a document id from another branch does not exist from here
 * (preview, download and delete all 404 on it), nothing is written for a
 * rejected upload, deleting removes the row and the file, and deleting a
 * whole branch that has employees and documents still succeeds.
 */
final class EmployeeDocumentTest extends TestCase
{
    private const SUPER_ADMIN_PASSWORD = 'emd-super-admin-password';

    private int $branchAId;
    private int $branchBId;
    private int $adminAId;
    private int $adminBId;
    private int $employeeAId;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->cleanUp();

        $this->branchAId = BranchFixture::create('EMD-A');
        $this->branchBId = BranchFixture::create('EMD-B');
        $this->adminAId = $this->insertUser('emd-admin-a@test.local', 'admin', $this->branchAId);
        $this->adminBId = $this->insertUser('emd-admin-b@test.local', 'admin', $this->branchBId);

        $this->asBranchA();
        $this->employeeAId = (new EmployeeProfile())->createWithLogin([
            'name' => 'EMD Employee A',
            'email' => 'emd-employee-a@test.local',
            'phone' => null,
            'designation' => 'Clerk',
            'joining_date' => null,
        ], password_hash('unused-in-this-test', PASSWORD_DEFAULT));
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $_SESSION = [];
    }

    private function cleanUp(): void
    {
        $db = Database::instance();
        $db->run(
            'DELETE d FROM employee_documents d JOIN branches b ON b.id = d.branch_id WHERE b.name LIKE :n',
            ['n' => 'EMD-%']
        );
        $db->run(
            'DELETE a FROM audit_log a JOIN branches b ON b.id = a.branch_id WHERE b.name LIKE :n',
            ['n' => 'EMD-%']
        );
        $db->run(
            'DELETE a FROM audit_log a JOIN users u ON u.id = a.user_id WHERE u.email LIKE :e',
            ['e' => 'emd-%@test.local']
        );
        $db->delete('users', 'email LIKE :e', ['e' => 'emd-%@test.local']);
        $db->delete('branches', 'name LIKE :n', ['n' => 'EMD-%']);
    }

    private function insertUser(string $email, string $role, ?int $branchId, string $password = 'unused'): int
    {
        return Database::instance()->insert('users', [
            'name' => 'EMD ' . $email,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'branch_id' => $branchId,
            'status' => 'active',
        ]);
    }

    private function asBranchA(): void
    {
        $_SESSION = ['_auth_user_id' => $this->adminAId, '_active_branch_id' => $this->branchAId];
    }

    private function asBranchB(): void
    {
        $_SESSION = ['_auth_user_id' => $this->adminBId, '_active_branch_id' => $this->branchBId];
    }

    /** Insert a document row with a real file on disk, as the current session's branch. */
    private function publish(string $title, ?string $description = null): int
    {
        $stored = bin2hex(random_bytes(16)) . '.pdf';
        $path = EmployeeDocument::filePath(['stored_filename' => $stored]);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, "%PDF-1.4\n% EMD test document\n");
        $this->files[] = $path;

        return (new EmployeeDocument())->create([
            'title' => $title,
            'description' => $description,
            'original_filename' => 'policy.pdf',
            'stored_filename' => $stored,
            'size' => (int) filesize($path),
            'uploaded_by' => (int) $_SESSION['_auth_user_id'],
        ]);
    }

    private function documentCount(): int
    {
        return (int) Database::instance()->value(
            'SELECT COUNT(*) FROM employee_documents WHERE branch_id IN (:a, :b)',
            ['a' => $this->branchAId, 'b' => $this->branchBId]
        );
    }

    /**
     * @param array<string,string> $session
     * @param array<string,string> $post
     * @param array<string,array{name:string,type:string,tmp_name:string,error:int,size:int}> $files
     */
    private function act(string $method, array $session, array $post = [], array $files = [], ?int $id = null): int
    {
        return ControllerActionRunner::run(
            EmployeeDocumentController::class,
            $method,
            $session,
            $post,
            $files,
            in_array($method, ['preview', 'download'], true) ? 'GET' : 'POST',
            $id === null ? [] : ['id' => (string) $id]
        )['status'];
    }

    /** @return array<string,int> */
    private function employeeSession(): array
    {
        return ['_auth_user_id' => $this->employeeAId, '_active_branch_id' => $this->branchAId];
    }

    /** @return array<string,int> */
    private function adminSession(): array
    {
        return ['_auth_user_id' => $this->adminAId, '_active_branch_id' => $this->branchAId];
    }

    public function testFindSearchAndRecentNeverReachAnotherBranch(): void
    {
        $this->asBranchB();
        $documentB = $this->publish('EMD Handbook B');
        $this->asBranchA();
        $documentA = $this->publish('EMD Handbook A', 'Read before your first day');

        $documents = new EmployeeDocument();
        self::assertNotNull($documents->find($documentA));
        self::assertNull($documents->find($documentB), 'Branch B\'s document id must not resolve from Branch A');

        self::assertSame([$documentA], array_map('intval', array_column($documents->search('handbook', 1, 20), 'id')));
        self::assertSame([$documentA], array_map('intval', array_column($documents->search('first day', 1, 20), 'id')));
        self::assertSame(1, $documents->searchCount(null));
        self::assertSame([$documentA], array_map('intval', array_column($documents->recent(5), 'id')));
    }

    public function testPreviewDownloadAndDelete404OnAnotherBranchsDocument(): void
    {
        $this->asBranchB();
        $documentB = $this->publish('EMD Handbook B');

        self::assertSame(404, $this->act('preview', $this->employeeSession(), [], [], $documentB));
        self::assertSame(404, $this->act('download', $this->employeeSession(), [], [], $documentB));
        self::assertSame(404, $this->act('destroy', $this->adminSession(), ['x' => '1'], [], $documentB));

        self::assertSame(1, $this->documentCount(), 'the other branch\'s document must survive');
        self::assertFileExists(end($this->files));
    }

    public function testAMissingFileIsA404NotAnError(): void
    {
        $this->asBranchA();
        $id = $this->publish('EMD Orphan');
        unlink(end($this->files));

        self::assertSame(404, $this->act('download', $this->employeeSession(), [], [], $id));
    }

    public function testDeleteWithFileRemovesTheRowAndTheFile(): void
    {
        $this->asBranchA();
        $id = $this->publish('EMD To Delete');
        $path = end($this->files);

        self::assertTrue((new EmployeeDocument())->deleteWithFile($id));

        self::assertSame(0, $this->documentCount());
        self::assertFileDoesNotExist($path);
        self::assertSame(1, (int) Database::instance()->value(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'employee_document.deleted' AND entity_id = :id",
            ['id' => $id]
        ));
        self::assertFalse((new EmployeeDocument())->deleteWithFile($id), 'a second delete finds nothing');
    }

    public function testStoreWithoutAFileOrWithAnInvalidTitleInsertsNothing(): void
    {
        self::assertSame(302, $this->act('store', $this->adminSession(), ['title' => 'EMD No File']));
        self::assertSame(302, $this->act('store', $this->adminSession(), ['title' => str_repeat('x', 151)]));

        self::assertSame(0, $this->documentCount());
    }

    public function testStoreUploadRejectsAFileThatWasNotUploadedOverHttp(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'emd');
        file_put_contents($path, "%PDF-1.4\n");
        $this->files[] = $path;

        $this->asBranchA();
        try {
            (new EmployeeDocument())->storeUpload(['title' => 'EMD Forged', 'description' => null], [
                'name' => 'forged.pdf',
                'type' => 'application/pdf',
                'tmp_name' => $path,
                'error' => UPLOAD_ERR_OK,
                'size' => (int) filesize($path),
            ]);
            self::fail('a file that is_uploaded_file() rejects must not be stored');
        } catch (RuntimeException $e) {
            self::assertSame('Invalid upload.', $e->getMessage());
        }

        self::assertSame(0, $this->documentCount());
    }

    public function testStoreUploadRejectsAnOversizedFile(): void
    {
        $this->asBranchA();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too large');

        try {
            (new EmployeeDocument())->storeUpload(['title' => 'EMD Huge', 'description' => null], [
                'name' => 'huge.pdf',
                'type' => 'application/pdf',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_INI_SIZE,
                'size' => 0,
            ]);
        } finally {
            self::assertSame(0, $this->documentCount());
        }
    }

    /**
     * employee_documents.uploaded_by references users, and employee_profiles
     * references branches: deleting a branch with both must still succeed,
     * and take the files with it.
     */
    public function testDeletingABranchWithEmployeesAndDocumentsSucceeds(): void
    {
        $this->asBranchA();
        $this->publish('EMD Branch Doc');
        $path = end($this->files);
        $superAdminId = $this->insertUser('emd-super@test.local', 'super_admin', null, self::SUPER_ADMIN_PASSWORD);
        $branchName = (string) Database::instance()->value(
            'SELECT name FROM branches WHERE id = :id',
            ['id' => $this->branchAId]
        );

        $status = ControllerActionRunner::run(
            BranchController::class,
            'destroy',
            ['_auth_user_id' => $superAdminId],
            ['confirm_name' => $branchName, 'confirm_password' => self::SUPER_ADMIN_PASSWORD],
            [],
            'POST',
            ['id' => (string) $this->branchAId]
        )['status'];

        $db = Database::instance();
        self::assertSame(302, $status);
        $count = static fn (string $sql, int $id): int => (int) $db->value($sql, ['id' => $id]);
        self::assertSame(0, $count('SELECT COUNT(*) FROM branches WHERE id = :id', $this->branchAId));
        self::assertSame(0, $count('SELECT COUNT(*) FROM users WHERE id = :id', $this->employeeAId));
        self::assertSame(0, (int) $db->value(
            'SELECT COUNT(*) FROM employee_profiles WHERE user_id = :id',
            ['id' => $this->employeeAId]
        ));
        self::assertSame(0, $this->documentCount());
        self::assertFileDoesNotExist($path);
    }
}
