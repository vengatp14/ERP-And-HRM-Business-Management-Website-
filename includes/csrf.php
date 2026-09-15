<?php

declare(strict_types=1);

/**
 * includes/csrf.php
 * CSRF protection. Every state-changing form must include csrf_field(),
 * and every POST handler must call csrf_verify_or_die() before acting.
 */

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token): bool
{
    if (empty($_SESSION['_csrf_token']) || $token === null) {
        return false;
    }
    return hash_equals($_SESSION['_csrf_token'], $token);
}

/**
 * Verifies the CSRF token from $_POST and halts with a 403 error page if
 * invalid. Call at the top of every script that handles a POST request.
 *
 * Note: 419 ("token expired", popularized by some frameworks) is not an
 * IANA-registered HTTP status, and some server stacks (Apache/mod_php
 * included) silently rewrite it to 500 — confirmed during testing. 403
 * is the correct, universally-supported status for a rejected token.
 */
function csrf_verify_or_die(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // PHP silently empties $_POST and $_FILES (with no catchable
        // error) when a request body exceeds post_max_size — including
        // the CSRF token itself. Detected via CONTENT_LENGTH vs the
        // configured limit, so this shows a clear message instead of a
        // confusing "session expired" CSRF failure. Confirmed via
        // testing: uploading a file between PHP's default limits and
        // this app's intended 10MB limit reproduced exactly this.
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $postMaxBytes = return_bytes(ini_get('post_max_size'));
        if ($postMaxBytes > 0 && $contentLength > $postMaxBytes && empty($_POST)) {
            http_response_code(413);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
                . '<title>File Too Large</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
                . '<body class="bg-light"><div class="d-flex align-items-center justify-content-center min-vh-100 p-3">'
                . '<div class="text-center" style="max-width:480px;"><div class="display-1 fw-bold text-primary mb-2">413</div>'
                . '<h1 class="h4 mb-3">File Too Large</h1><p class="text-muted mb-4">The upload exceeds the server\'s configured size limit. Please choose a smaller file.</p>'
                . '<a href="javascript:history.back()" class="btn btn-primary px-4">Go Back</a></div></div></body></html>';
            exit;
        }

        if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
            http_response_code(403);
            require dirname(__DIR__) . '/errors/403.php';
            exit;
        }
    }
}

/**
 * Parses a php.ini-style size string (e.g. "8M", "512K", "1G") into bytes.
 */
function return_bytes(string $iniValue): int
{
    $iniValue = trim($iniValue);
    if ($iniValue === '') {
        return 0;
    }
    $unit = strtolower(substr($iniValue, -1));
    $value = (int) $iniValue;
    return match ($unit) {
        'g' => $value * 1024 * 1024 * 1024,
        'm' => $value * 1024 * 1024,
        'k' => $value * 1024,
        default => $value,
    };
}
