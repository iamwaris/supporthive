<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use App\Services\Access;

/**
 * An employee: a users row with role 'employee' plus its employee_profiles
 * row, always written and read together.
 *
 * findWithUser() is the single ownership gate for every employee screen. It
 * matches the id, the employee role AND this branch in one query, so an
 * admin's own id, a partner's id, or another branch's employee id are all
 * simply "not found" — there is no separate check to forget.
 */
final class EmployeeProfile extends Model
{
    protected string $table = 'employee_profiles';
    protected string $primaryKey = 'user_id';

    /** @var list<string> */
    protected array $fillable = ['user_id', 'phone', 'designation', 'joining_date'];

    /**
     * Create the login and its profile in one transaction — a profile with no
     * login, or an employee login with no profile, is never left behind.
     *
     * @param array{name:string,email:string,phone:?string,designation:string,joining_date:?string} $clean
     */
    public function createWithLogin(array $clean, string $passwordHash): int
    {
        return (int) Database::instance()->transaction(function () use ($clean, $passwordHash): int {
            $userId = (new User())->create([
                'name' => $clean['name'],
                'email' => $clean['email'],
                'password_hash' => $passwordHash,
                'role' => Access::EMPLOYEE,
                'status' => 'active',
                'must_change_password' => 1,
            ]);

            $this->create([
                'user_id' => $userId,
                'phone' => $clean['phone'],
                'designation' => $clean['designation'],
                'joining_date' => $clean['joining_date'],
            ]);

            return $userId;
        });
    }

    /**
     * Callers resolve $userId through findWithUser() first; the role filter
     * here is a second guard so this can never rename a non-employee login.
     *
     * @param array{name:string,email:string,phone:?string,designation:string,joining_date:?string} $clean
     */
    public function updateWithLogin(int $userId, array $clean): void
    {
        Database::instance()->transaction(function (Database $db) use ($userId, $clean): void {
            $db->update(
                'users',
                ['name' => $clean['name'], 'email' => $clean['email']],
                "id = :id AND branch_id = :branch AND role = 'employee'",
                ['id' => $userId, 'branch' => $this->requireBranchId()]
            );

            $this->updateById($userId, [
                'phone' => $clean['phone'],
                'designation' => $clean['designation'],
                'joining_date' => $clean['joining_date'],
            ]);
        });
    }

    /** @return array<string,mixed>|null */
    public function findWithUser(int $userId): ?array
    {
        return $this->db()->first(
            "SELECT u.id, u.name, u.email, u.status, u.must_change_password, u.last_login_at, u.created_at,
                    p.phone, p.designation, p.joining_date, p.branch_id, b.name AS branch_name
             FROM users u
             JOIN employee_profiles p ON p.user_id = u.id
             JOIN branches b ON b.id = p.branch_id
             WHERE u.id = :id AND u.role = 'employee' AND p.branch_id = :branch
             LIMIT 1",
            ['id' => $userId, 'branch' => $this->requireBranchId()]
        );
    }

    /** @return list<array<string,mixed>> */
    public function search(?string $term, ?string $status, int $page, int $perPage): array
    {
        $perPage = max(1, min($perPage, 100));
        [$where, $params] = $this->searchConditions($term, $status);

        return $this->db()->all(
            "SELECT u.id, u.name, u.email, u.status, u.must_change_password, p.phone, p.designation, p.joining_date
             FROM users u
             JOIN employee_profiles p ON p.user_id = u.id
             WHERE {$where}
             ORDER BY u.status ASC, u.name ASC
             LIMIT :take OFFSET :skip",
            $params + ['take' => $perPage, 'skip' => max(0, ($page - 1) * $perPage)]
        );
    }

    public function searchCount(?string $term, ?string $status): int
    {
        [$where, $params] = $this->searchConditions($term, $status);

        return (int) $this->db()->value(
            "SELECT COUNT(*) FROM users u JOIN employee_profiles p ON p.user_id = u.id WHERE {$where}",
            $params
        );
    }

    /**
     * Built from fixed SQL fragments only; every value is a bound parameter.
     * One placeholder per use: with native prepares a repeated named
     * placeholder fails with HY093 (see LedgerQuery::search()).
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function searchConditions(?string $term, ?string $status): array
    {
        $conditions = ["u.role = 'employee'", 'p.branch_id = :branch'];
        $params = ['branch' => $this->requireBranchId()];

        $term = trim((string) $term);
        if ($term !== '') {
            $pattern = '%' . addcslashes($term, '%_\\') . '%';
            $conditions[] = '(u.name LIKE :q1 OR u.email LIKE :q2 OR p.designation LIKE :q3 OR p.phone LIKE :q4)';
            $params += ['q1' => $pattern, 'q2' => $pattern, 'q3' => $pattern, 'q4' => $pattern];
        }

        if ($status !== null) {
            $conditions[] = 'u.status = :status';
            $params['status'] = $status;
        }

        return [implode(' AND ', $conditions), $params];
    }
}
