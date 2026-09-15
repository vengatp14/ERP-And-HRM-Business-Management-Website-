<?php

declare(strict_types=1);

/**
 * includes/auth.php
 * All authentication business logic: credential checking, login-attempt
 * lockout, OTP 2FA, remember-me, password reset, and session guards.
 */

// ---------------------------------------------------------------------
// User lookups
// ---------------------------------------------------------------------

function find_user_by_email(string $email): array|false
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => mb_strtolower(trim($email))]);
    return $stmt->fetch();
}

function find_user_by_id(int $id): array|false
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}

function create_user(array $data): int
{
    $data['uuid'] = generate_uuid_v4();
    $data['email'] = mb_strtolower(trim($data['email']));

    $columns = array_keys($data);
    $placeholders = array_map(static fn ($c) => ':' . $c, $columns);
    $sql = sprintf('INSERT INTO users (%s) VALUES (%s)', implode(', ', $columns), implode(', ', $placeholders));

    $stmt = db()->prepare($sql);
    $stmt->execute($data);

    return (int) db()->lastInsertId();
}

function update_user_password(int $userId, string $plainPassword): bool
{
    $hash = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = db()->prepare('UPDATE users SET password_hash = :hash, must_change_password = 0 WHERE id = :id');
    return $stmt->execute(['hash' => $hash, 'id' => $userId]);
}

function touch_last_login(int $userId): void
{
    $stmt = db()->prepare('UPDATE users SET last_login_at = :now WHERE id = :id');
    $stmt->execute(['now' => date('Y-m-d H:i:s'), 'id' => $userId]);
}

// ---------------------------------------------------------------------
// Login attempts / lockout
//
// IMPORTANT: attempted_at is always written explicitly via PHP's date(),
// never left to a DB column default. A database's CURRENT_TIMESTAMP is
// evaluated in the database server's own timezone (frequently UTC),
// while the lockout check below compares against a PHP-computed cutoff
// in the app's configured timezone. Relying on the DB default silently
// broke lockout entirely in earlier testing whenever those two
// timezones didn't match — the account tracking would show failed
// attempts, but they'd never count as "recent," so lockout never fired.
// ---------------------------------------------------------------------

function record_login_attempt(string $email, string $ip, bool $successful, ?string $userAgent): void
{
    $stmt = db()->prepare(
        'INSERT INTO login_attempts (email, ip_address, successful, user_agent, attempted_at)
         VALUES (:email, :ip, :successful, :ua, :now)'
    );
    $stmt->execute([
        'email' => mb_strtolower(trim($email)),
        'ip' => $ip,
        'successful' => $successful ? 1 : 0,
        'ua' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
        'now' => date('Y-m-d H:i:s'),
    ]);
}

function recent_failed_login_count(string $email, int $withinMinutes): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS cnt FROM login_attempts
         WHERE email = :email AND successful = 0 AND attempted_at >= :since'
    );
    $since = date('Y-m-d H:i:s', time() - ($withinMinutes * 60));
    $stmt->execute(['email' => mb_strtolower(trim($email)), 'since' => $since]);
    return (int) ($stmt->fetch()['cnt'] ?? 0);
}

function clear_login_attempts(string $email): void
{
    $stmt = db()->prepare('DELETE FROM login_attempts WHERE email = :email');
    $stmt->execute(['email' => mb_strtolower(trim($email))]);
}

/**
 * Returns null if not locked out, or seconds remaining in the lockout
 * window if it is.
 */
function lockout_seconds_remaining(string $email): ?int
{
    $failed = recent_failed_login_count($email, LOGIN_LOCKOUT_MINUTES);
    if ($failed < LOGIN_MAX_ATTEMPTS) {
        return null;
    }
    return LOGIN_LOCKOUT_MINUTES * 60;
}

/**
 * Step 1 of login: verify credentials only. Does NOT establish a
 * session — callers must check two_factor_enabled and route through
 * the OTP step if needed before calling establish_session().
 *
 * @return array{ok:bool, user?:array, error?:string}
 */
function attempt_login_credentials(string $email, string $password, string $ip, ?string $userAgent): array
{
    $lockout = lockout_seconds_remaining($email);
    if ($lockout !== null) {
        return ['ok' => false, 'error' => 'Too many failed attempts. Please try again in ' . (int) ceil($lockout / 60) . ' minute(s).'];
    }

    $user = find_user_by_email($email);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        record_login_attempt($email, $ip, false, $userAgent);
        return ['ok' => false, 'error' => 'Invalid email or password.'];
    }

    if ($user['status'] !== 'active') {
        record_login_attempt($email, $ip, false, $userAgent);
        return ['ok' => false, 'error' => 'Your account is not active. Please contact administration for more info.'];
    }

    record_login_attempt($email, $ip, true, $userAgent);
    clear_login_attempts($email);

    return ['ok' => true, 'user' => $user];
}

// ---------------------------------------------------------------------
// Login history
// ---------------------------------------------------------------------

