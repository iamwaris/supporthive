<?php

/**
 * Creates or updates a user from the command line.
 *
 *   php scripts/create-user.php "Full Name" email@example.com admin
 *
 * The password is read from stdin, never passed as an argument: arguments are
 * visible in `ps` and land in shell history. There is deliberately no web
 * route for this — self-registration is not part of this product, and the
 * first admin has to come from somewhere trustworthy.
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

$name = $argv[1] ?? '';
$email = strtolower(trim($argv[2] ?? ''));
$role = $argv[3] ?? Access::PARTNER;

if ($name === '' || $email === '') {
    fwrite(STDERR, "Usage: php scripts/create-user.php \"Full Name\" email@example.com [admin|partner]\n");
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

$minLength = (int) Config::get('security.password_min_length', 12);

echo "Password (min {$minLength} chars, not echoed): ";
shell_exec('stty -echo 2>/dev/null');
$password = trim((string) fgets(STDIN));
shell_exec('stty echo 2>/dev/null');
echo "\n";

if (mb_strlen($password) < $minLength) {
    fwrite(STDERR, "Password must be at least {$minLength} characters.\n");
    exit(1);
}

$db = Database::instance();
$existing = $db->first('SELECT id FROM users WHERE email = :email LIMIT 1', ['email' => $email]);

if ($existing !== null) {
    $db->update(
        'users',
        ['name' => $name, 'role' => $role, 'status' => 'active', 'password_hash' => Auth::hash($password)],
        'id = :id',
        ['id' => $existing['id']]
    );
    echo "Updated existing user #{$existing['id']} ({$email}) as {$role}.\n";
    exit(0);
}

$id = $db->insert('users', [
    'name' => $name,
    'email' => $email,
    'password_hash' => Auth::hash($password),
    'role' => $role,
    'status' => 'active',
    'email_verified_at' => date('Y-m-d H:i:s'),
]);

echo "Created user #{$id} ({$email}) as {$role}.\n";
