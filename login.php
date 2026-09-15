<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_guest();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rate_limit_check('login', 20, 60);
    csrf_verify_or_die();

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $error = validate_required($email, 'Email') ?? validate_email($email) ?? validate_required($password, 'Password');

    if ($error !== null) {
        flash_set('error', $error);
        redirect('login.php');
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $result = attempt_login_credentials($email, $password, $ip, $userAgent);

    if (!$result['ok']) {
        flash_set('error', $result['error']);
        redirect('login.php');
    }

    $user = $result['user'];

    if ((bool) $user['two_factor_enabled']) {
        issue_login_otp($user['email'], (int) $user['id']);
        $_SESSION['pending_otp_email'] = $user['email'];
        $_SESSION['pending_otp_remember'] = !empty($_POST['remember']);
        redirect('login-otp.php');
    }

    establish_session($user, $ip, $userAgent);

    if (!empty($_POST['remember'])) {
        issue_remember_me_cookie((int) $user['id']);
    }

    redirect('dashboard.php');
}

$pageTitle = 'Sign In';
require __DIR__ . '/includes/guest-header.php';
?>

<form method="POST" action="<?= e(url('login.php')) ?>" novalidate>
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="email" class="form-label">Email address</label>
        <input type="email" class="form-control" id="email" name="email" required autofocus
               placeholder="you@company.com" value="<?= e($_POST['email'] ?? '') ?>">
    </div>

    <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <div class="input-group">
            <input type="password" class="form-control" id="password" name="password" required placeholder="••••••••">
            <button class="btn btn-outline-secondary bi bi-eye" type="button" data-toggle-password="password" aria-label="Show password"></button>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
            <label class="form-check-label" for="remember">Remember me</label>
        </div>
        <a href="<?= e(url('forgot-password.php')) ?>" class="small text-decoration-none">Forgot password?</a>
    </div>

    <button type="submit" class="btn btn-primary w-100 py-2">Sign In</button>

    <p class="text-center small text-muted mt-3 mb-0">
        Don't have an account? <a href="<?= e(url('register.php')) ?>" class="text-decoration-none">Register</a>
    </p>
</form>

<?php require __DIR__ . '/includes/guest-footer.php'; ?>
