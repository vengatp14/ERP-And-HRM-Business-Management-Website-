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
 * Social media platforms the Company Branding page (and printed
 * bills) know how to show. Order here is the display order
 * everywhere. Each is stored as {name, url} in the company_branding
 * "social_links" JSON column — see company_social_links() below.
 */
const COMPANY_SOCIAL_PLATFORMS = [
    'instagram' => ['label' => 'Instagram', 'icon' => 'bi-instagram', 'placeholder' => 'https://instagram.com/yourcompany'],
    'facebook' => ['label' => 'Facebook', 'icon' => 'bi-facebook', 'placeholder' => 'https://facebook.com/yourcompany'],
    'x' => ['label' => 'X / Twitter', 'icon' => 'bi-twitter-x', 'placeholder' => 'https://x.com/yourcompany'],
    'youtube' => ['label' => 'YouTube', 'icon' => 'bi-youtube', 'placeholder' => 'https://youtube.com/@yourcompany'],
    'linkedin' => ['label' => 'LinkedIn', 'icon' => 'bi-linkedin', 'placeholder' => 'https://linkedin.com/company/yourcompany'],
];

/**
 * Configured social profiles, keyed by platform — only platforms that
 * actually have both a profile name and a URL are included, so
 * callers (Company Branding form, printed bills) never need to
 * re-check for emptiness. See update_company_social_links() to save.
 */
function company_social_links(): array
{
    $branding = get_company_branding();
    $raw = json_decode((string) ($branding['social_links'] ?? ''), true);
    if (!is_array($raw)) {
        return [];
    }

    $links = [];
    foreach (COMPANY_SOCIAL_PLATFORMS as $key => $meta) {
        $name = trim((string) ($raw[$key]['name'] ?? ''));
        $url = trim((string) ($raw[$key]['url'] ?? ''));
        if ($name !== '' && $url !== '') {
            $links[$key] = ['name' => $name, 'url' => $url];
        }
    }

    return $links;
}

/** Saves the social profile name/URL pairs entered on the Company Branding page. Blank pairs are simply not stored (not "configured"). */
function update_company_social_links(array $links): bool
{
    $clean = [];
    foreach (COMPANY_SOCIAL_PLATFORMS as $key => $meta) {
        $name = trim((string) ($links[$key]['name'] ?? ''));
        $url = trim((string) ($links[$key]['url'] ?? ''));
        if ($name !== '' && $url !== '') {
            $clean[$key] = ['name' => $name, 'url' => $url];
        }
    }

    $stmt = db()->prepare('UPDATE company_branding SET social_links = :links, updated_at = :now WHERE id = 1');
    return $stmt->execute([
        'links' => $clean === [] ? null : json_encode($clean, JSON_THROW_ON_ERROR),
        'now' => date('Y-m-d H:i:s'),
    ]);
}

// ---------------------------------------------------------------------
// Payment Details (bank account + QR codes) — shown on every generated
// final bill (see billing/view.php). Bank fields live on the same
// singleton company_branding row as the rest of the company details;
// QR codes live in their own table since there can be any number of
// them (see database/migration_038_company_bank_details.sql and
// migration_039_company_payment_qrs.sql).
// ---------------------------------------------------------------------

/**
 * The company's configured bank details, keyed by field — only
 * returns fields that are actually filled in, so callers (the bill's
 * Payment Details section) never need to check for emptiness
 * individually. Returns [] if nothing has been configured at all,
 * which callers use to skip the "Bank Details" block entirely rather
 * than printing empty rows.
 */
function company_bank_details(): array
{
    $branding = get_company_branding();
    $fields = [
        'account_name' => 'Account Name',
        'bank_name' => 'Bank Name',
        'account_number' => 'Account Number',
        'ifsc' => 'IFSC',
        'branch' => 'Branch',
        'other_details' => 'Other Details',
    ];
    $columnMap = [
        'account_name' => 'bank_account_name',
        'bank_name' => 'bank_name',
        'account_number' => 'bank_account_number',
        'ifsc' => 'bank_ifsc',
        'branch' => 'bank_branch',
        'other_details' => 'bank_other_details',
    ];

    $details = [];
    foreach ($fields as $key => $label) {
        $value = trim((string) ($branding[$columnMap[$key]] ?? ''));
        if ($value !== '') {
            $details[$key] = ['label' => $label, 'value' => $value];
        }
    }

    return $details;
}

