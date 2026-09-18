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
$companySealUrl = company_branding_url('seal');
$companySignatureUrl = company_branding_url('signature');

$quotationOverview = quotation_default_overview($quotation);
$quotationDuration = quotation_duration_label($quotation);
$testingChecklist = quotation_testing_checklist($quotation);
$paymentTerms = $quotation['payment_terms'] ?: quotation_default_payment_terms((float) $quotation['total_amount']);
// The printed quotation always shows the full company content — only the
// project-specific values (client, amounts, dates, scope text if the user
// customized it) differ — so scope/terms fall back to the same defaults
// the form prefills, instead of the section disappearing when blank.
$scopeText = $quotation['scope_description'] ?: (QUOTATION_DEFAULT_SCOPE_BY_TYPE[$quotation['website_type']] ?? '');
$termsText = $quotation['terms_conditions'] ?: QUOTATION_DEFAULT_TERMS;

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
        <p class="text-muted mb-0"><?= field_or($quotation['client_name'] ?? null) ?></p>
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

<div class="quo-doc-shell mb-4" id="printArea">
    <!--
        Rendered as a single <table> (not stacked <div>s) on purpose: a
        <thead>/<tfoot> inside a <table> is the one CSS-only technique
        that reliably repeats on every printed page across browsers —
        it mirrors how the company's source PDF repeats its letterhead
        header and signature footer on every physical page.
    -->
    <table class="quo-doc">
        <thead>
            <tr><td>
                <div class="quo-topbar"><span class="quo-topbar-accent"></span></div>
                <div class="quo-letterhead">
                    <div class="quo-letterhead-brand">
                        <?php if ($companyLogoUrl): ?>
                            <img src="<?= e($companyLogoUrl) ?>" alt="<?= e(company_name()) ?> logo" class="quo-logo">
                        <?php else: ?>
                            <div class="quo-logo-text"><?= e(company_name()) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="quo-letterhead-contact">
                        <?php if (company_email()): ?><div><i class="bi bi-envelope-fill"></i> <?= e(company_email()) ?></div><?php endif; ?>
                        <?php if (company_website()): ?><div><i class="bi bi-globe2"></i> <?= e(company_website()) ?></div><?php endif; ?>
                        <?php if (company_mobile()): ?><div><i class="bi bi-telephone-fill"></i> <?= e(company_mobile()) ?></div><?php endif; ?>
                        <?php if (company_address()): ?><div><i class="bi bi-geo-alt-fill"></i> <?= nl2br(e(company_address())) ?></div><?php endif; ?>
                        <?php if (company_gstin()): ?><div><i class="bi bi-file-earmark-text-fill"></i> <?= e(company_gstin()) ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="quo-divider"></div>
                <div class="quo-doc-meta no-print-hide">
                    <?= e($quotation['quotation_number']) ?> &middot; Date: <?= e($quotation['quotation_date']) ?><?php if ($quotation['valid_until']): ?> &middot; Valid Until: <?= e($quotation['valid_until']) ?><?php endif; ?>
                </div>
            </td></tr>
        </thead>

        <tfoot>
            <tr><td>
                <div class="quo-signblock">
                    <div class="quo-sign-col">
                        <div class="quo-sign-label">client signature</div>
                        <div class="quo-sign-space"></div>
                        <div class="quo-sign-line">Date: ______________</div>
                    </div>
                    <div class="quo-sign-col quo-sign-col-end">
                        <div class="quo-sign-label">For <?= e(company_name()) ?></div>
                        <div class="quo-sign-space">
                            <?php if ($companySealUrl): ?><img src="<?= e($companySealUrl) ?>" alt="Company seal" class="quo-seal"><?php endif; ?>
                            <?php if ($companySignatureUrl): ?><img src="<?= e($companySignatureUrl) ?>" alt="Authorized signature" class="quo-signature"><?php endif; ?>
                        </div>
                        <div class="quo-sign-line">Authorized Signatory</div>
                    </div>
                </div>
            </td></tr>
        </tfoot>

        <tbody>
            <tr><td class="quo-body">

                <h2 class="quo-title"><?= e(strtoupper(quotation_website_type_label($quotation))) ?></h2>

                <div class="quo-section">
                    <div class="quo-block-label">Quotation For</div>
                    <div class="quo-client">
                        <strong><?= field_or($quotation['client_name'] ?? null, 'Not entered', false) ?></strong>
                        <?php if (!empty($quotation['client_contact_name'])): ?><br><?= e($quotation['client_contact_name']) ?><?php endif; ?>
                        <?php if (!empty($quotation['client_address'])): ?><br><?= nl2br(e($quotation['client_address'])) ?><?php endif; ?>
                        <?php if (!empty($quotation['client_gstin'])): ?><br>GSTIN: <?= e($quotation['client_gstin']) ?><?php endif; ?>
                        <?php if (!empty($quotation['client_email']) || !empty($quotation['client_mobile'])): ?><br><?= e($quotation['client_email'] ?? '') ?> <?= e($quotation['client_mobile'] ?? '') ?><?php endif; ?>
                    </div>
                </div>

                <div class="quo-section">
                    <div class="quo-block-label">Development Quotation</div>

                    <div class="quo-kv"><span class="quo-kv-label">Project</span><div class="quo-kv-value"><?= e($quotation['project_title']) ?></div></div>

                    <?php if ($quotationDuration): ?>
                        <div class="quo-kv"><span class="quo-kv-label">Project Duration</span><div class="quo-kv-value"><?= e($quotationDuration) ?></div></div>
                    <?php endif; ?>

                    <div class="quo-kv"><span class="quo-kv-label">Project Overview</span><div class="quo-kv-value"><?= nl2br(e($quotationOverview)) ?></div></div>
                </div>

                <div class="quo-section">
                    <div class="quo-block-label">Project Investment</div>
                    <div class="quo-amount-line">Development Charges: ₹<?= e(number_format((float) $quotation['amount'], 2)) ?></div>
                    <?php if ((float) $quotation['gst_amount'] > 0): ?>
                        <div class="quo-amount-line">GST @ <?= e(rtrim(rtrim(number_format((float) $quotation['gst_rate'], 2), '0'), '.')) ?>%: ₹<?= e(number_format((float) $quotation['gst_amount'], 2)) ?></div>
                    <?php endif; ?>
                    <div class="quo-amount-line quo-amount-total-label">Grand Total</div>
                    <div class="quo-amount-line quo-amount-total-value">₹<?= e(number_format((float) $quotation['total_amount'], 2)) ?></div>
                    <div class="quo-text quo-payment-split"><?= nl2br(e($paymentTerms)) ?></div>
                </div>

                <?php if ($scopeText !== ''): ?>
                    <div class="quo-section quo-page-break">
                        <div class="quo-block-label">Scope of Work</div>
                        <div class="quo-text"><?= nl2br(e($scopeText)) ?></div>
                    </div>
                <?php endif; ?>

                <div class="quo-section">
                    <div class="quo-block-label">Testing &amp; Deployment</div>
                    <div class="quo-text">The completed website will undergo:</div>
                    <ul class="quo-list">
                        <?php foreach ($testingChecklist as $item): ?>
                            <li><?= e($item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="quo-section">
                    <div class="quo-block-label">Development Approach</div>
                    <div class="quo-text"><?= nl2br(e(QUOTATION_DEVELOPMENT_APPROACH_TEXT)) ?></div>
                </div>

                <div class="quo-section quo-page-break">
                    <div class="quo-block-label">Development Support &amp; Functionality Warranty</div>
                    <div class="quo-text"><?= nl2br(e(QUOTATION_WARRANTY_TEXT)) ?></div>
                </div>

                <div class="quo-section quo-page-break">
                    <div class="quo-block-label">Payment Terms &amp; Deployment Process</div>
                    <div class="quo-text quo-payment-split"><?= nl2br(e($paymentTerms)) ?></div>
                    <div class="quo-text"><?= nl2br(e(QUOTATION_PAYMENT_PROCESS_TEXT)) ?></div>
                    <div class="quo-flow">Payment Process:<br>Advance Payment &rarr; Development &rarr; Testing &rarr; Domain Connection &rarr; Remaining Payment &rarr; Final Live Deployment</div>
                </div>

                <div class="quo-section quo-page-break">
                    <div class="quo-block-label">Terms &amp; Conditions</div>
                    <div class="quo-text"><?= nl2br(e($termsText)) ?></div>
                </div>

                <?php if (!empty($quotation['notes'])): ?>
                    <div class="quo-section">
                        <div class="quo-block-label">Notes</div>
                        <div class="quo-text"><?= nl2br(e($quotation['notes'])) ?></div>
                    </div>
                <?php endif; ?>

                <div class="quo-section quo-recap">
                    <div class="quo-amount-line">Project Value: ₹<?= e(number_format((float) $quotation['amount'], 2)) ?><?php if ((float) $quotation['gst_amount'] > 0): ?> + <?= e(rtrim(rtrim(number_format((float) $quotation['gst_rate'], 2), '0'), '.')) ?>% GST<?php endif; ?></div>
                    <div class="quo-amount-line quo-amount-total-label">Total Payable</div>
                    <div class="quo-amount-line quo-amount-total-value">₹<?= e(number_format((float) $quotation['total_amount'], 2)) ?></div>
                    <div class="quo-thankyou">Thank you for choosing our <?= e(quotation_website_type_label($quotation)) ?> Development Services.</div>
                </div>

            </td></tr>
        </tbody>
    </table>
</div>

<style>
.quo-doc-shell { background: #fff; border: 1px solid var(--bs-border-color); border-radius: .375rem; overflow: hidden; }
.quo-doc { width: 100%; max-width: 100%; table-layout: fixed; border-collapse: collapse; color: #1a1a1a; font-family: Arial, Helvetica, sans-serif; }
.quo-doc td { padding: 0; }

.quo-topbar { position: relative; height: 14px; background: #3d2b8c; -webkit-print-color-adjust: exact; print-color-adjust: exact; color-adjust: exact; }
.quo-topbar-accent { position: absolute; top: 0; right: 0; bottom: 0; width: 32%; background: #c9b8f0; -webkit-print-color-adjust: exact; print-color-adjust: exact; color-adjust: exact; }

.quo-letterhead { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 1rem; padding: 1.25rem 1.5rem .75rem; }
.quo-letterhead-brand { min-width: 0; max-width: 100%; }
.quo-logo { max-height: 84px; max-width: 260px; object-fit: contain; }
.quo-logo-text { font-size: 1.4rem; font-weight: 700; color: #3d2b8c; letter-spacing: .02em; }
.quo-letterhead-contact { flex: 1 1 220px; min-width: 0; max-width: 100%; text-align: right; font-size: .8rem; color: #333; line-height: 1.6; overflow-wrap: anywhere; word-break: break-word; }
.quo-letterhead-contact i { color: #3d2b8c; width: 1.1em; text-align: center; margin-right: .35em; }

.quo-divider { height: 3px; background: linear-gradient(90deg, #3d2b8c 0%, #3d2b8c 68%, #c9b8f0 68%, #c9b8f0 100%); -webkit-print-color-adjust: exact; print-color-adjust: exact; color-adjust: exact; }
.quo-doc-meta { padding: .35rem 1.5rem; text-align: right; font-size: .72rem; color: #6c757d; overflow-wrap: anywhere; }

.quo-body { padding: 1.5rem; max-width: 100%; overflow-wrap: anywhere; }
.quo-title { text-align: center; font-size: 1.15rem; font-weight: 700; letter-spacing: .03em; margin: .5rem 0 1.75rem; text-transform: uppercase; }

.quo-section { margin-bottom: 1.75rem; }
.quo-block-label { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #3d2b8c; margin-bottom: .5rem; }
.quo-text { font-size: .9rem; line-height: 1.6; white-space: normal; }
.quo-list { margin: .35rem 0 0; padding-left: 1.25rem; font-size: .9rem; line-height: 1.7; }

.quo-kv { margin-bottom: .6rem; }
.quo-kv-label { display: block; font-size: .78rem; font-weight: 700; text-transform: uppercase; color: #555; }
.quo-kv-value { font-size: .9rem; line-height: 1.6; }

.quo-client { font-size: .9rem; line-height: 1.6; }

.quo-amount-line { font-size: .9rem; line-height: 1.7; }
.quo-amount-total-label { font-weight: 700; margin-top: .35rem; }
.quo-amount-total-value { font-weight: 700; font-size: 1.05rem; margin-bottom: .1rem; }
.quo-payment-split { margin-top: .5rem; }

.quo-flow { margin-top: .75rem; font-size: .85rem; line-height: 1.6; color: #333; }

.quo-recap { border-top: 1px solid #e2e2e2; padding-top: 1rem; }
.quo-thankyou { margin-top: .75rem; font-size: .9rem; font-weight: 600; }

.quo-signblock { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 1.5rem; padding: 1.5rem; }
.quo-sign-col { min-width: 220px; }
.quo-sign-col-end { text-align: right; margin-left: auto; }
.quo-sign-label { font-size: .8rem; color: #555; margin-bottom: .25rem; }
.quo-sign-space { min-height: 60px; display: flex; align-items: flex-end; }
.quo-sign-col-end .quo-sign-space { justify-content: flex-end; }
.quo-seal { height: 70px; max-width: 100%; }
.quo-signature { height: 50px; max-width: 100%; margin-left: .5rem; }
.quo-sign-line { font-size: .8rem; color: #555; border-top: 1px solid #999; padding-top: .25rem; max-width: 240px; }
.quo-sign-col-end .quo-sign-line { margin-left: auto; }

/*
    A genuinely different mobile layout for screens under 576px — not just
    the desktop letterhead squeezed narrower. The two-column letterhead
    (logo left, contact block right-aligned) is what was overflowing on
    phones, so on small screens it becomes a single stacked, left-aligned
    column with tighter spacing and smaller type throughout, and the
    signature block stacks instead of sitting side by side.
*/
@media screen and (max-width: 575.98px) {
    .quo-topbar { height: 10px; }
    .quo-letterhead { flex-direction: column; align-items: flex-start; padding: 1rem 1rem .6rem; gap: .75rem; }
    .quo-logo { max-height: 56px; max-width: 70%; }
    .quo-logo-text { font-size: 1.1rem; }
    .quo-letterhead-contact { flex: none; width: 100%; text-align: left; font-size: .75rem; }
    .quo-doc-meta { text-align: left; padding: .35rem 1rem; }

    .quo-body { padding: 1rem; }
    .quo-title { font-size: 1rem; margin: .35rem 0 1.25rem; }
    .quo-section { margin-bottom: 1.35rem; }
    .quo-text, .quo-list, .quo-amount-line, .quo-client, .quo-kv-value { font-size: .85rem; }

    .quo-signblock { flex-direction: column; padding: 1rem; gap: 1.75rem; }
    .quo-sign-col { min-width: 0; width: 100%; }
    .quo-sign-col-end { text-align: left; margin-left: 0; }
    .quo-sign-col-end .quo-sign-space { justify-content: flex-start; }
    .quo-sign-line, .quo-sign-col-end .quo-sign-line { max-width: 100%; margin-left: 0; }
}

@media print {
    .no-print, .app-sidebar, .app-navbar, nav, .btn { display: none !important; }
    .app-content { margin: 0 !important; padding: 0 !important; }
    .quo-doc-shell { border: none; }
    .quo-doc-meta { display: none; }
    .quo-page-break { page-break-before: always; }
    .quo-doc, .quo-doc * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }
    @page { margin: 0 1.25cm 2.2cm; }
}
</style>

<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('printBtn')?.addEventListener('click', function () { window.print(); });
document.querySelectorAll('.js-auto-submit').forEach(function (select) {
    select.addEventListener('change', function () { select.closest('form').submit(); });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
