<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_guest();

if (empty($_SESSION['pending_otp_email'])) {
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rate_limit_check('otp_verify', 10, 60);
    csrf_verify_or_die();

    $email = $_SESSION['pending_otp_email'];
    $otp = trim((string) ($_POST['otp'] ?? ''));

    if (!verify_login_otp($email, $otp)) {
        flash_set('error', 'Invalid or expired OTP. Please try again.');
        redirect('login-otp.php');
    }

    $user = find_user_by_email($email);
    if (!$user) {
        redirect('login.php');
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    establish_session($user, $ip, $userAgent);

    if (!empty($_SESSION['pending_otp_remember'])) {
        issue_remember_me_cookie((int) $user['id']);
    }

    unset($_SESSION['pending_otp_email'], $_SESSION['pending_otp_remember']);
    redirect('dashboard.php');
}

$pageTitle = 'Verify OTP';
$pageSubtitle = 'Enter the 6-digit code sent to your email';
require __DIR__ . '/includes/guest-header.php';
?>

<form method="POST" action="<?= e(url('login-otp.php')) ?>" novalidate>
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="otp" class="form-label">Verification Code</label>
        <input type="text" class="form-control text-center fs-4 letter-spacing-wide" id="otp" name="otp"
               inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus placeholder="000000">
    </div>

    <button type="submit" class="btn btn-primary w-100 py-2 mb-2">Verify &amp; Continue</button>
    <a href="<?= e(url('login.php')) ?>" class="btn btn-link w-100">Back to login</a>
</form>

<?php require __DIR__ . '/includes/guest-footer.php'; ?>