/** Whether any bank detail has been configured at all. */
function company_has_bank_details(): bool
{
    return company_bank_details() !== [];
}

/** Saves the bank account details entered on the Company Branding page. Blank fields are stored as "not configured" (NULL), same pattern as update_company_name_address(). */
function update_company_bank_details(array $data): bool
{
    $clean = static fn (string $key): ?string => trim((string) ($data[$key] ?? '')) !== '' ? trim((string) $data[$key]) : null;

    $stmt = db()->prepare(
        'UPDATE company_branding SET
            bank_account_name = :account_name,
            bank_name = :bank_name,
            bank_account_number = :account_number,
            bank_ifsc = :ifsc,
            bank_branch = :branch,
            bank_other_details = :other_details,
            updated_at = :now
         WHERE id = 1'
    );
    return $stmt->execute([
        'account_name' => $clean('account_name'),
        'bank_name' => $clean('bank_name'),
        'account_number' => $clean('account_number'),
        'ifsc' => $clean('ifsc'),
        'branch' => $clean('branch'),
        'other_details' => $clean('other_details'),
        'now' => date('Y-m-d H:i:s'),
    ]);
}

/** All configured payment QR codes, in display order — used by both the Company Branding admin page and the bill's Payment Details section. */
function list_company_payment_qrs(): array
{
    return db()->query('SELECT * FROM company_payment_qrs ORDER BY display_order ASC, id ASC')->fetchAll();
}

function find_company_payment_qr(int $id): array|false
{
    $stmt = db()->prepare('SELECT * FROM company_payment_qrs WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}

/**
 * Adds a new configured payment QR (e.g. "Google Pay", "Business UPI
 * QR"). Returns the new row's id on success, or a string error message
 * on failure — same success/error convention as
 * update_company_branding_file().
 */
function add_company_payment_qr(string $methodLabel, array $file): int|string
{
    $methodLabel = trim($methodLabel);
    if ($methodLabel === '') {
        return 'Please name the payment method for this QR (e.g. "Google Pay").';
    }

    $result = handle_upload($file, 'images');
    if (!$result['ok']) {
        return $result['error'];
    }

    $nextOrder = (int) db()->query('SELECT COALESCE(MAX(display_order), -1) + 1 FROM company_payment_qrs')->fetchColumn();

    $stmt = db()->prepare(
        'INSERT INTO company_payment_qrs (method_label, qr_stored_filename, qr_original_filename, qr_mime, display_order, created_at, updated_at)
         VALUES (:label, :stored, :original, :mime, :display_order, :created_at, :updated_at)'
    );
    $now = date('Y-m-d H:i:s');
    $stmt->execute([
        'label' => $methodLabel,
        'stored' => $result['stored_filename'],
        'original' => $result['original_filename'],
        'mime' => $result['mime'],
        'display_order' => $nextOrder,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return (int) db()->lastInsertId();
}

/** Removes a configured payment QR — deletes both the row and its stored file. */
function remove_company_payment_qr(int $id): bool
{
    $qr = find_company_payment_qr($id);
    if ($qr === false) {
        return false;
    }

    $path = dirname(__DIR__) . '/uploads/images/' . $qr['qr_stored_filename'];
    if (is_file($path)) {
        @unlink($path);
    }

    return db()->prepare('DELETE FROM company_payment_qrs WHERE id = :id')->execute(['id' => $id]);
}

/** Whether any payment details (bank details or at least one QR) are configured — used to decide whether the bill's Payment Details section prints at all. */
function company_has_payment_details(): bool
{
    return company_has_bank_details() || list_company_payment_qrs() !== [];
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
