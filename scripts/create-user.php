<?php

/**
 * Creates or updates a user from the command line.
 *
 *   php scripts/create-user.php "Full Name" email@example.com admin
 *
 * The password is read from STDIN, never from the arguments: arguments show up
 * in `ps` output and in shell history.
 *
 * Recommended, and the only form that keeps the password off the screen on
 * every host — hide it with the shell's own builtin and pipe it in:
 *
 *   read -rs -p "Password: " P; echo
 *   printf '%s\n' "$P" | php scripts/create-user.php "Full Name" you@example.com admin
 *   unset P
 *
 * An earlier version called shell_exec('stty -echo') to hide typing. Hostinger
 * disables shell_exec, so the script died with a fatal error instead of
 * creating the account. Terminal echo is the shell's job, not this script's.
 *
 * There is deliberately no web route for this: self-registration is not part
 * of the product, and the first admin has to come from somewhere trustworthy.
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

// When STDIN is a terminal the password will be visible, because hiding it
// needs a shell call this script will not make. Say so rather than let someone
// type a real password onto a shared screen believing it is masked.
$interactive = function_exists('stream_isatty') && @stream_isatty(STDIN);

if ($interactive) {
    fwrite(STDERR, "Note: typing will be VISIBLE. To hide it, cancel and use:\n");
    fwrite(STDERR, "  read -rs -p \"Password: \" P; echo\n");
    fwrite(STDERR, "  printf '%s\\n' \"\$P\" | php scripts/create-user.php " . escapeshellarg($name)
        . ' ' . escapeshellarg($email) . " {$role}\n");
    fwrite(STDERR, "  unset P\n\n");
}

fwrite(STDERR, "Password (min {$minLength} characters): ");
$password = rtrim((string) fgets(STDIN), "\r\n");
fwrite(STDERR, "\n");

if (mb_strlen($password) < $minLength) {
    fwrite(STDERR, "Password must be at least {$minLength} characters. Nothing was written.\n");
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
