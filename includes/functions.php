<?php

declare(strict_types=1);

/**
 * includes/functions.php
 * General-purpose utility functions used across the app.
 */

/** '1st', '2nd', '3rd', '4th'... — used to display recurring expense day-of-month. */
function ordinal_suffix(int $day): string
{
    if ($day % 100 >= 11 && $day % 100 <= 13) {
        return 'th';
    }
    return match ($day % 10) {
        1 => 'st',
        2 => 'nd',
        3 => 'rd',
        default => 'th',
    };
}

function generate_uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ---------------------------------------------------------------------
// Validation helpers. Each returns an error string, or null if valid.
// ---------------------------------------------------------------------

function validate_required(mixed $value, string $label): ?string
{
    if ($value === null || (is_string($value) && trim($value) === '')) {
        return "{$label} is required.";
    }
    return null;
}

function validate_email(string $value): ?string
{
    if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }
    return null;
}

function validate_strong_password(string $value): ?string
{
    if ($value === '') {
        return null;
    }
    $isStrong = strlen($value) >= 8
        && preg_match('/[A-Z]/', $value)
        && preg_match('/[a-z]/', $value)
        && preg_match('/[0-9]/', $value)
        && preg_match('/[^A-Za-z0-9]/', $value);

    return $isStrong ? null : 'Password must be at least 8 characters and include uppercase, lowercase, a number, and a symbol.';
}

function validate_matches(mixed $a, mixed $b, string $message = 'Fields do not match.'): ?string
{
    return $a === $b ? null : $message;
}

// ---------------------------------------------------------------------
// Rate limiting — generic, file-based fixed-window counter. Used for
// endpoints beyond the dedicated login-attempt limiter in auth.php.
// ---------------------------------------------------------------------

function rate_limit_check(string $bucket, int $maxRequests, int $perSeconds): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $window = (int) floor(time() / $perSeconds);
    $key = preg_replace('/[^a-zA-Z0-9_]/', '_', "{$bucket}_{$ip}_{$window}");

    $cacheDir = dirname(__DIR__) . '/cache';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    $file = $cacheDir . '/ratelimit_' . $key . '.txt';

    $count = is_file($file) ? (int) file_get_contents($file) : 0;

    if ($count >= $maxRequests) {
        http_response_code(429);
        header('Retry-After: ' . $perSeconds);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>Too Many Requests</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
            . '<body class="bg-light"><div class="d-flex align-items-center justify-content-center min-vh-100 p-3">'
            . '<div class="text-center" style="max-width:480px;"><div class="display-1 fw-bold text-primary mb-2">429</div>'
            . '<h1 class="h4 mb-3">Too Many Requests</h1><p class="text-muted mb-4">You\'ve made too many requests in a short time. Please wait a moment and try again.</p>'
            . '<a href="' . e(url('login.php')) . '" class="btn btn-primary px-4">Go Back</a></div></div></body></html>';
        exit;
    }

    file_put_contents($file, (string) ($count + 1), LOCK_EX);
}

// ---------------------------------------------------------------------
// Pagination helper — reusable across any list page (users, leads, etc.)
// ---------------------------------------------------------------------

/**
 * @return array{page:int, perPage:int, offset:int, totalPages:int}
 */
function paginate(int $totalRows, int $perPage = 20): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    return ['page' => $page, 'perPage' => $perPage, 'offset' => $offset, 'totalPages' => $totalPages];
}

/**
 * Renders Bootstrap-styled pagination links, preserving existing query
 * string parameters (e.g. search/filter) other than 'page'.
 */
function render_pagination(int $currentPage, int $totalPages): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $queryWithoutPage = $_GET;
    unset($queryWithoutPage['page']);
    $baseQuery = http_build_query($queryWithoutPage);
    $baseQuery = $baseQuery !== '' ? $baseQuery . '&' : '';

    $html = '<nav aria-label="Page navigation"><ul class="pagination justify-content-center">';

    $prevDisabled = $currentPage <= 1 ? ' disabled' : '';
    $prevPage = max(1, $currentPage - 1);
    $html .= "<li class=\"page-item{$prevDisabled}\"><a class=\"page-link\" href=\"?{$baseQuery}page={$prevPage}\">Previous</a></li>";

    for ($i = 1; $i <= $totalPages; $i++) {
        $active = $i === $currentPage ? ' active' : '';
        $html .= "<li class=\"page-item{$active}\"><a class=\"page-link\" href=\"?{$baseQuery}page={$i}\">{$i}</a></li>";
    }

    $nextDisabled = $currentPage >= $totalPages ? ' disabled' : '';
    $nextPage = min($totalPages, $currentPage + 1);
    $html .= "<li class=\"page-item{$nextDisabled}\"><a class=\"page-link\" href=\"?{$baseQuery}page={$nextPage}\">Next</a></li>";

    $html .= '</ul></nav>';
    return $html;
}
