<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_guest();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rate_limit_check('forgot_password', 5, 300);
    csrf_verify_or_die();

    $email = trim((string) ($_POST['email'] ?? ''));
    $error = validate_required($email, 'Email') ?? validate_email($email);

    if ($error !== null) {
        flash_set('error', $error);
        redirect('forgot-password.php');
    }

    // request_password_reset() returns null silently for unknown emails —
    // the response message is identical either way, to avoid leaking
    // which addresses have accounts (account enumeration protection).
    request_password_reset($email);

    flash_set('status', 'If an account with that email exists, a password reset link has been sent.');
    redirect('forgot-password.php');
}

$pageTitle = 'Forgot Password';
$pageSubtitle = "We'll email you a reset link";
require __DIR__ . '/includes/guest-header.php';
?>

<form method="POST" action="<?= e(url('forgot-password.php')) ?>" novalidate>
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="email" class="form-label">Email address</label>
        <input type="email" class="form-control" id="email" name="email" required autofocus placeholder="you@company.com">
    </div>

    <button type="submit" class="btn btn-primary w-100 py-2 mb-2">Send Reset Link</button>
    <a href="<?= e(url('login.php')) ?>" class="btn btn-link w-100">Back to login</a>
</form>

<?php require __DIR__ . '/includes/guest-footer.php'; ?>
