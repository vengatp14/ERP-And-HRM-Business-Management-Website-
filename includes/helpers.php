<?php

declare(strict_types=1);

/**
 * includes/helpers.php
 * General-purpose escaping/sanitization helpers and secure headers.
 * Every dynamic value printed into HTML must go through e().
 */

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Display a value, or a readable placeholder label (e.g. "Not entered",
 * "Not assigned") instead of a bare "—" when the value is empty. Used
 * everywhere a table cell or detail row would otherwise fall back to a
 * dash — a dash reads as broken/missing data, a labeled placeholder
 * reads as "this was never filled in".
 *
 * $muted true (default) wraps the placeholder in a small muted/italic
 * span for screen views; pass false on printed documents (quotations,
 * invoices) where that styling wouldn't print well.
 */
function field_or(?string $value, string $label = 'Not entered', bool $muted = true): string
{
    if ($value === null || trim($value) === '') {
        return $muted
            ? '<span class="text-muted fst-italic">' . e($label) . '</span>'
            : e($label);
    }
    return e($value);
}

function sanitize_string(?string $value): string
{
    return trim(strip_tags($value ?? ''));
}

/**
 * A fresh random nonce per request, used to allow specific inline
 * <script> blocks under a strict CSP (script-src does NOT get
 * 'unsafe-inline' — that would defeat the point of the policy).
 * Call csp_nonce() from a template to get the same value used in the
 * header, and add nonce="<?= e(csp_nonce()) ?>" to that <script> tag.
 */
function csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

function apply_secure_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // geolocation=(self) — same-origin only — so hr/attendance.php can
    // capture the device's real coordinates at punch-in/out (see
    // punch_in()/punch_out() in includes/hr.php). Microphone/camera stay
    // fully denied; nothing in this app uses them.
    header('Permissions-Policy: geolocation=(self), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'nonce-" . csp_nonce() . "' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net");
    // No-store on every authenticated page: without this, hitting the
    // browser Back button after creating/editing/deleting a record (e.g.
    // GST Billing -> New Invoice -> browser Back) can restore the PREVIOUS
    // page straight from the browser's bfcache instead of asking the
    // server again — so a newly created invoice looks "missing" from the
    // list until a manual refresh. no-store forces a fresh server fetch
    // every time so list pages always reflect the latest data.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
    }
}

/**
 * Resolves the app's own base URL path automatically, so links/redirects
 * work whether the project sits at the web root or in an XAMPP subfolder
 * (htdocs/project-name/, visited as http://localhost/project-name/), and
 * regardless of which page (root-level or inside a subfolder like
 * leads/ or admin/) is currently executing.
 *
 * IMPORTANT: this must NOT be derived from the currently-running script's
 * own path (e.g. dirname($_SERVER['SCRIPT_NAME'])) — that only gives the
 * right answer for root-level pages. For a script at leads/form.php,
 * dirname() of its own SCRIPT_NAME returns ".../leads", which would then
 * get double-prepended onto every url('leads/...') call from that page.
 * Confirmed via testing: this exact bug produced redirects like
 * "/leads/leads/view.php" instead of "/leads/view.php".
 *
 * Instead, this compares the project's own filesystem folder (always
 * known via __DIR__, regardless of which script included this file) to
 * DOCUMENT_ROOT — a relationship that's fixed no matter which page runs.
 */
