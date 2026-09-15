<?php

declare(strict_types=1);

/**
 * includes/config.php
 * Loads environment configuration and defines constants used throughout
 * the project. Include this first, before any other includes/ file.
 */

require_once __DIR__ . '/env.php';
env_load(dirname(__DIR__) . '/.env');

// ---------------------------------------------------------------------
// App
// ---------------------------------------------------------------------
define('APP_NAME', env_get('APP_NAME', 'Enterprise CRM ERP'));
define('APP_ENV', env_get('APP_ENV', 'production'));
define('APP_DEBUG', (bool) env_get('APP_DEBUG', false));
define('APP_TIMEZONE', env_get('APP_TIMEZONE', 'Asia/Kolkata'));

date_default_timezone_set(APP_TIMEZONE);

// ---------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------
define('DB_DRIVER', env_get('DB_DRIVER', 'mysql')); // 'mysql' or 'sqlite' (sqlite for local/dev/testing only)
define('DB_HOST', env_get('DB_HOST', '127.0.0.1'));
define('DB_PORT', env_get('DB_PORT', '3306'));
define('DB_NAME', env_get('DB_DATABASE', 'crmerp'));
define('DB_USER', env_get('DB_USERNAME', 'crmerp'));
define('DB_PASS', env_get('DB_PASSWORD', ''));
define('DB_CHARSET', 'utf8mb4');
define('DB_SQLITE_PATH', env_get('DB_SQLITE_PATH', dirname(__DIR__) . '/database/dev.sqlite'));

// ---------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------
define('SESSION_LIFETIME_MINUTES', (int) env_get('SESSION_LIFETIME', 120));
define('SESSION_SECURE_COOKIE', (bool) env_get('SESSION_SECURE_COOKIE', false));
define('SESSION_COOKIE_NAME', 'crm_erp_session');

// ---------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------
define('LOGIN_MAX_ATTEMPTS', (int) env_get('LOGIN_MAX_ATTEMPTS', 5));
define('LOGIN_LOCKOUT_MINUTES', (int) env_get('LOGIN_LOCKOUT_MINUTES', 15));
define('OTP_EXPIRY_MINUTES', (int) env_get('OTP_EXPIRY_MINUTES', 10));
define('REMEMBER_ME_DAYS', 30);

// ---------------------------------------------------------------------
// Company (used by GST Billing for invoice headers and CGST/SGST vs.
// IGST determination — intra-state sales use split CGST+SGST, inter-
// state sales use IGST; see includes/billing.php)
// ---------------------------------------------------------------------
define('COMPANY_NAME', env_get('COMPANY_NAME', APP_NAME));
define('COMPANY_GSTIN', env_get('COMPANY_GSTIN', ''));
define('COMPANY_STATE', env_get('COMPANY_STATE', ''));
define('COMPANY_ADDRESS', env_get('COMPANY_ADDRESS', ''));
define('COMPANY_MOBILE', env_get('COMPANY_MOBILE', ''));
define('INVOICE_NUMBER_PREFIX', env_get('INVOICE_NUMBER_PREFIX', 'INV'));

// Prefix for the Monthly Payments / project income ledger's own receipt
// numbering (see includes/project_payments.php: next_project_income_number()).
// Kept separate from INVOICE_NUMBER_PREFIX so these informal collection
// receipts are never mistaken for a formal GST invoice number.
define('PROJECT_INCOME_NUMBER_PREFIX', env_get('PROJECT_INCOME_NUMBER_PREFIX', 'PAY'));

// Invoice branding — logo/seal/signature shown on every GST bill.
// Paths are relative to /assets (resolved via asset()). Ships pointing at
// the bundled placeholder images so bills are print-ready immediately;
// swap in the real files (or point these at new filenames) before going
// live — no code change needed, just update .env or replace the images
// at assets/images/company-*-default.png.
define('COMPANY_LOGO_PATH', env_get('COMPANY_LOGO_PATH', 'images/company-logo-default.png'));
define('COMPANY_SEAL_PATH', env_get('COMPANY_SEAL_PATH', 'images/company-seal-default.png'));
define('COMPANY_SIGNATURE_PATH', env_get('COMPANY_SIGNATURE_PATH', 'images/company-signature-default.png'));

// ---------------------------------------------------------------------
// Mail (used by future notification features; PHPMailer wiring point)
// ---------------------------------------------------------------------
define('MAIL_HOST', env_get('MAIL_HOST', ''));
define('MAIL_PORT', (int) env_get('MAIL_PORT', 587));
define('MAIL_USERNAME', env_get('MAIL_USERNAME', ''));
define('MAIL_PASSWORD', env_get('MAIL_PASSWORD', ''));
define('MAIL_ENCRYPTION', env_get('MAIL_ENCRYPTION', 'tls'));
define('MAIL_FROM_ADDRESS', env_get('MAIL_FROM_ADDRESS', 'noreply@example.com'));
define('MAIL_FROM_NAME', env_get('MAIL_FROM_NAME', APP_NAME));

// ---------------------------------------------------------------------
// Error reporting / logging
// ---------------------------------------------------------------------
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
}
ini_set('log_errors', '1');
$logsDir = dirname(__DIR__) . '/logs';
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0775, true);
}
ini_set('error_log', $logsDir . '/php-error.log');
