<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('projects');

$documentId = (int) ($_GET['id'] ?? 0);
$document = find_project_completion_document($documentId);

if ($document === false) {
    http_response_code(404);
    require dirname(__DIR__) . '/errors/404.php';
    exit;
}

// Only admins, or someone assigned to the project the document belongs
// to, may download it — same rule as opening the project itself
// (projects/view.php).
$currentUserId = (int) current_user()['id'];
if (!is_admin_role(current_user()) && !user_is_assigned_to_project((int) $document['project_id'], $currentUserId)) {
    http_response_code(403);
    require dirname(__DIR__) . '/errors/403.php';
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

audit_log($currentUserId, 'projects', 'completion_document_download', "Downloaded completion document #{$documentId}: {$document['original_filename']}");

header('Content-Type: ' . $document['mime_type']);
header('Content-Disposition: inline; filename="' . basename($document['original_filename']) . '"');
header('Content-Length: ' . filesize($filePath));
header('X-Content-Type-Options: nosniff');
readfile($filePath);
