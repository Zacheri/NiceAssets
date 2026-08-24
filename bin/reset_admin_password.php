<?php

require __DIR__ . '/_bootstrap.php';

use App\Core\Database;
use App\Models\User;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/reset_admin_password.php <username> <new-password>\n");
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}
$username = $argv[1];
$password = $argv[2];
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}
$user = Database::fetchOne('SELECT id FROM users WHERE username = :u', ['u' => $username]);
if ($user === null) {
    fwrite(STDERR, "User '{$username}' not found.\n");
    exit(1);
}
Database::execute(
    'UPDATE users SET password_hash = :p, login_failures = 0, locked_until = NULL, updated_at = now() WHERE id = :id',
    ['p' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]
);
User::log('user.password_reset', 'user', (string) $user['id'], ['username' => $username, 'via' => 'cli']);
echo "Password reset for {$username}.\n";
