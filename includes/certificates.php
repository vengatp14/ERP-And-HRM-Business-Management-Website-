<?php

declare(strict_types=1);

/**
 * includes/certificates.php
 * Employee certificates: Pay Slip, Offer Letter, Experience Certificate.
 *
 * Each issued certificate is stored as a row (type + JSON `details`)
 * so it can always be re-downloaded exactly as issued, and rendered
 * on demand as a print-friendly page (see hr/certificate-download.php),
 * matching the same "print to PDF" pattern billing/view.php already
 * uses for invoices.
 */

const CERTIFICATE_TYPES = [
    'pay_slip' => 'Pay Slip',
    'offer_letter' => 'Offer Letter',
    'experience_certificate' => 'Experience Certificate',
];

function create_certificate(int $userId, string $type, ?string $referenceLabel, array $details, int $issuedBy): int
{
    $stmt = db()->prepare(
        'INSERT INTO employee_certificates (user_id, type, reference_label, details, issued_by, issued_at)
         VALUES (:uid, :type, :ref, :details, :issued_by, :now)'
    );
    $stmt->execute([
        'uid' => $userId,
        'type' => $type,
        'ref' => $referenceLabel,
        'details' => json_encode($details, JSON_THROW_ON_ERROR),
        'issued_by' => $issuedBy,
        'now' => date('Y-m-d H:i:s'),
    ]);

    return (int) db()->lastInsertId();
}

