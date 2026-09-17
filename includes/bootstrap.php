<?php

declare(strict_types=1);

/**
 * includes/bootstrap.php
 * Single entry point that loads every foundational include in the
 * correct order and boots the session. Every top-level page starts with:
 *
 *   require_once __DIR__ . '/includes/bootstrap.php';
 *
 * instead of repeating individual requires (and risking the wrong order).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/calendar.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/clients.php';
require_once __DIR__ . '/company_branding.php';
require_once __DIR__ . '/employees.php';
require_once __DIR__ . '/projects.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/quotations.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/project_payments.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/hr.php';
require_once __DIR__ . '/certificates.php';

apply_secure_headers();
session_boot();

// Remember-me auto-login: if there's no active session but a valid
// remember-me cookie is present, silently re-establish the session.
if (empty($_SESSION['user_id']) && !empty($_COOKIE['remember_me'])) {
    $rememberedUser = attempt_remember_me_login($_COOKIE['remember_me']);
    if ($rememberedUser !== false && $rememberedUser['status'] === 'active') {
        establish_session($rememberedUser, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', $_SERVER['HTTP_USER_AGENT'] ?? null);
    }
}
