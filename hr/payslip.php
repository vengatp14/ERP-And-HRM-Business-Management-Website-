<?php

declare(strict_types=1);

/**
 * hr/payslip.php
 * The "Download" action for a salary record on hr/salaries.php.
 * Renders straight from the employee_salaries row every time — there's
 * no separately stored PDF/file — so once an Admin corrects a record
 * via the Edit link on hr/salaries.php, this download immediately
 * reflects the corrected figures with no extra "regenerate" step.
 * Uses the same browser print-to-PDF approach as the rest of the app
 * (see hr/certificate-download.php, accounts/gst_report.php).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_role('super_admin', 'admin');

$salaryId = (int) ($_GET['id'] ?? 0);
$record = $salaryId > 0 ? find_salary_record($salaryId) : false;

if (!$record) {
    flash_set('error', 'Salary record not found.');
    redirect('hr/salaries.php');
}

$monthLabel = date('F Y', strtotime($record['pay_month'] . '-01'));
$companyLogoUrl = company_branding_url('logo');

$pageTitle = 'Payslip — ' . $record['full_name'] . ' — ' . $monthLabel;
$activeMenu = 'employees';
$breadcrumbs = [
    ['label' => 'Employees', 'url' => url('employees/index.php')],
    ['label' => 'Salaries', 'url' => url('hr/salaries.php')],
    ['label' => 'Payslip', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3 no-print">
    <h1 class="h4 mb-0"><?= e($pageTitle) ?></h1>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary btn-sm" id="printBtn"><i class="bi bi-printer"></i> Print / Save as PDF</button>
        <a href="<?= e(url('hr/salaries.php?month=' . $record['pay_month'] . '&edit=' . $record['id'])) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i> Edit</a>
        <a href="<?= e(url('hr/salaries.php?month=' . $record['pay_month'])) ?>" class="btn btn-outline-secondary btn-sm">Back</a>
    </div>
</div>

<div class="card" id="printArea">
    <div class="card-body p-4 p-md-5">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4 pb-3 border-bottom">
            <div>
                <?php if ($companyLogoUrl): ?>
                    <img src="<?= e($companyLogoUrl) ?>" alt="<?= e(company_name()) ?> logo" class="mb-2" style="max-height:64px;max-width:240px;object-fit:contain;">
                <?php endif; ?>
                <h5 class="mb-1"><?= e(company_name()) ?></h5>
                <?php if (company_address()): ?><div class="small text-muted"><?= nl2br(e(company_address())) ?></div><?php endif; ?>
            </div>
            <div class="text-md-end">
                <h4 class="mb-1">Payslip</h4>
                <div class="text-muted">For the month of <strong><?= e($monthLabel) ?></strong></div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="text-muted small text-uppercase mb-1">Employee</div>
                <div class="fw-semibold"><?= e($record['full_name']) ?></div>
                <div class="small text-muted"><?= e($record['designation'] ?: 'Not Specified') ?><?= $record['department'] ? ' · ' . e($record['department']) : '' ?></div>
            </div>
            <div class="col-md-6 text-md-end">
                <div class="text-muted small text-uppercase mb-1">Payment Status</div>
                <span class="badge <?= $record['status'] === 'paid' ? 'text-bg-success' : 'text-bg-warning' ?> fs-6"><?= e(ucfirst($record['status'])) ?></span>
                <?php if (!empty($record['paid_on'])): ?>
                    <div class="small text-muted mt-1">Paid on <?= e(date('d M Y', strtotime($record['paid_on']))) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-responsive mb-3">
            <table class="table table-bordered mb-0">
                <thead class="table-light">
                    <tr><th>Earnings</th><th class="text-end">Amount</th><th>Deductions</th><th class="text-end">Amount</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Basic Pay</td>
                        <td class="text-end">₹<?= e(number_format((float) $record['basic_pay'], 2)) ?></td>
                        <td>Deductions</td>
                        <td class="text-end">₹<?= e(number_format((float) $record['deductions'], 2)) ?></td>
                    </tr>
                    <tr>
                        <td>Allowances</td>
                        <td class="text-end">₹<?= e(number_format((float) $record['allowances'], 2)) ?></td>
                        <td></td>
                        <td class="text-end"></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="fw-semibold table-light">
                        <td>Gross Earnings</td>
                        <td class="text-end">₹<?= e(number_format((float) $record['basic_pay'] + (float) $record['allowances'], 2)) ?></td>
                        <td>Total Deductions</td>
                        <td class="text-end">₹<?= e(number_format((float) $record['deductions'], 2)) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="d-flex justify-content-end mb-4">
            <div class="border rounded p-3 text-end" style="min-width: 260px;">
                <div class="text-muted small text-uppercase">Net Pay</div>
                <div class="h4 mb-0">₹<?= e(number_format((float) $record['net_pay'], 2)) ?></div>
            </div>
        </div>

        <?php if (!empty($record['notes'])): ?>
            <div class="mb-4">
                <div class="text-muted small text-uppercase mb-1">Notes</div>
                <div><?= nl2br(e($record['notes'])) ?></div>
            </div>
        <?php endif; ?>

        <div class="small text-muted pt-3 border-top">This is a system-generated payslip and reflects the figures currently on record for this pay period.</div>
    </div>
</div>

<style>
@media print {
    .no-print, .app-sidebar, .app-navbar, nav, .btn { display: none !important; }
    .app-content { margin: 0 !important; padding: 0 !important; }
}
</style>

<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('printBtn')?.addEventListener('click', function () { window.print(); });
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
