<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_guest();

/*
 * NOTE ON DESIGN: this is included because the requested project
 * structure lists register.php as a standard page. In practice, most
 * CRM/ERP systems don't allow public self-registration — employee
 * accounts are usually created by an admin (see admin/users.php). This
 * page is left open here for completeness and easy local testing, but
 * consider gating it behind require_login() + require_role('super_admin')
 * before deploying somewhere with real, uncontrolled public access.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rate_limit_check('register', 5, 300);
    csrf_verify_or_die();

    $fullName = sanitize_string($_POST['full_name'] ?? '');
    $email = trim((string) ($_POST['email'] ?? ''));
    $mobile = sanitize_string($_POST['mobile'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

    $error = validate_required($fullName, 'Full name')
        ?? validate_required($email, 'Email')
        ?? validate_email($email)
        ?? validate_required($password, 'Password')
        ?? validate_strong_password($password)
        ?? validate_matches($password, $passwordConfirmation, 'Passwords do not match.');

    if ($error === null && find_user_by_email($email) !== false) {
        $error = 'An account with that email already exists.';
    }

    if ($error !== null) {
        flash_set('error', $error);
        redirect('register.php');
    }

    $userId = create_user([
        'role' => 'employee',
        'full_name' => $fullName,
        'email' => $email,
        'mobile' => $mobile !== '' ? $mobile : null,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        'status' => 'inactive', // admin must Activate this account in Employees before they can log in
        'two_factor_enabled' => 0,
    ]);

    audit_log($userId, 'auth', 'register', 'New account registered.');

    flash_set('status', 'Account created successfully. Please wait for admin approval before you can log in.');
    redirect('login.php');
}

$pageTitle = 'Create Account';
$pageSubtitle = 'Register a new employee account';
require __DIR__ . '/includes/guest-header.php';
?>

<form method="POST" action="<?= e(url('register.php')) ?>" novalidate>
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="full_name" class="form-label">Full name</label>
        <input type="text" class="form-control" id="full_name" name="full_name" required autofocus
               value="<?= e($_POST['full_name'] ?? '') ?>">
    </div>

    <div class="mb-3">
        <label for="email" class="form-label">Email address</label>
        <input type="email" class="form-control" id="email" name="email" required
               placeholder="you@company.com" value="<?= e($_POST['email'] ?? '') ?>">
    </div>

    <div class="mb-3">
        <label for="mobile" class="form-label">Mobile <span class="text-muted small">(optional)</span></label>
        <input type="tel" class="form-control" id="mobile" name="mobile" value="<?= e($_POST['mobile'] ?? '') ?>">
    </div>

    <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <div class="input-group">
            <input type="password" class="form-control" id="password" name="password" required>
            <button class="btn btn-outline-secondary bi bi-eye" type="button" data-toggle-password="password" aria-label="Show password"></button>
        </div>
        <div class="form-text">At least 8 characters, with uppercase, lowercase, a number, and a symbol.</div>
    </div>

    <div class="mb-3">
        <label for="password_confirmation" class="form-label">Confirm Password</label>
        <div class="input-group">
            <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required>
            <button class="btn btn-outline-secondary bi bi-eye" type="button" data-toggle-password="password_confirmation" aria-label="Show password"></button>
        </div>
    </div>

    <button type="submit" class="btn btn-primary w-100 py-2">Create Account</button>

    <p class="text-center small text-muted mt-3 mb-0">
        Already have an account? <a href="<?= e(url('login.php')) ?>" class="text-decoration-none">Sign in</a>
    </p>
</form>

<?php require __DIR__ . '/includes/guest-footer.php'; ?>
