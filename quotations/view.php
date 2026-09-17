<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('quotations');

$quotationId = (int) ($_GET['id'] ?? 0);
$quotation = find_quotation($quotationId);

if ($quotation === false) {
    flash_set('error', 'Quotation not found.');
    redirect('quotations/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    require_permission('quotations', 'edit');
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $status = $_POST['status'] ?? '';
        if (in_array($status, quotation_status_options(), true)) {
            db()->prepare('UPDATE quotations SET status = :status, updated_at = :now WHERE id = :id')
                ->execute(['status' => $status, 'now' => date('Y-m-d H:i:s'), 'id' => $quotationId]);
            flash_set('status', 'Quotation status updated.');
        }
        redirect('quotations/view.php?id=' . $quotationId);
    }
}

$companyLogoUrl = company_branding_url('logo');

$pageTitle = $quotation['quotation_number'];
$activeMenu = 'quotations';
$breadcrumbs = [
    ['label' => 'Quotations', 'url' => url('quotations/index.php')],
    ['label' => $quotation['quotation_number'], 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3 no-print">
    <div>
        <h1 class="h4 mb-1">
            <?= e($quotation['quotation_number']) ?>
            <span class="badge <?= e(quotation_status_badge_class($quotation['status'])) ?>"><?= e(ucwords($quotation['status'])) ?></span>
        </h1>
        <p class="text-muted mb-0"><?= e($quotation['client_name'] ?? '—') ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="printBtn"><i class="bi bi-printer"></i> Print / Save as PDF</button>
        <?php if (user_can(current_user(), 'quotations', 'edit')): ?>
            <a href="<?= e(url('quotations/form.php?id=' . $quotationId)) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i> Edit</a>
            <form method="POST" action="<?= e(url('quotations/view.php?id=' . $quotationId)) ?>" class="d-inline-flex align-items-center gap-1">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_status">
                <select name="status" class="form-select form-select-sm js-auto-submit" style="width:auto;">
                    <?php foreach (quotation_status_options() as $s): ?>
                        <option value="<?= e($s) ?>" <?= $quotation['status'] === $s ? 'selected' : '' ?>><?= e(ucwords($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4" id="printArea">
    <div class="card-body">
        <div class="row mb-4">
            <div class="col-12 col-sm-6">
                <?php if ($companyLogoUrl): ?>
                    <img src="<?= e($companyLogoUrl) ?>" alt="<?= e(company_name()) ?> logo" class="mb-2" style="max-height:64px;max-width:240px;object-fit:contain;">
                <?php endif; ?>
                <h5 class="mb-1"><?= e(company_name()) ?></h5>
                <?php if (company_gstin()): ?><div class="small text-muted">GSTIN: <?= e(company_gstin()) ?></div><?php endif; ?>
                <?php if (company_address()): ?><div class="small text-muted"><?= nl2br(e(company_address())) ?></div><?php endif; ?>
                <?php if (company_mobile()): ?><div class="small text-muted">Mobile: <?= e(company_mobile()) ?></div><?php endif; ?>
            </div>
            <div class="col-12 col-sm-6 text-sm-end mt-3 mt-sm-0">
                <h5 class="mb-1">Quotation <?= e($quotation['quotation_number']) ?></h5>
                <div class="small text-muted">Date: <?= e($quotation['quotation_date']) ?></div>
                <?php if ($quotation['valid_until']): ?><div class="small text-muted">Valid Until: <?= e($quotation['valid_until']) ?></div><?php endif; ?>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12 col-sm-6">
                <div class="text-muted small">Quotation For</div>
                <strong><?= e($quotation['client_name'] ?? '—') ?></strong><br>
                <?php if (!empty($quotation['client_contact_name'])): ?><?= e($quotation['client_contact_name']) ?><br><?php endif; ?>
                <?= nl2br(e($quotation['client_address'] ?? '')) ?><br>
                <?php if (!empty($quotation['client_gstin'])): ?>GSTIN: <?= e($quotation['client_gstin']) ?><br><?php endif; ?>
                <?= e($quotation['client_email'] ?? '') ?> <?= e($quotation['client_mobile'] ?? '') ?>
            </div>
            <div class="col-12 col-sm-6 text-sm-end mt-3 mt-sm-0">
                <div class="text-muted small">Project</div>
                <div class="fw-semibold"><?= e($quotation['project_title']) ?></div>
                <div class="text-muted small mt-2">Website / Project Type</div>
                <div><?= e(quotation_website_type_label($quotation)) ?></div>
            </div>
        </div>

        <?php if (!empty($quotation['scope_description'])): ?>
            <div class="mb-4">
                <h6 class="text-uppercase small fw-semibold text-muted">Scope of Work</h6>
                <div><?= nl2br(e($quotation['scope_description'])) ?></div>
            </div>
        <?php endif; ?>

        <div class="row justify-content-end">
            <div class="col-12 col-md-5">
                <table class="table table-sm mb-0">
                    <tr><td>Project Amount</td><td class="text-end">₹<?= e(number_format((float) $quotation['amount'], 2)) ?></td></tr>
                    <?php if ((float) $quotation['gst_amount'] > 0): ?>
                        <tr><td>GST @ <?= e(rtrim(rtrim(number_format((float) $quotation['gst_rate'], 2), '0'), '.')) ?>%</td><td class="text-end">₹<?= e(number_format((float) $quotation['gst_amount'], 2)) ?></td></tr>
                    <?php endif; ?>
                    <tr class="fw-semibold border-top"><td>Grand Total</td><td class="text-end">₹<?= e(number_format((float) $quotation['total_amount'], 2)) ?></td></tr>
                </table>
            </div>
        </div>

        <?php if (!empty($quotation['payment_terms'])): ?>
            <div class="mt-3">
                <h6 class="text-uppercase small fw-semibold text-muted">Payment Terms</h6>
                <div><?= nl2br(e($quotation['payment_terms'])) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($quotation['terms_conditions'])): ?>
            <div class="mt-3">
                <h6 class="text-uppercase small fw-semibold text-muted">Terms &amp; Conditions</h6>
                <div><?= nl2br(e($quotation['terms_conditions'])) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($quotation['notes'])): ?>
            <div class="mt-3">
                <div class="text-muted small">Notes</div>
                <div><?= nl2br(e($quotation['notes'])) ?></div>
            </div>
        <?php endif; ?>

        <div class="row mt-5">
            <div class="col-12 col-sm-6">
                <div class="small text-muted mb-1">Client Signature</div>
                <div style="min-height:60px;"></div>
                <div class="small text-muted border-top pt-1" style="max-width:240px;">Date: ______________</div>
            </div>
            <div class="col-12 col-sm-6 text-sm-end mt-4 mt-sm-0">
                <div class="small text-muted mb-1">For <?= e(company_name()) ?></div>
                <div style="min-height:60px;"></div>
                <div class="small text-muted border-top pt-1 ms-sm-auto" style="max-width:240px;">Authorized Signatory</div>
            </div>
        </div>
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
document.querySelectorAll('.js-auto-submit').forEach(function (select) {
    select.addEventListener('change', function () { select.closest('form').submit(); });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
