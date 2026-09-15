<?php

declare(strict_types=1);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
/**
 * includes/database.php
 * Provides db(): a shared PDO connection, safe by default (real prepared
 * statements, exceptions on error). Supports MySQL (production/XAMPP) and
 * SQLite (local dev/testing convenience only).
 *
 * Usage: $stmt = db()->prepare("SELECT * FROM users WHERE email = :email");
 */

function db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    try {
        if (DB_DRIVER === 'sqlite') {
            $pdo = new PDO('sqlite:' . DB_SQLITE_PATH);
            $pdo->exec('PRAGMA foreign_keys = ON;');
        } else {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
            // Explicitly pin the connection collation to match the tables'
            // collation (utf8mb4_unicode_ci). Without this, MySQL falls back
            // to its own default collation for the charset (often
            // utf8mb4_general_ci), which then clashes with column collations
            // in comparisons/DATE_FORMAT() etc. under native prepared
            // statements ("Illegal mix of collations").
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Real prepared statements only — required for SQL injection safety.
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    } catch (PDOException $e) {
        error_log('[database.php] Connection failed: ' . $e->getMessage());
        die($e->getMessage());
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
            fwrite(STDERR, "Check your .env database settings.\n");
            exit(1);
        }

        // Never expose the real DB error to a web visitor.
        http_response_code(500);
        require dirname(__DIR__) . '/errors/500.php';
        exit;
    }

    return $pdo;
}
