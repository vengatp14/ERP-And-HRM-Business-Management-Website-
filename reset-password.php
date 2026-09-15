<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_guest();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $token = (string) ($_POST['token'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

    csrf_verify_or_die();

    $error = validate_required($password, 'Password')
        ?? validate_strong_password($password)
        ?? validate_matches($password, $passwordConfirmation, 'Passwords do not match.');

    if ($error !== null) {
        flash_set('error', $error);
        redirect('reset-password.php?email=' . urlencode($email) . '&token=' . urlencode($token));
    }

    if (!reset_password($email, $token, $password)) {
        flash_set('error', 'This reset link is invalid or has expired. Please request a new one.');
        redirect('forgot-password.php');
    }

    flash_set('status', 'Password reset successful. Please log in with your new password.');
    redirect('login.php');
}

$email = trim((string) ($_GET['email'] ?? ''));
$token = (string) ($_GET['token'] ?? '');

$pageTitle = 'Reset Password';
$pageSubtitle = 'Choose a new password';
require __DIR__ . '/includes/guest-header.php';
?>

<form method="POST" action="<?= e(url('reset-password.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="email" value="<?= e($email) ?>">
    <input type="hidden" name="token" value="<?= e($token) ?>">

    <div class="mb-3">
        <label for="password" class="form-label">New Password</label>
        <div class="input-group">
            <input type="password" class="form-control" id="password" name="password" required autofocus>
            <button class="btn btn-outline-secondary bi bi-eye" type="button" data-toggle-password="password" aria-label="Show password"></button>
        </div>
        <div class="form-text">At least 8 characters, with uppercase, lowercase, a number, and a symbol.</div>
    </div>

    <div class="mb-3">
        <label for="password_confirmation" class="form-label">Confirm New Password</label>
        <div class="input-group">
            <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required>
            <button class="btn btn-outline-secondary bi bi-eye" type="button" data-toggle-password="password_confirmation" aria-label="Show password"></button>
        </div>
    </div>

    <button type="submit" class="btn btn-primary w-100 py-2">Reset Password</button>
</form>

<?php require __DIR__ . '/includes/guest-footer.php'; ?>
