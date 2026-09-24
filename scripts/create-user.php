<?php

/**
 * Creates or updates a user from the command line.
 *
 *   php scripts/create-user.php "Full Name" email@example.com admin "Branch Name"
 *   php scripts/create-user.php "Full Name" email@example.com admin 2
 *
 * The branch is required for every role this script can create (admin,
 * partner — super_admin is deliberately not one of them, see below), by
 * name or numeric id. Omit it only when exactly one branch exists; with
 * more than one, guessing which branch a new login belongs to is exactly
 * the kind of mistake multi-branch isolation exists to prevent, so the
 * script refuses to guess and lists the branches instead.
 *
 * The password is read from STDIN, never from the arguments: arguments show up
 * in `ps` output and in shell history.
 *
 * Recommended, and the only form that keeps the password off the screen on
 * every host — hide it with the shell's own builtin and pipe it in:
 *
 *   read -rs -p "Password: " P; echo
 *   printf '%s\n' "$P" | php scripts/create-user.php "Full Name" you@example.com admin "Branch Name"
 *   unset P
 *
 * An earlier version called shell_exec('stty -echo') to hide typing. Hostinger
 * disables shell_exec, so the script died with a fatal error instead of
 * creating the account. Terminal echo is the shell's job, not this script's.
 *
 * There is deliberately no web route for this: self-registration is not part
 * of the product, and the first admin has to come from somewhere trustworthy.
 * super_admin is deliberately not creatable here either — see
 * App\Services\Access::ASSIGNABLE and docs/PLAN.md — it is granted by a
 * direct, one-off database action, never a repeatable tool.
 *
 * CLI only.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Services\Access;

$name = trim($argv[1] ?? '');
$email = strtolower(trim($argv[2] ?? ''));
$role = $argv[3] ?? Access::PARTNER;
$branchArg = trim($argv[4] ?? '');

if ($name === '' || $email === '') {
    fwrite(STDERR, 'Usage: php scripts/create-user.php "Full Name" email@example.com'
        . " [admin|partner] [branch name or id]\n");
    exit(1);
}

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Not a valid email address: {$email}\n");
    exit(1);
}

if (!in_array($role, Access::ASSIGNABLE, true)) {
    fwrite(STDERR, 'Role must be one of: ' . implode(', ', Access::ASSIGNABLE) . "\n");
    exit(1);
}

$db = Database::instance();

/** @return array{id:int,name:string}|null */
$findBranch = static function (string $arg) use ($db): ?array {
    if ($arg === '') {
        return null;
    }

    $sql = ctype_digit($arg)
        ? 'SELECT id, name FROM branches WHERE id = :ref AND is_active = 1'
        : 'SELECT id, name FROM branches WHERE name = :ref AND is_active = 1';

    $row = $db->first($sql, ['ref' => $arg]);

    return $row === null ? null : ['id' => (int) $row['id'], 'name' => (string) $row['name']];
};

/** @return list<array{id:int,name:string}> */
$listBranches = static function () use ($db): array {
    $rows = $db->all('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name');
    return array_map(
        static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
        $rows
    );
};

$branch = $findBranch($branchArg);

if ($branch === null && $branchArg !== '') {
    fwrite(STDERR, "No active branch matches \"{$branchArg}\". Active branches:\n");
    foreach ($listBranches() as $b) {
        fwrite(STDERR, "  #{$b['id']}  {$b['name']}\n");
    }
    exit(1);
}

if ($branch === null) {
    // No branch given — only acceptable when there is exactly one to pick.
    $all = $listBranches();
    if (count($all) === 1) {
        $branch = $all[0];
        fwrite(STDERR, "No branch given; using the only active branch: {$branch['name']} (#{$branch['id']}).\n");
    } else {
        fwrite(STDERR, "A branch is required — more than one exists. Pass a name or id:\n");
        foreach ($all as $b) {
            fwrite(STDERR, "  #{$b['id']}  {$b['name']}\n");
        }
        exit(1);
    }
}

$minLength = (int) Config::get('security.password_min_length', 12);

// When STDIN is a terminal the password will be visible, because hiding it
// needs a shell call this script will not make. Say so rather than let someone
// type a real password onto a shared screen believing it is masked.
$interactive = function_exists('stream_isatty') && @stream_isatty(STDIN);

if ($interactive) {
    fwrite(STDERR, "Note: typing will be VISIBLE. To hide it, cancel and use:\n");
    fwrite(STDERR, "  read -rs -p \"Password: \" P; echo\n");
    fwrite(STDERR, "  printf '%s\\n' \"\$P\" | php scripts/create-user.php " . escapeshellarg($name)
        . ' ' . escapeshellarg($email) . " {$role} " . escapeshellarg((string) $branch['id']) . "\n");
    fwrite(STDERR, "  unset P\n\n");
}

fwrite(STDERR, "Password (min {$minLength} characters): ");
$password = rtrim((string) fgets(STDIN), "\r\n");
fwrite(STDERR, "\n");

if (mb_strlen($password) < $minLength) {
    fwrite(STDERR, "Password must be at least {$minLength} characters. Nothing was written.\n");
    exit(1);
}

$existing = $db->first('SELECT id FROM users WHERE email = :email LIMIT 1', ['email' => $email]);

// Every password this script sets is one only the operator has typed, never
// the account's own owner — the account must change it on first login.
if ($existing !== null) {
    $db->update(
        'users',
        [
            'name' => $name,
            'role' => $role,
            'branch_id' => $branch['id'],
            'status' => 'active',
            'password_hash' => Auth::hash($password),
            'must_change_password' => 1,
        ],
        'id = :id',
        ['id' => $existing['id']]
    );
    echo "Updated existing user #{$existing['id']} ({$email}) as {$role} in {$branch['name']}."
        . " Must change password on next login.\n";
    exit(0);
}

$id = $db->insert('users', [
    'name' => $name,
    'email' => $email,
    'password_hash' => Auth::hash($password),
    'role' => $role,
    'branch_id' => $branch['id'],
    'status' => 'active',
    'must_change_password' => 1,
    'email_verified_at' => date('Y-m-d H:i:s'),
]);

echo "Created user #{$id} ({$email}) as {$role} in {$branch['name']}. Must change password on first login.\n";
