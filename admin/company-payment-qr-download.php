<?php

declare(strict_types=1);

/**
 * admin/company-payment-qr-download.php
 * Streams a configured payment QR code inline (for <img src> use on
 * the GST invoice's Payment Details section and the Company Branding
 * admin page). Same pattern as admin/company-branding-download.php —
 * uploads/ itself denies direct access (see uploads/.htaccess), so
 * every file goes through an authenticated script like this one.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

// Same visibility rule as the company logo/seal/signature: anyone who
// can view billing (to see it on an invoice) or is a super_admin (to
// manage it) may load the image.
$user = current_user();
if (!user_can($user, 'billing', 'view') && ($user['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    require dirname(__DIR__) . '/errors/403.php';
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$qr = $id > 0 ? find_company_payment_qr($id) : false;

if ($qr === false) {
    http_response_code(404);
    require dirname(__DIR__) . '/errors/404.php';
    exit;
}

$filePath = dirname(__DIR__) . '/uploads/images/' . $qr['qr_stored_filename'];

if (!is_file($filePath)) {
    http_response_code(404);
    require dirname(__DIR__) . '/errors/404.php';
    exit;
}

header('Content-Type: ' . $qr['qr_mime']);
header('Content-Disposition: inline; filename="' . basename($qr['qr_original_filename'] ?: 'qr') . '"');
header('Content-Length: ' . filesize($filePath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($filePath);
