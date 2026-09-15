<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('leads');

$documentId = (int) ($_GET['id'] ?? 0);
$document = find_lead_document($documentId);

if ($document === false) {
    http_response_code(404);
    require dirname(__DIR__) . '/errors/404.php';
    exit;
}

// Determine the category subfolder the same way handle_upload() chose it,
// by checking the stored extension against the known allow-lists.
$extension = strtolower(pathinfo($document['stored_filename'], PATHINFO_EXTENSION));
$category = in_array($extension, UPLOAD_ALLOWED_TYPES['images']['extensions'], true) ? 'images' : 'documents';

$filePath = dirname(__DIR__) . '/uploads/' . $category . '/' . $document['stored_filename'];

if (!is_file($filePath)) {
    http_response_code(404);
    require dirname(__DIR__) . '/errors/404.php';
    exit;
}

audit_log((int) current_user()['id'], 'leads', 'document_download', "Downloaded document #{$documentId}: {$document['original_filename']}");

header('Content-Type: ' . $document['mime_type']);
header('Content-Disposition: inline; filename="' . basename($document['original_filename']) . '"');
header('Content-Length: ' . filesize($filePath));
header('X-Content-Type-Options: nosniff');
readfile($filePath);
