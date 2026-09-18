<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('billing');

$invoiceId = (int) ($_GET['id'] ?? 0);
$invoice = find_invoice($invoiceId);

if ($invoice === false) {
    flash_set('error', 'Invoice not found.');
    redirect('billing/index.php');
}

$currentUserId = (int) current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_payment') {
        $amount = (float) ($_POST['amount'] ?? 0);
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');

        $balanceDueNow = (float) $invoice['total_amount'] - (float) $invoice['amount_paid'];

        if ($amount <= 0) {
            flash_set('error', 'Payment amount must be greater than zero.');
            redirect('billing/view.php?id=' . $invoiceId);
        }

        // Never let a recorded payment push amount_paid past the invoice
        // total — auto-cap to whatever balance remains rather than
        // rejecting, so a fat-fingered amount doesn't block the payment
        // outright.
        if ($amount > $balanceDueNow) {
            $amount = $balanceDueNow;
            flash_set('status', 'Payment amount was capped to the balance due (₹' . number_format($balanceDueNow, 2) . ').');
        }

        $paymentMethod = $_POST['payment_method'] ?? 'bank_transfer';
        $reference = sanitize_string($_POST['reference'] ?? '') ?: null;
        $notes = sanitize_string($_POST['notes'] ?? '') ?: null;

        $invoicePaymentId = add_invoice_payment($invoiceId, [
            'amount' => $amount,
            'payment_date' => $paymentDate,
            'payment_method' => $paymentMethod,
            'reference' => $reference,
            'notes' => $notes,
        ], $currentUserId);

        // Mirror into project_income when this invoice is linked to a
        // project, so a payment recorded here also shows up in that
        // project's Payments card (Total Received / Recent payments) —
        // same reasoning in reverse as record_project_income() mirroring
        // into invoice_payments. invoice_payment_id keeps this row out of
        // total_income()/get_ledger_entries()'s separate project_income
        // sum so the amount isn't counted twice.
        if (!empty($invoice['project_id'])) {
            record_project_income_mirror_only((int) $invoice['project_id'], $invoicePaymentId, [
                'amount' => $amount,
                'payment_date' => $paymentDate,
                'payment_method' => $paymentMethod,
                'reference' => $reference,
                'notes' => 'Mirrored from an invoice payment' . ($notes ? ' — ' . $notes : '') . '.',
            ], $currentUserId);
        }

        flash_set('status', 'Payment recorded.');
        redirect('billing/view.php?id=' . $invoiceId);
    }

    if ($action === 'cancel_invoice') {
        db()->prepare("UPDATE invoices SET status = 'cancelled', updated_at = :now WHERE id = :id")
            ->execute(['now' => date('Y-m-d H:i:s'), 'id' => $invoiceId]);
        flash_set('status', 'Invoice cancelled.');
        redirect('billing/view.php?id=' . $invoiceId);
    }

    if ($action === 'mark_sent') {
        db()->prepare("UPDATE invoices SET status = 'sent', updated_at = :now WHERE id = :id AND status = 'draft'")
            ->execute(['now' => date('Y-m-d H:i:s'), 'id' => $invoiceId]);
        flash_set('status', 'Invoice marked as sent.');
        redirect('billing/view.php?id=' . $invoiceId);
    }

    if ($action === 'mark_completed') {
        $balanceNow = (float) $invoice['total_amount'] - (float) $invoice['amount_paid'];
        // Completed = fully paid, full stop. No manual override — an
        // invoice with money still owed stays open regardless of what's
        // submitted here.
        if ($balanceNow > 0.0) {
            flash_set('error', 'This invoice still has ₹' . number_format($balanceNow, 2) . ' outstanding. Record the remaining payment before marking it Completed.');
            redirect('billing/view.php?id=' . $invoiceId);
        }
        db()->prepare("UPDATE invoices SET status = 'completed', updated_at = :now WHERE id = :id")
            ->execute(['now' => date('Y-m-d H:i:s'), 'id' => $invoiceId]);
        flash_set('status', 'Invoice marked as completed.');
        redirect('billing/view.php?id=' . $invoiceId);
    }
}

