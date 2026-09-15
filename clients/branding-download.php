<?php

declare(strict_types=1);

/**
 * clients/branding-download.php
 * Streams a client's logo/seal/signature image inline (for <img src>
 * use on the GST invoice print view and the client form preview).
 * Matches the pattern in leads/document-download.php: uploads/ itself
 * denies direct access (see uploads/.htaccess), so every file — even
 * ones only ever meant to be viewed, not downloaded — goes through an
 * authenticated script like this one.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

// Used both on the client edit form (needs 'clients' view) and inline
// on the GST invoice print view (needs 'billing' view) — a user with
// either is allowed to load the image.
$user = current_user();
if (!user_can($user, 'clients', 'view') && !user_can($user, 'billing', 'view')) {
    http_response_code(403);
    require dirname(__DIR__) . '/errors/403.php';
    exit;
}

$clientId = (int) ($_GET['id'] ?? 0);
$type = (string) ($_GET['type'] ?? '');

if (!in_array($type, CLIENT_BRANDING_TYPES, true)) {
    http_response_code(404);
    require dirname(__DIR__) . '/errors/404.php';
    exit;
}

$client = find_client($clientId);

if ($client === false || empty($client["{$type}_stored_filename"])) {
    http_response_code(404);
    require dirname(__DIR__) . '/errors/404.php';
    exit;
}

$filePath = dirname(__DIR__) . '/uploads/images/' . $client["{$type}_stored_filename"];

if (!is_file($filePath)) {
    http_response_code(404);
    require dirname(__DIR__) . '/errors/404.php';
    exit;
}

header('Content-Type: ' . $client["{$type}_mime"]);
header('Content-Disposition: inline; filename="' . basename($client["{$type}_original_filename"] ?? $type) . '"');
header('Content-Length: ' . filesize($filePath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($filePath);