function record_login_history(int $userId, string $ip, ?string $userAgent): void
{
    $stmt = db()->prepare(
        'INSERT INTO login_history (user_id, ip_address, user_agent, logged_in_at) VALUES (:uid, :ip, :ua, :now)'
    );
    $stmt->execute([
        'uid' => $userId,
        'ip' => $ip,
        'ua' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
        'now' => date('Y-m-d H:i:s'),
    ]);
}

function get_login_history(int $userId, int $limit = 20): array
{
    $stmt = db()->prepare('SELECT * FROM login_history WHERE user_id = :uid ORDER BY logged_in_at DESC LIMIT :lim');
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------
// Audit log
// ---------------------------------------------------------------------

function audit_log(?int $userId, string $module, string $action, ?string $description = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO audit_logs (user_id, module, action, description, ip_address, user_agent, created_at)
         VALUES (:uid, :module, :action, :desc, :ip, :ua, :now)'
    );
    $stmt->execute([
        'uid' => $userId,
        'module' => $module,
        'action' => $action,
        'desc' => $description,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        'now' => date('Y-m-d H:i:s'),
    ]);
}

// ---------------------------------------------------------------------
// Session establishment / teardown
// ---------------------------------------------------------------------

function establish_session(array $user, string $ip, ?string $userAgent): void
{
    session_regenerate_on_login(); // prevent session fixation

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['logged_in_at'] = time();

    touch_last_login((int) $user['id']);
    record_login_history((int) $user['id'], $ip, $userAgent);
    audit_log((int) $user['id'], 'auth', 'login', 'User logged in successfully.');
}

function logout_user(): void
{
    $userId = $_SESSION['user_id'] ?? null;
    if ($userId !== null) {
        audit_log((int) $userId, 'auth', 'logout', 'User logged out.');
        revoke_all_remember_tokens((int) $userId);
    }

    // Clear the remember-me cookie too — otherwise bootstrap.php's
    // silent remember-me auto-login re-establishes the session on the
    // very next request (e.g. the redirect to login.php that follows
    // this function), making logout look like it silently fails and
    // sends the user straight back to the dashboard.
    if (!empty($_COOKIE['remember_me'])) {
        setcookie('remember_me', '', [
            'expires' => time() - 42000,
            'path' => '/',
            'secure' => SESSION_SECURE_COOKIE,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE['remember_me']);
    }

    session_destroy_secure();
}

function current_user(): ?array
{
    static $cached = null;
    static $fetched = false;

    if ($fetched) {
        return $cached;
    }
    $fetched = true;

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $user = find_user_by_id((int) $_SESSION['user_id']);
    $cached = $user !== false ? $user : null;
    return $cached;
}

/**
 * Guards a page so it's only reachable when authenticated. Also enforces
 * a sliding session-lifetime auto-logout. Call at the top of every
 * protected page, after session_boot().
 */
function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        redirect('login.php');
    }

    $lifetimeSeconds = SESSION_LIFETIME_MINUTES * 60;
    if (isset($_SESSION['logged_in_at']) && (time() - $_SESSION['logged_in_at']) > $lifetimeSeconds) {
        session_destroy_secure();
        flash_set('error', 'Your session expired. Please log in again.');
        redirect('login.php');
    }

    // If an admin deactivated/suspended this account after they'd
    // already logged in, force logout on the very next page load
    // instead of waiting for the session to expire naturally.
    $liveUser = find_user_by_id((int) $_SESSION['user_id']);
    if (!$liveUser || $liveUser['status'] !== 'active') {
        session_destroy_secure();
        flash_set('error', 'Your account has been deactivated. Please contact your administrator.');
        redirect('login.php');
    }

    // Sliding expiration: any authenticated page view extends the session.
    $_SESSION['logged_in_at'] = time();
}

/**
 * Guards a page so it's only reachable by specific roles (call after
 * require_login()).
 */
function require_role(string ...$roles): void
{
    if (!in_array($_SESSION['user_role'] ?? '', $roles, true)) {
        http_response_code(403);
        require dirname(__DIR__) . '/errors/403.php';
        exit;
    }
}

/**
 * Redirects already-authenticated users away from guest-only pages
 * (login, register, forgot-password).
 */
function require_guest(): void
{
    if (!empty($_SESSION['user_id'])) {
        redirect('dashboard.php');
    }
}

// ---------------------------------------------------------------------
// OTP (login 2FA)
// ---------------------------------------------------------------------

