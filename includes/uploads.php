<?php

declare(strict_types=1);

/**
 * includes/uploads.php
 * Secure file upload handling, reusable across modules (Leads, Projects,
 * Invoices, profile photos, ...). Validates both the file extension and
 * the actual MIME type (via finfo, not the client-supplied Content-Type,
 * which can't be trusted), enforces a size limit, and always writes with
 * a randomly generated filename so an attacker can't control the stored
 * path or overwrite another file.
 */

const UPLOAD_MAX_BYTES = 10 * 1024 * 1024; // 10 MB

const UPLOAD_ALLOWED_TYPES = [
    'documents' => [
        'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx'],
        'mimes' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
    ],
    'images' => [
        'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],
];

/**
 * Validates and stores an uploaded file (from a single $_FILES[...] entry).
 * Returns ['ok'=>true, 'stored_filename'=>.., 'original_filename'=>..,
 * 'size'=>.., 'mime'=>..] on success, or ['ok'=>false, 'error'=>..] on
 * failure. Never trusts the client-supplied filename or MIME type.
 *
 * @param array  $file     A single entry from $_FILES (already isset-checked by caller)
 * @param string $category 'documents' or 'images' — determines the allow-list and target subfolder
 */
function handle_upload(array $file, string $category): array
{
    if (!isset(UPLOAD_ALLOWED_TYPES[$category])) {
        return ['ok' => false, 'error' => 'Invalid upload category.'];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file was uploaded.'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the server\'s configured upload size limit.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Server storage error. Please contact an administrator.',
            default => 'Upload failed (error code ' . $file['error'] . ').',
        };
        return ['ok' => false, 'error' => $message];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        // Defends against a request that fakes the $_FILES superglobal
        // structure to point at an arbitrary local file.
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }

    if ($file['size'] > UPLOAD_MAX_BYTES) {
        $maxMb = (int) (UPLOAD_MAX_BYTES / 1024 / 1024);
        return ['ok' => false, 'error' => "File exceeds the {$maxMb}MB size limit."];
    }

    $originalName = $file['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = UPLOAD_ALLOWED_TYPES[$category];

    if (!in_array($extension, $allowed['extensions'], true)) {
        return ['ok' => false, 'error' => 'File type not allowed. Accepted: ' . implode(', ', $allowed['extensions'])];
    }

    // Verify the actual file content, not the client-supplied Content-Type
    // header (which is trivially spoofable) — this is what actually
    // prevents a renamed .php-as-.pdf upload from being accepted.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($detectedMime, $allowed['mimes'], true)) {
        return ['ok' => false, 'error' => 'File content does not match an allowed type.'];
    }

    $storedFilename = bin2hex(random_bytes(16)) . '.' . $extension;
    $targetDir = dirname(__DIR__) . '/uploads/' . $category;
    $targetPath = $targetDir . '/' . $storedFilename;

    // uploads/images, uploads/documents, uploads/temp start out empty in
    // the repo — empty directories aren't tracked by git and are commonly
    // dropped by zip/deploy tooling, so a fresh deployment can be missing
    // them entirely. Create it on demand rather than hard-failing.
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0775, true);
    }

    // A zip/FTP deploy also commonly leaves these folders owned by the
    // deploying user (not the web server user), so they exist but the
    // server can't write into them — is_dir() above passes while
    // is_writable() below still fails. Try a best-effort self-heal
    // before giving up, since that's a one-line permissions fix rather
    // than a real capacity problem.
    if (is_dir($targetDir) && !is_writable($targetDir)) {
        @chmod($targetDir, 0775);
    }

    if (!is_dir($targetDir) || !is_writable($targetDir)) {
        error_log("[uploads.php] Target directory not writable: {$targetDir}");
        return ['ok' => false, 'error' => 'Server storage error. Please contact an administrator.'];
    }

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['ok' => false, 'error' => 'Failed to save the uploaded file.'];
    }

    return [
        'ok' => true,
        'stored_filename' => $storedFilename,
        'original_filename' => sanitize_string(basename($originalName)),
        'size' => (int) $file['size'],
        'mime' => $detectedMime,
    ];
}

function human_file_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / (1024 * 1024), 1) . ' MB';
}