function base_path(): string
{
    static $basePath = null;
    if ($basePath === null) {
        $projectRoot = str_replace('\\', '/', dirname(__DIR__));
        $documentRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

        // realpath() resolves any symlinks/relative segments so both sides
        // refer to the same canonical path before comparing.
        $projectRootReal = realpath($projectRoot);
        $documentRootReal = realpath($documentRoot);
        if ($projectRootReal !== false) {
            $projectRoot = str_replace('\\', '/', $projectRootReal);
        }
        if ($documentRootReal !== false) {
            $documentRoot = rtrim(str_replace('\\', '/', $documentRootReal), '/');
        }

        if ($documentRoot !== '' && str_starts_with($projectRoot, $documentRoot)) {
            // Exact match (correct behavior on case-sensitive filesystems,
            // e.g. Linux production servers).
            $basePath = substr($projectRoot, strlen($documentRoot));
        } elseif ($documentRoot !== '' && stripos($projectRoot, $documentRoot) === 0) {
            // Case-insensitive fallback: Windows/XAMPP filesystems are
            // case-insensitive, so DOCUMENT_ROOT and __DIR__ can report the
            // same folder with different casing (e.g. "C:/xampp/htdocs" vs
            // "c:/xampp/htdocs") even though they're identical on disk. A
            // case-sensitive comparison then wrongly concludes the project
            // isn't under the document root at all, so base_path() returns
            // "" instead of "/prop-crm" — and every url() call in the app
            // (every href, every form action) silently points at the
            // server root instead of the app folder.
            $basePath = substr($projectRoot, strlen($documentRoot));
        } else {
            // Last resort, independent of DOCUMENT_ROOT entirely (covers
            // servers that misreport or omit it): find this project's own
            // folder name in the URL of the script that's actually running.
            // E.g. project folder "prop-crm" + SCRIPT_NAME
            // "/prop-crm/leads/index.php" => base path "/prop-crm".
            $projectFolderName = basename($projectRoot);
            $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
            $marker = '/' . $projectFolderName;
            $pos = strripos($scriptName, $marker . '/');
            if ($pos === false && strtolower($scriptName) === strtolower($marker)) {
                $pos = 0;
            }
            $basePath = $pos !== false ? substr($scriptName, 0, $pos + strlen($marker)) : '';
        }
    }
    return $basePath;
}

/**
 * Builds an app-relative URL, e.g. url('login.php') or url('admin/users.php').
 */
function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path), true, 302);
    exit;
}

function asset(string $path): string
{
    $relative = 'assets/' . ltrim($path, '/');

    // Cache-bust with the file's mtime so browsers/proxies never serve a
    // stale cached app.css/app.js after a deploy — no manual version bump
    // needed, it just changes whenever the file on disk changes.
    $fullPath = __DIR__ . '/../' . $relative;
    $version = is_file($fullPath) ? (string) filemtime($fullPath) : '1';

    return url($relative) . '?v=' . $version;
}

/**
 * Keeps only digits and a leading + from a stored phone number, so it's
 * safe to drop straight into a tel: or wa.me link regardless of how the
 * number was typed in (spaces, dashes, brackets, etc).
 */
function phone_digits(?string $number): string
{
    if ($number === null || $number === '') {
        return '';
    }
    return preg_replace('/[^0-9+]/', '', $number) ?? '';
}

/**
 * Builds a wa.me link from a stored phone number. wa.me expects the
 * number with country code and no leading +, so that's stripped too.
 * Assumes numbers are already stored with country code (e.g. 91XXXXXXXXXX);
 * falls back to the raw digits if that's not the case.
 */
function whatsapp_link(?string $number): string
{
    $digits = ltrim(phone_digits($number), '+');
    return 'https://wa.me/' . $digits;
}

function tel_link(?string $number): string
{
    return 'tel:' . phone_digits($number);
}

/**
 * Renders a compact read-only 5-star display (filled vs empty Bootstrap
 * Icons stars) for a rating value. Pass null/0 for "not rated yet".
 */
function star_display(?int $rating, string $emptyLabel = 'Not rated'): string
{
    if ($rating === null || $rating < 1) {
        return '<span class="text-muted small">' . e($emptyLabel) . '</span>';
    }
    $rating = max(1, min(5, $rating));
    $html = '<span class="text-warning" title="' . $rating . ' of 5 stars">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= '<i class="bi ' . ($i <= $rating ? 'bi-star-fill' : 'bi-star') . '"></i>';
    }
    return $html . '</span>';
}