$items = get_invoice_items($invoiceId);
$payments = get_invoice_payments($invoiceId);
$balanceDue = (float) $invoice['total_amount'] - (float) $invoice['amount_paid'];
$showHsnSac = false;
$showGst = false;
$showTax = false;
foreach ($items as $item) {
    $showHsnSac = $showHsnSac || trim((string) ($item['hsn_sac'] ?? '')) !== '';
    $showGst = $showGst || (float) ($item['gst_rate'] ?? 0) > 0;
    $showTax = $showTax || (float) ($item['line_tax'] ?? 0) > 0;
}
$showTaxSummary = (float) ($invoice['cgst_amount'] ?? 0) > 0
    || (float) ($invoice['sgst_amount'] ?? 0) > 0
    || (float) ($invoice['igst_amount'] ?? 0) > 0;

// Company logo/seal/signature — uploaded from the website (Admin >
// Company Branding) if set, else the bundled default placeholder.
// See includes/company_branding.php.
$companyLogoUrl = company_branding_url('logo');
$companySealUrl = company_branding_url('seal');
$companySignatureUrl = company_branding_url('signature');

// Payment Details section (req: QR codes + bank account details on
// every final bill) — pulled live from Admin > Company Branding so a
// change there applies to every future bill without editing invoices.
// Nothing prints if nothing has been configured (see
// company_has_payment_details()).
$paymentQrs = list_company_payment_qrs();
$bankDetails = company_bank_details();
$showPaymentDetails = company_has_payment_details();

// Client's own logo/seal/signature — uploaded on their client record
// (Clients > Edit > Invoice Branding). Falls back to nothing (not our
// defaults) when the client hasn't uploaded one, so we never show our
// own branding twice.
$clientLogoUrl = !empty($invoice['client_logo_stored_filename'])
    ? url('clients/branding-download.php?id=' . $invoice['client_id'] . '&type=logo') : null;
$clientSealUrl = !empty($invoice['client_seal_stored_filename'])
    ? url('clients/branding-download.php?id=' . $invoice['client_id'] . '&type=seal') : null;
$clientSignatureUrl = !empty($invoice['client_signature_stored_filename'])
    ? url('clients/branding-download.php?id=' . $invoice['client_id'] . '&type=signature') : null;

