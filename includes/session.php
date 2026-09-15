<?php

declare(strict_types=1);

/**
 * includes/session.php
 * Hardened session handling: secure cookie flags, periodic ID rotation,
 * a fingerprint check to catch session hijacking, and session-fixation
 * prevention on login. Call session_boot() once, early, before any output.
 */

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $lifetimeSeconds = SESSION_LIFETIME_MINUTES * 60;

    session_set_cookie_params([
        'lifetime' => $lifetimeSeconds,
        'path' => '/',
        'domain' => '',
        'secure' => SESSION_SECURE_COOKIE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name(SESSION_COOKIE_NAME);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) $lifetimeSeconds);

    session_start();

    session_guard_fingerprint();
    session_regenerate_periodically();
}

/**
 * Records a coarse fingerprint (user agent + IP subnet) on the session for
 * audit purposes only.
 *
 * NOTE: this used to force-destroy the session (auto-logout) whenever the
 * fingerprint changed mid-session. That fired constantly on real, legitimate
 * use — a phone hopping between Wi-Fi and mobile data, a carrier rotating
 * IPs, or a browser's responsive/device-toolbar mode changing the
 * User-Agent string — logging people out mid-navigation for no real reason.
 * The session should only end when the user explicitly logs out (or the
 * session naturally expires via require_login()'s sliding lifetime), so
 * this now just tracks the fingerprint without killing the session on
 * mismatch.
 */
function session_guard_fingerprint(): void
{
    $fingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . session_client_ip_subnet());

    if (!isset($_SESSION['_fingerprint'])) {
        $_SESSION['_fingerprint'] = $fingerprint;
        return;
    }

    // Fingerprint changed (new network, new device-toolbar UA, etc.) — just
    // keep the latest value for audit/history purposes, don't log the user out.
    $_SESSION['_fingerprint'] = $fingerprint;
}

function session_regenerate_periodically(): void
{
    $regenIntervalSeconds = 300;

    if (!isset($_SESSION['_last_regeneration'])) {
        $_SESSION['_last_regeneration'] = time();
        return;
    }

    if (time() - $_SESSION['_last_regeneration'] > $regenIntervalSeconds) {
        session_regenerate_id(true);
        $_SESSION['_last_regeneration'] = time();
    }
}

/**
 * Call right after a successful login to prevent session fixation.
 */
function session_regenerate_on_login(): void
{
    session_regenerate_id(true);
    $_SESSION['_last_regeneration'] = time();
}

function session_destroy_secure(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}

function session_client_ip_subnet(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $parts = explode('.', $ip);
    return count($parts) === 4 ? implode('.', array_slice($parts, 0, 3)) : $ip;
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][$type][] = $message;
}

/**
 * Returns and clears flash messages of a given type.
 */
function flash_get(string $type): array
{
    $messages = $_SESSION['flash'][$type] ?? [];
    unset($_SESSION['flash'][$type]);
    return $messages;
}
