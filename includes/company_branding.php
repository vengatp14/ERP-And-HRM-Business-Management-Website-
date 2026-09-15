<?php

declare(strict_types=1);

/**
 * includes/company_branding.php
 * Lets the company's own logo/seal/signature be changed from the
 * website (Admin > Company Branding) instead of replacing files on
 * the server or editing .env. Single-row table (company_branding,
 * id=1). If nothing has been uploaded yet, callers fall back to the
 * bundled placeholder images under assets/images/ — see
 * company_branding_url() below, used by billing/view.php.
 */

const COMPANY_BRANDING_TYPES = ['logo', 'seal', 'signature'];

/** Fetches the single company_branding row, creating it if missing. */
function get_company_branding(): array
{
    $stmt = db()->query('SELECT * FROM company_branding WHERE id = 1 LIMIT 1');
    $row = $stmt->fetch();
    if ($row === false) {
        db()->exec('INSERT IGNORE INTO company_branding (id) VALUES (1)');
        $stmt = db()->query('SELECT * FROM company_branding WHERE id = 1 LIMIT 1');
        $row = $stmt->fetch();
    }
    return $row ?: [];
}

/**
 * Uploads and saves the company's logo/seal/signature, deleting the
 * previous file for that slot if one existed. Returns true on
 * success, or a string error message on failure.
 */
function update_company_branding_file(string $type, array $file): bool|string
{
    if (!in_array($type, COMPANY_BRANDING_TYPES, true)) {
        return 'Invalid branding type.';
    }

    $result = handle_upload($file, 'images');
    if (!$result['ok']) {
        return $result['error'];
    }

    $current = get_company_branding();
    if (!empty($current["{$type}_stored_filename"])) {
        $oldPath = dirname(__DIR__) . '/uploads/images/' . $current["{$type}_stored_filename"];
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $stmt = db()->prepare(
        "UPDATE company_branding SET {$type}_stored_filename = :stored, {$type}_original_filename = :original,
                {$type}_mime = :mime, updated_at = :now WHERE id = 1"
    );
    $stmt->execute([
        'stored' => $result['stored_filename'],
        'original' => $result['original_filename'],
        'mime' => $result['mime'],
        'now' => date('Y-m-d H:i:s'),
    ]);

    return true;
}

/** Removes the company's logo/seal/signature for one slot, if set. */
function remove_company_branding_file(string $type): bool
{
    if (!in_array($type, COMPANY_BRANDING_TYPES, true)) {
        return false;
    }

    $current = get_company_branding();
    if (empty($current["{$type}_stored_filename"])) {
        return false;
    }

    $path = dirname(__DIR__) . '/uploads/images/' . $current["{$type}_stored_filename"];
    if (is_file($path)) {
        @unlink($path);
    }

    $stmt = db()->prepare(
        "UPDATE company_branding SET {$type}_stored_filename = NULL, {$type}_original_filename = NULL,
                {$type}_mime = NULL, updated_at = :now WHERE id = 1"
    );
    return $stmt->execute(['now' => date('Y-m-d H:i:s')]);
}

/**
 * The company name to display — the value set from the website
 * (Admin > Company Branding) if an admin has filled it in, otherwise
 * the COMPANY_NAME constant (from .env, or the "Enterprise CRM ERP"
 * bundled default) so nothing breaks before it's configured.
 */
function company_name(): string
{
    $branding = get_company_branding();
    $name = trim((string) ($branding['company_name'] ?? ''));
    return $name !== '' ? $name : COMPANY_NAME;
}

/** The company address to display — same fallback pattern as company_name(). */
function company_address(): string
{
    $branding = get_company_branding();
    $address = trim((string) ($branding['company_address'] ?? ''));
    return $address !== '' ? $address : COMPANY_ADDRESS;
}

/** The company's GSTIN to display — same fallback pattern as company_name(). */
function company_gstin(): string
{
    $branding = get_company_branding();
    $gstin = trim((string) ($branding['company_gstin'] ?? ''));
    return $gstin !== '' ? $gstin : COMPANY_GSTIN;
}

/** The company's mobile number to display — same fallback pattern as company_name(). */
function company_mobile(): string
{
    $branding = get_company_branding();
    $mobile = trim((string) ($branding['company_mobile'] ?? ''));
    return $mobile !== '' ? $mobile : COMPANY_MOBILE;
}

/** Saves the company name/address/GSTIN/mobile entered on the Company Branding page. */
function update_company_name_address(string $name, string $address, string $gstin = '', string $mobile = ''): bool
{
    $stmt = db()->prepare(
        'UPDATE company_branding SET company_name = :name, company_address = :address,
                company_gstin = :gstin, company_mobile = :mobile, updated_at = :now WHERE id = 1'
    );
    return $stmt->execute([
        'name' => $name !== '' ? $name : null,
        'address' => $address !== '' ? $address : null,
        'gstin' => $gstin !== '' ? $gstin : null,
        'mobile' => $mobile !== '' ? $mobile : null,
        'now' => date('Y-m-d H:i:s'),
    ]);
}

/**
 * The URL to show for one branding slot: the uploaded-from-website
 * image if there is one, otherwise the bundled placeholder file
 * (COMPANY_LOGO_PATH/COMPANY_SEAL_PATH/COMPANY_SIGNATURE_PATH from
 * includes/config.php), otherwise null (renders nothing).
 */
function company_branding_url(string $type): ?string
{
    if (!in_array($type, COMPANY_BRANDING_TYPES, true)) {
        return null;
    }

    $branding = get_company_branding();
    if (!empty($branding["{$type}_stored_filename"])) {
        return url('admin/company-branding-download.php?type=' . $type);
    }

    $fallbackPath = match ($type) {
        'logo' => COMPANY_LOGO_PATH,
        'seal' => COMPANY_SEAL_PATH,
        'signature' => COMPANY_SIGNATURE_PATH,
    };

    if ($fallbackPath && is_file(dirname(__DIR__) . '/assets/' . $fallbackPath)) {
        return asset($fallbackPath);
    }

    return null;
}