function list_certificates(?int $userId = null, int $limit = 50): array
{
    if ($userId !== null) {
        $stmt = db()->prepare(
            'SELECT c.*, u.full_name, u.email FROM employee_certificates c
             JOIN users u ON u.id = c.user_id
             WHERE c.user_id = :uid ORDER BY c.issued_at DESC LIMIT :lim'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    } else {
        $stmt = db()->prepare(
            'SELECT c.*, u.full_name, u.email FROM employee_certificates c
             JOIN users u ON u.id = c.user_id
             ORDER BY c.issued_at DESC LIMIT :lim'
        );
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function find_certificate(int $id): array|false
{
    $stmt = db()->prepare(
        'SELECT c.*, u.full_name, u.email, u.department, u.designation, u.mobile
         FROM employee_certificates c JOIN users u ON u.id = c.user_id WHERE c.id = :id'
    );
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}

/**
 * Renders the body markup (no <html>/<head>) for a certificate, ready
 * to drop into the print-styled wrapper in hr/certificate-download.php.
 */
function render_certificate_body(array $certificate): string
{
    $details = json_decode((string) $certificate['details'], true) ?? [];
    $employeeName = e($certificate['full_name']);
    $designation = e((string) ($certificate['designation'] ?? ''));
    $department = e((string) ($certificate['department'] ?? ''));
    $issuedAt = date('d M Y', strtotime((string) $certificate['issued_at']));
    $companyName = e(company_name());
    $companyAddress = e(company_address());

    // Company logo/seal/signature — uploaded from the website (Admin >
    // Company Branding) or the bundled placeholder, same as billing/view.php.
    $companyLogoUrl = company_branding_url('logo');
    $companySealUrl = company_branding_url('seal');
    $companySignatureUrl = company_branding_url('signature');
    $logoHtml = $companyLogoUrl
        ? '<img src="' . e($companyLogoUrl) . '" alt="' . $companyName . ' logo" class="mb-2" style="max-height:64px;max-width:240px;object-fit:contain;">'
        : '';
    $sealSignatureHtml = ($companySealUrl || $companySignatureUrl)
        ? '<div class="d-flex align-items-end gap-2" style="min-height:90px;">'
            . ($companySealUrl ? '<img src="' . e($companySealUrl) . '" alt="Company seal" style="height:80px;">' : '')
            . ($companySignatureUrl ? '<img src="' . e($companySignatureUrl) . '" alt="Authorized signature" style="height:60px;">' : '')
            . '</div>'
        : '';

    if ($certificate['type'] === 'pay_slip') {
        $basic = (float) ($details['basic_pay'] ?? 0);
        $allowances = (float) ($details['allowances'] ?? 0);
        $deductions = (float) ($details['deductions'] ?? 0);
        $net = $basic + $allowances - $deductions;
        $payMonth = e((string) ($details['pay_month'] ?? $certificate['reference_label']));
        $basicFmt = number_format($basic, 2);
        $allowFmt = number_format($allowances, 2);
        $deductFmt = number_format($deductions, 2);
        $netFmt = number_format($net, 2);

        return <<<HTML
            <div class="text-center mb-4">
                {$logoHtml}
                <h2 class="mb-0">{$companyName}</h2>
                <div class="text-muted small">{$companyAddress}</div>
                <h4 class="mt-3 mb-0">Pay Slip</h4>
                <div class="text-muted small">{$payMonth}</div>
            </div>
            <table class="table table-bordered mb-4">
                <tr><th style="width:35%">Employee Name</th><td>{$employeeName}</td></tr>
                <tr><th>Designation</th><td>{$designation}</td></tr>
                <tr><th>Department</th><td>{$department}</td></tr>
                <tr><th>Pay Month</th><td>{$payMonth}</td></tr>
            </table>
            <table class="table table-bordered mb-4">
                <thead><tr><th>Earnings</th><th class="text-end">Amount (₹)</th></tr></thead>
                <tbody>
                    <tr><td>Basic Pay</td><td class="text-end">{$basicFmt}</td></tr>
                    <tr><td>Allowances</td><td class="text-end">{$allowFmt}</td></tr>
                    <tr><td>Deductions</td><td class="text-end">-{$deductFmt}</td></tr>
                    <tr class="fw-bold"><td>Net Pay</td><td class="text-end">₹{$netFmt}</td></tr>
                </tbody>
            </table>
            <p class="text-muted small mb-4">Issued on {$issuedAt}. This is a system-generated pay slip.</p>
            <div class="row mt-4">
                <div class="col-6">
                    <div class="small text-muted mb-1">For {$companyName}</div>
                    {$sealSignatureHtml}
                    <div class="small text-muted border-top pt-1" style="width:240px;">Authorized Signatory</div>
                </div>
            </div>
            HTML;
    }

    if ($certificate['type'] === 'offer_letter') {
        $offerDesignation = e((string) ($details['designation'] ?? ''));
        $offerDepartment = e((string) ($details['department'] ?? ''));
        $joiningDate = !empty($details['joining_date']) ? date('d M Y', strtotime((string) $details['joining_date'])) : '';
        $ctc = e((string) ($details['ctc'] ?? ''));
        $notes = nl2br(e((string) ($details['notes'] ?? '')));

        return <<<HTML
            <div class="text-center mb-4">
                {$logoHtml}
                <h2 class="mb-0">{$companyName}</h2>
                <div class="text-muted small">{$companyAddress}</div>
                <h4 class="mt-3">Offer Letter</h4>
            </div>
            <p class="text-end">Date: {$issuedAt}</p>
            <p>Dear <strong>{$employeeName}</strong>,</p>
            <p>
                We are pleased to offer you the position of <strong>{$offerDesignation}</strong>
                in the <strong>{$offerDepartment}</strong> department at {$companyName}, effective
                from <strong>{$joiningDate}</strong>.
            </p>
            <p>Your annual CTC will be <strong>₹{$ctc}</strong>, as discussed.</p>
            <p>{$notes}</p>
            <p class="mt-4">We look forward to having you on our team.</p>
            <div class="mt-5">
                <div class="small text-muted mb-1">For {$companyName}</div>
                {$sealSignatureHtml}
                <div class="small text-muted border-top pt-1" style="width:240px;">Authorized Signatory</div>
            </div>
            HTML;
    }

    // experience_certificate
    $expDesignation = e((string) ($details['designation'] ?? ''));
    $fromDate = !empty($details['from_date']) ? date('d M Y', strtotime((string) $details['from_date'])) : '';
    $toDate = !empty($details['to_date']) ? date('d M Y', strtotime((string) $details['to_date'])) : '';
    $remarks = nl2br(e((string) ($details['remarks'] ?? '')));

    return <<<HTML
        <div class="text-center mb-4">
            {$logoHtml}
            <h2 class="mb-0">{$companyName}</h2>
            <div class="text-muted small">{$companyAddress}</div>
            <h4 class="mt-3">Experience Certificate</h4>
        </div>
        <p class="text-end">Date: {$issuedAt}</p>
        <p>This is to certify that <strong>{$employeeName}</strong> worked with {$companyName}
            as <strong>{$expDesignation}</strong> from <strong>{$fromDate}</strong> to
            <strong>{$toDate}</strong>.</p>
        <p>{$remarks}</p>
        <p>We wish them success in their future endeavours.</p>
        <div class="mt-5">
            <div class="small text-muted mb-1">For {$companyName}</div>
            {$sealSignatureHtml}
            <div class="small text-muted border-top pt-1" style="width:240px;">Authorized Signatory</div>
        </div>
        HTML;
}