function issue_login_otp(string $email, ?int $userId): string
{
    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $stmt = db()->prepare(
        'INSERT INTO otp_verifications (user_id, email, otp_hash, purpose, expires_at, created_at)
         VALUES (:uid, :email, :hash, \'login\', :expires, :now)'
    );
    $stmt->execute([
        'uid' => $userId,
        'email' => mb_strtolower(trim($email)),
        'hash' => hash('sha256', $otp),
        'expires' => date('Y-m-d H:i:s', time() + (OTP_EXPIRY_MINUTES * 60)),
        'now' => date('Y-m-d H:i:s'),
    ]);

    // Production would email/SMS this via PHPMailer. Logged here (not
    // shown on-screen) so the flow remains testable end-to-end.
    error_log("[DEV ONLY] OTP for {$email}: {$otp}");

    return $otp;
}

function verify_login_otp(string $email, string $otp): bool
{
    $stmt = db()->prepare(
        "SELECT * FROM otp_verifications
         WHERE email = :email AND purpose = 'login' AND consumed_at IS NULL
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['email' => mb_strtolower(trim($email))]);
    $row = $stmt->fetch();

    if (!$row || strtotime($row['expires_at']) < time() || (int) $row['attempts'] >= 5) {
        return false;
    }

    $isValid = hash_equals($row['otp_hash'], hash('sha256', $otp));

    if ($isValid) {
        db()->prepare('UPDATE otp_verifications SET consumed_at = :now WHERE id = :id')
            ->execute(['now' => date('Y-m-d H:i:s'), 'id' => $row['id']]);
    } else {
        db()->prepare('UPDATE otp_verifications SET attempts = attempts + 1 WHERE id = :id')
            ->execute(['id' => $row['id']]);
    }

    return $isValid;
}

// ---------------------------------------------------------------------
// Remember me (selector/validator pattern — raw token never stored)
// ---------------------------------------------------------------------

function issue_remember_me_cookie(int $userId): void
{
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));

    $stmt = db()->prepare(
        'INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at, created_at)
         VALUES (:uid, :selector, :hash, :expires, :now)'
    );
    $stmt->execute([
        'uid' => $userId,
        'selector' => $selector,
        'hash' => hash('sha256', $validator),
        'expires' => date('Y-m-d H:i:s', time() + (REMEMBER_ME_DAYS * 86400)),
        'now' => date('Y-m-d H:i:s'),
    ]);

    setcookie('remember_me', $selector . ':' . $validator, [
        'expires' => time() + (REMEMBER_ME_DAYS * 86400),
        'path' => '/',
        'secure' => SESSION_SECURE_COOKIE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function attempt_remember_me_login(string $cookieValue): array|false
{
    if (!str_contains($cookieValue, ':')) {
        return false;
    }
    [$selector, $validator] = explode(':', $cookieValue, 2);

    $stmt = db()->prepare('SELECT * FROM remember_tokens WHERE selector = :selector AND expires_at > :now LIMIT 1');
    $stmt->execute(['selector' => $selector, 'now' => date('Y-m-d H:i:s')]);
    $record = $stmt->fetch();

    if (!$record || !hash_equals($record['validator_hash'], hash('sha256', $validator))) {
        return false;
    }

    return find_user_by_id((int) $record['user_id']);
}

function revoke_all_remember_tokens(int $userId): void
{
    db()->prepare('DELETE FROM remember_tokens WHERE user_id = :uid')->execute(['uid' => $userId]);
}

// ---------------------------------------------------------------------
// Forgot / reset password
// ---------------------------------------------------------------------

function request_password_reset(string $email): ?string
{
    $user = find_user_by_email($email);
    if (!$user) {
        // Deliberately return null without hinting whether the account
        // exists, to prevent account enumeration via this endpoint.
        return null;
    }

    $token = bin2hex(random_bytes(32));
    $stmt = db()->prepare(
        'INSERT INTO password_resets (email, token_hash, expires_at, created_at) VALUES (:email, :hash, :expires, :now)'
    );
    $stmt->execute([
        'email' => mb_strtolower(trim($email)),
        'hash' => hash('sha256', $token),
        'expires' => date('Y-m-d H:i:s', time() + 3600),
        'now' => date('Y-m-d H:i:s'),
    ]);

    $resetLink = url('reset-password.php') . '?email=' . urlencode($email) . '&token=' . urlencode($token);
    error_log("[DEV ONLY] Password reset link for {$email}: {$resetLink}");

    return $token;
}

function find_valid_reset_token(string $email, string $token): array|false
{
    $stmt = db()->prepare(
        'SELECT * FROM password_resets WHERE email = :email AND token_hash = :hash AND used_at IS NULL ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['email' => mb_strtolower(trim($email)), 'hash' => hash('sha256', $token)]);
    $row = $stmt->fetch();

    if (!$row || strtotime($row['expires_at']) < time()) {
        return false;
    }
    return $row;
}

function reset_password(string $email, string $token, string $newPassword): bool
{
    $record = find_valid_reset_token($email, $token);
    if (!$record) {
        return false;
    }

    $user = find_user_by_email($email);
    if (!$user) {
        return false;
    }

    update_user_password((int) $user['id'], $newPassword);
    db()->prepare('UPDATE password_resets SET used_at = :now WHERE id = :id')
        ->execute(['now' => date('Y-m-d H:i:s'), 'id' => $record['id']]);
    revoke_all_remember_tokens((int) $user['id']);
    audit_log((int) $user['id'], 'auth', 'password_reset', 'Password was reset via forgot-password flow.');

    return true;
}

function change_password(int $userId, string $currentPassword, string $newPassword): bool
{
    $user = find_user_by_id($userId);
    if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
        return false;
    }
    update_user_password($userId, $newPassword);
    audit_log($userId, 'auth', 'password_change', 'User changed their own password.');
    return true;
}
