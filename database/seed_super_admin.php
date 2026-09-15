<?php

declare(strict_types=1);

/**
 * database/seed_super_admin.php
 * Creates the initial Super Admin account.
 * Usage: php database/seed_super_admin.php
 *
 * Reads credentials from environment variables (or interactive prompts)
 * rather than hardcoding them, so no default password ever ships in
 * source control.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

// auth.php pulls in session.php/csrf.php internals we don't need for a
// CLI script, so only require the pieces actually used here.
require_once __DIR__ . '/../includes/auth.php';

function prompt(string $label, bool $hidden = false): string
{
    echo $label;
    if ($hidden && stripos(PHP_OS, 'WIN') !== 0) {
        system('stty -echo');
        $value = trim((string) fgets(STDIN));
        system('stty echo');
        echo "\n";
        return $value;
    }
    return trim((string) fgets(STDIN));
}

$name = getenv('SEED_ADMIN_NAME') ?: prompt('Full name: ');
$email = getenv('SEED_ADMIN_EMAIL') ?: prompt('Email: ');
$password = getenv('SEED_ADMIN_PASSWORD') ?: prompt('Password (min 8 chars, mixed case, number, symbol): ', true);

if (find_user_by_email($email) !== false) {
    fwrite(STDERR, "A user with that email already exists. Aborting.\n");
    exit(1);
}

$passwordError = validate_strong_password($password);
if ($passwordError !== null) {
    fwrite(STDERR, "{$passwordError}\n");
    exit(1);
}

$id = create_user([
    'role' => 'super_admin',
    'full_name' => $name,
    'email' => $email,
    'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
    'status' => 'active',
    'two_factor_enabled' => 0,
]);

echo "Super Admin created with ID {$id}.\n";
