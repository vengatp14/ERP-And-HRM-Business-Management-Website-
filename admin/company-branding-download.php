<?php

declare(strict_types=1);

/**
 * admin/company-branding-download.php
 * Streams the company's uploaded logo/seal/signature inline (for
 * <img src> use on the GST invoice print view and the Company
 * Branding admin page). Same pattern as clients/branding-download.php
 * and leads/document-download.php: uploads/ itself denies direct
 * access (see uploads/.htaccess), so every file goes through an
 * authenticated script like this one.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

// Anyone who can view billing (to see it on an invoice) or is a
// super_admin (to manage it) may load the image.
$user = current_user();
if (!user_can($user, 'billing', 'view') && ($user['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    require dirname(__DIR__) . '/errors/403.php';
    exit;
}

$type = (string) ($_GET['type'] ?? '');

if (!in_array($type, COMPANY_BRANDING_TYPES, true)) {
    http_response_code(404);
    require dirname(__DIR__) . '/errors/404.php';
    exit;
}

$branding = get_company_branding();

if (empty($branding["{$type}_stored_filename"])) {
    http_response_code(404);
    require dirname(__DIR__) . '/errors/404.php';
    exit;
}

$filePath = dirname(__DIR__) . '/uploads/images/' . $branding["{$type}_stored_filename"];

if (!is_file($filePath)) {
    http_response_code(404);
    require dirname(__DIR__) . '/errors/404.php';
    exit;
}

header('Content-Type: ' . $branding["{$type}_mime"]);
header('Content-Disposition: inline; filename="' . basename($branding["{$type}_original_filename"] ?? $type) . '"');
header('Content-Length: ' . filesize($filePath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($filePath);
