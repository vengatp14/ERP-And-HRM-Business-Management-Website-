<?php

declare(strict_types=1);

/**
 * api/session-check.php
 * Lightweight JSON endpoint for front-end AJAX polling to detect an
 * expired session without a full page reload — e.g. a JS interval that
 * warns the user before they lose unsaved work in a long form.
 *
 * GET /api/session-check.php
 * -> {"authenticated": true, "user": {...}} or {"authenticated": false}
 */

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['authenticated' => false]);
    exit;
}

$lifetimeSeconds = SESSION_LIFETIME_MINUTES * 60;
$expired = isset($_SESSION['logged_in_at']) && (time() - $_SESSION['logged_in_at']) > $lifetimeSeconds;

if ($expired) {
    session_destroy_secure();
    http_response_code(401);
    echo json_encode(['authenticated' => false, 'reason' => 'expired']);
    exit;
}

echo json_encode([
    'authenticated' => true,
    'user' => [
        'name' => $_SESSION['user_name'] ?? null,
        'role' => $_SESSION['user_role'] ?? null,
    ],
    'seconds_remaining' => $lifetimeSeconds - (time() - $_SESSION['logged_in_at']),
]);