$pageTitle = $invoice['invoice_number'];
$activeMenu = 'billing';
$breadcrumbs = [
    ['label' => 'GST Billing', 'url' => url('billing/index.php')],
    ['label' => $invoice['invoice_number'], 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3 no-print">
    <div>
        <h1 class="h4 mb-1">
            <?= e($invoice['invoice_number']) ?>
            <span class="badge <?= e(invoice_status_badge_class($invoice['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', $invoice['status']))) ?></span>
        </h1>
        <p class="text-muted mb-0"><?= field_or($invoice['client_name'] ?? null) ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="printBtn"><i class="bi bi-printer"></i> Print</button>
        <?php if (!in_array($invoice['status'], ['paid', 'completed', 'cancelled'], true) && user_can(current_user(), 'billing', 'edit')): ?>
            <a href="<?= e(url('billing/form.php?id=' . $invoiceId)) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i> Edit</a>
        <?php endif; ?>
        <?php if ($invoice['status'] === 'draft'): ?>
            <form method="POST" action="<?= e(url('billing/view.php?id=' . $invoiceId)) ?>" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_sent">
                <button type="submit" class="btn btn-primary btn-sm">Mark as Sent</button>
            </form>
        <?php endif; ?>
        <?php if (!in_array($invoice['status'], ['completed', 'cancelled'], true)): ?>
            <?php if ($balanceDue > 0.0): ?>
                <button type="button" class="btn btn-outline-primary btn-sm" disabled
                        title="₹<?= e(number_format($balanceDue, 2)) ?> still outstanding — record the full payment first.">
                    Mark Completed
                </button>
            <?php else: ?>
                <form method="POST" action="<?= e(url('billing/view.php?id=' . $invoiceId)) ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_completed">
                    <button type="submit" class="btn btn-outline-primary btn-sm">Mark Completed</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (!in_array($invoice['status'], ['paid', 'completed', 'cancelled'], true)): ?>
            <form method="POST" action="<?= e(url('billing/view.php?id=' . $invoiceId)) ?>" class="d-inline js-confirm-cancel">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel_invoice">
                <button type="submit" class="btn btn-outline-danger btn-sm">Cancel Invoice</button>
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
                <?php if (invoice_is_gst($invoice) && company_gstin()): ?><div class="small text-muted">GSTIN: <?= e(company_gstin()) ?></div><?php endif; ?>
                <?php if (company_address()): ?><div class="small text-muted"><?= nl2br(e(company_address())) ?></div><?php endif; ?>
                <?php if (company_mobile()): ?><div class="small text-muted">Mobile: <?= e(company_mobile()) ?></div><?php endif; ?>
            </div>
            <div class="col-12 col-sm-6 text-sm-end mt-3 mt-sm-0">
                <h5 class="mb-1">Invoice <?= e($invoice['invoice_number']) ?></h5>
                <div class="small text-muted">Date: <?= e($invoice['invoice_date']) ?></div>
                <?php if ($invoice['due_date']): ?><div class="small text-muted">Due: <?= e($invoice['due_date']) ?></div><?php endif; ?>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12 col-sm-6">
                <?php if ($clientLogoUrl): ?>
                    <img src="<?= e($clientLogoUrl) ?>" alt="<?= e($invoice['client_name'] ?? '') ?> logo" class="mb-2" style="max-height:56px;max-width:200px;object-fit:contain;">
                <?php endif; ?>
                <div class="text-muted small">Billed To</div>
                <strong><?= field_or($invoice['client_name'] ?? null, 'Not entered', false) ?></strong><br>
                <?= nl2br(e($invoice['client_address'] ?? '')) ?><br>
                <?php if ($invoice['client_gstin']): ?>GSTIN: <?= e($invoice['client_gstin']) ?><br><?php endif; ?>
                <?= e($invoice['client_email'] ?? '') ?> <?= e($invoice['client_mobile'] ?? '') ?>
            </div>
            <div class="col-12 col-sm-6 text-sm-end mt-3 mt-sm-0">
                <?php if ($invoice['project_title']): ?><div class="text-muted small">Project</div><div><?= e($invoice['project_title']) ?></div><?php endif; ?>
                <div class="text-muted small mt-2">Place of Supply</div>
                <div>
                    <?= field_or($invoice['place_of_supply'] ?? null, 'Not specified', false) ?>
                    (<?= invoice_is_gst($invoice) ? 'GST Applicable' : 'Non-GST Invoice' ?>)
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Description</th>
                        <?php if ($showHsnSac): ?><th>HSN/SAC</th><?php endif; ?>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Unit Price</th>
                        <?php if ($showGst): ?><th class="text-end">GST %</th><?php endif; ?>
                        <?php if ($showTax): ?><th class="text-end">Tax</th><?php endif; ?>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= e($item['description']) ?></td>
                            <?php if ($showHsnSac): ?><td><?= e($item['hsn_sac'] ?? '') ?></td><?php endif; ?>
                            <td class="text-end"><?= e((string) $item['quantity']) ?></td>
                            <td class="text-end">₹<?= e(number_format((float) $item['unit_price'], 2)) ?></td>
                            <?php if ($showGst): ?><td class="text-end"><?= e((string) $item['gst_rate']) ?>%</td><?php endif; ?>
                            <?php if ($showTax): ?><td class="text-end">₹<?= e(number_format((float) $item['line_tax'], 2)) ?></td><?php endif; ?>
                            <td class="text-end">₹<?= e(number_format((float) $item['line_total'], 2)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="row justify-content-end">
            <div class="col-12 col-md-5">
                <table class="table table-sm mb-0">
                    <tr><td>Subtotal</td><td class="text-end">₹<?= e(number_format((float) $invoice['subtotal'], 2)) ?></td></tr>
                    <?php if ((float) $invoice['discount_amount'] > 0): ?>
                        <tr><td>Discount</td><td class="text-end">−₹<?= e(number_format((float) $invoice['discount_amount'], 2)) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($showTaxSummary): ?>
                        <tr><td>Taxable Amount</td><td class="text-end">₹<?= e(number_format((float) $invoice['taxable_amount'], 2)) ?></td></tr>
                        <?php if ((float) $invoice['cgst_amount'] > 0): ?>
                            <tr><td>CGST</td><td class="text-end">₹<?= e(number_format((float) $invoice['cgst_amount'], 2)) ?></td></tr>
                        <?php endif; ?>
                        <?php if ((float) $invoice['sgst_amount'] > 0): ?>
                            <tr><td>SGST</td><td class="text-end">₹<?= e(number_format((float) $invoice['sgst_amount'], 2)) ?></td></tr>
                        <?php endif; ?>
                        <?php if ((float) $invoice['igst_amount'] > 0): ?>
                            <tr><td>IGST</td><td class="text-end">₹<?= e(number_format((float) $invoice['igst_amount'], 2)) ?></td></tr>
                        <?php endif; ?>
                    <?php endif; ?>
                    <tr class="fw-semibold border-top"><td>Total</td><td class="text-end">₹<?= e(number_format((float) $invoice['total_amount'], 2)) ?></td></tr>
                    <tr><td>Paid</td><td class="text-end">₹<?= e(number_format((float) $invoice['amount_paid'], 2)) ?></td></tr>
                    <tr class="fw-semibold"><td>Balance Due</td><td class="text-end">₹<?= e(number_format($balanceDue, 2)) ?></td></tr>
                </table>
            </div>
        </div>

        <?php if ($showPaymentDetails): ?>
            <div class="row mt-4">
                <div class="col-12">
                    <div class="border rounded p-3" id="paymentDetailsSection">
                        <div class="text-uppercase small fw-semibold text-muted mb-3">Payment Details</div>
                        <div class="row g-4">
                            <?php if (!empty($paymentQrs)): ?>
                                <div class="col-12 col-md-6">
                                    <div class="d-flex flex-wrap gap-4">
                                        <?php foreach ($paymentQrs as $qr): ?>
                                            <div class="text-center">
                                                <img src="<?= e(url('admin/company-payment-qr-download.php?id=' . $qr['id'])) ?>"
                                                     alt="<?= e($qr['method_label']) ?> payment QR"
                                                     style="height:130px;width:130px;object-fit:contain;" class="border rounded p-1 bg-white">
                                                <div class="small text-muted mt-1"><?= e($qr['method_label']) ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($bankDetails)): ?>
                                <div class="col-12 col-md-6">
                                    <div class="small fw-semibold mb-1">Bank Details</div>
                                    <table class="table table-sm table-borderless mb-0" style="max-width:360px;">
                                        <?php foreach ($bankDetails as $field): ?>
                                            <tr>
                                                <td class="text-muted py-1 pe-2" style="width:45%;"><?= e($field['label']) ?></td>
                                                <td class="py-1"><?= e($field['value']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($invoice['notes'])): ?>
            <div class="mt-3"><div class="text-muted small">Notes</div><?= nl2br(e($invoice['notes'])) ?></div>
        <?php endif; ?>
        <?php if (!empty($invoice['terms'])): ?>
            <div class="mt-3"><div class="text-muted small">Terms &amp; Conditions</div><?= nl2br(e($invoice['terms'])) ?></div>
        <?php endif; ?>

        <?php $companySocialLinks = company_social_links(); ?>
        <?php if (!empty($companySocialLinks)): ?>
            <!--
                Profile name only (never a raw URL) so the printed copy stays
                clean and short — see req #14. On the web/soft-copy view the
                name is a real hyperlink to the configured URL (req #15);
                Chrome/Edge's "Save as PDF" preserves that as a clickable
                link in the exported PDF too.
            -->
            <div class="mt-3 d-flex flex-wrap gap-3 small">
                <?php foreach ($companySocialLinks as $platformKey => $link): ?>
                    <a href="<?= e($link['url']) ?>" target="_blank" rel="noopener" class="text-decoration-none">
                        <i class="bi <?= e(COMPANY_SOCIAL_PLATFORMS[$platformKey]['icon']) ?>"></i>
                        <?= e(COMPANY_SOCIAL_PLATFORMS[$platformKey]['label']) ?>:
                        <?= e($link['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="row mt-5">
            <div class="col-12 col-sm-6">
                <div class="small text-muted mb-1">For <?= e(company_name()) ?></div>
                <div class="d-flex align-items-end gap-2" style="min-height:90px;">
                    <?php if ($companySealUrl): ?>
                        <img src="<?= e($companySealUrl) ?>" alt="Company seal" style="height:80px;max-width:100%;">
                    <?php endif; ?>
                    <?php if ($companySignatureUrl): ?>
                        <img src="<?= e($companySignatureUrl) ?>" alt="Authorized signature" style="height:60px;max-width:100%;">
                    <?php endif; ?>
                </div>
                <div class="small text-muted border-top pt-1" style="max-width:240px;">Authorized Signatory</div>
            </div>
            <div class="col-12 col-sm-6 text-sm-end mt-4 mt-sm-0">
                <div class="small text-muted mb-1">For <?= e($invoice['client_name'] ?? 'Client') ?></div>
                <div class="d-flex align-items-end justify-content-sm-end gap-2" style="min-height:90px;">
                    <?php if ($clientSealUrl): ?>
                        <img src="<?= e($clientSealUrl) ?>" alt="Client seal" style="height:80px;max-width:100%;">
                    <?php endif; ?>
                    <?php if ($clientSignatureUrl): ?>
                        <img src="<?= e($clientSignatureUrl) ?>" alt="Client authorized signature" style="height:60px;max-width:100%;">
                    <?php endif; ?>
                </div>
                <div class="small text-muted border-top pt-1 ms-sm-auto" style="max-width:240px;">
                    <?= ($clientSealUrl || $clientSignatureUrl) ? 'Authorized Signatory' : 'Receiver\'s Signature' ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card no-print">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Payments</h2></div>
    <div class="card-body">
        <div class="table-responsive mb-3">
            <table class="table table-sm">
                <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Recorded By</th></tr></thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">No payments recorded yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><?= e($payment['payment_date']) ?></td>
                            <td>₹<?= e(number_format((float) $payment['amount'], 2)) ?></td>
                            <td><?= e(ucwords(str_replace('_', ' ', $payment['payment_method']))) ?></td>
                            <td><?= field_or($payment['reference'] ?? null, 'Not provided') ?></td>
                            <td><?= field_or($payment['created_by_name'] ?? null, 'Not recorded') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (!in_array($invoice['status'], ['cancelled', 'completed'], true) && $balanceDue > 0): ?>
            <form method="POST" action="<?= e(url('billing/view.php?id=' . $invoiceId)) ?>" class="row g-2" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_payment">
                <div class="col-6 col-md-2">
                    <input type="number" step="0.01" min="0.01" max="<?= e(number_format($balanceDue, 2, '.', '')) ?>" name="amount" id="paymentAmountInput" class="form-control form-control-sm" placeholder="Amount" value="<?= e(number_format($balanceDue, 2, '.', '')) ?>" required>
                </div>
                <div class="col-6 col-md-2">
                    <input type="date" name="payment_date" class="form-control form-control-sm" value="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <div class="col-6 col-md-2">
                    <select name="payment_method" class="form-select form-select-sm">
                        <?php foreach (['bank_transfer','upi','cash','cheque','card','other'] as $m): ?>
                            <option value="<?= e($m) ?>"><?= e(ucwords(str_replace('_', ' ', $m))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <input type="text" name="reference" class="form-control form-control-sm" placeholder="Reference / UTR no.">
                </div>
                <div class="col-12 col-md-3">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Record Payment</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<style>
@media print {
    .no-print, .app-sidebar, .app-navbar, nav, .btn { display: none !important; }
    .app-content { margin: 0 !important; padding: 0 !important; }
    #paymentDetailsSection { page-break-inside: avoid; }
}
</style>

<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('printBtn')?.addEventListener('click', function () { window.print(); });
document.querySelector('.js-confirm-cancel')?.addEventListener('submit', function (e) {
    if (!confirm('Cancel this invoice?')) e.preventDefault();
});

// Live-clamp the payment amount to the remaining balance as the user
// types, instead of only capping it after the form is submitted.
(function () {
    var input = document.getElementById('paymentAmountInput');
    if (!input) { return; }
    var clamp = function () {
        var max = parseFloat(input.getAttribute('max'));
        var val = parseFloat(input.value);
        if (!isNaN(max) && !isNaN(val) && val > max) {
            input.value = max.toFixed(2);
        }
    };
    input.addEventListener('input', clamp);
    input.addEventListener('blur', clamp);
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
