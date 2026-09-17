<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$quotationId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$quotation = $quotationId !== null ? find_quotation($quotationId) : null;

if ($quotationId !== null && $quotation === false) {
    flash_set('error', 'Quotation not found.');
    redirect('quotations/index.php');
}

require_permission('quotations', $quotationId !== null ? 'edit' : 'add');

$isEdit = $quotation !== null;

// Optional prefill when arriving from Lead -> Convert to Client ->
// Create Quotation (see leads/view.php). The client's own record
// already carries over the lead's name/company/contact (see
// convert_won_lead_to_client() in includes/leads.php) — reused here by
// simply preselecting that client, rather than re-copying the same
// fields a second time.
$prefillClientId = $isEdit ? (int) $quotation['client_id'] : (int) ($_GET['client_id'] ?? 0);
$prefillLeadId = $isEdit ? $quotation['lead_id'] : (($_GET['lead_id'] ?? '') !== '' ? (int) $_GET['lead_id'] : null);
$prefillLead = $prefillLeadId ? find_lead((int) $prefillLeadId) : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $gstApplicable = isset($_POST['gst_applicable']);
    $data = [
        'client_id' => (int) ($_POST['client_id'] ?? 0),
        'lead_id' => $isEdit ? ($quotation['lead_id'] ?? null) : ($prefillLeadId ?: null),
        'quotation_date' => $_POST['quotation_date'] ?? date('Y-m-d'),
        'valid_until' => ($_POST['valid_until'] ?? '') !== '' ? $_POST['valid_until'] : null,
        'project_title' => sanitize_string($_POST['project_title'] ?? ''),
        'website_type' => $_POST['website_type'] ?? 'other',
        'website_type_other' => sanitize_string($_POST['website_type_other'] ?? '') ?: null,
        'scope_description' => sanitize_string($_POST['scope_description'] ?? '') ?: null,
        'amount' => (float) ($_POST['amount'] ?? 0),
        'gst_applicable' => $gstApplicable,
        'gst_rate' => (float) ($_POST['gst_rate'] ?? QUOTATION_DEFAULT_GST_RATE),
        'payment_terms' => sanitize_string($_POST['payment_terms'] ?? '') ?: null,
        'terms_conditions' => sanitize_string($_POST['terms_conditions'] ?? '') ?: null,
        'notes' => sanitize_string($_POST['notes'] ?? '') ?: null,
        'status' => $_POST['status'] ?? 'draft',
    ];

    $error = null;
    if ($data['client_id'] <= 0 || find_client($data['client_id']) === false) {
        $error = 'Please select a valid client.';
    } elseif (trim($data['project_title']) === '') {
        $error = 'Please enter a project/website name.';
    } elseif (!array_key_exists($data['website_type'], quotation_website_type_options())) {
        $error = 'Invalid website/project type.';
    } elseif ($data['amount'] < 0) {
        $error = 'Amount cannot be negative.';
    } elseif (!in_array($data['status'], quotation_status_options(), true)) {
        $error = 'Invalid status.';
    }

    if ($error !== null) {
        flash_set('error', $error);
        redirect('quotations/form.php' . ($isEdit ? '?id=' . $quotationId : ('?' . http_build_query(['client_id' => $prefillClientId, 'lead_id' => $prefillLeadId]))));
    }

    $currentUserId = (int) current_user()['id'];

    if ($isEdit) {
        update_quotation($quotationId, $data);
        audit_log($currentUserId, 'quotations', 'update', "Updated quotation #{$quotationId}.");
        flash_set('status', 'Quotation updated successfully.');
        redirect('quotations/view.php?id=' . $quotationId);
    }

    $newId = create_quotation($data, $currentUserId);
    audit_log($currentUserId, 'quotations', 'create', "Created quotation #{$newId}.");
    flash_set('status', 'Quotation created successfully.');
    redirect('quotations/view.php?id=' . $newId);
}

$clients = list_active_clients_for_select();

$pageTitle = $isEdit ? 'Edit Quotation' : 'New Quotation';
$activeMenu = 'quotations';
$breadcrumbs = [
    ['label' => 'Quotations', 'url' => url('quotations/index.php')],
    ['label' => $isEdit ? 'Edit' : 'New', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="card">
    <div class="card-header bg-white"><h2 class="h6 mb-0"><?= e($pageTitle) ?></h2></div>
    <div class="card-body">
        <?php if (empty($clients) && !$isEdit): ?>
            <div class="alert alert-warning">
                You need at least one active client before creating a quotation.
                <a href="<?= e(url('clients/form.php')) ?>">Add a client first</a>.
            </div>
        <?php endif; ?>
        <?php if ($prefillLead !== false): ?>
            <div class="alert alert-info small">
                Prefilled from lead <strong><?= e($prefillLead['client_name']) ?></strong> — client details are
                already pulled from the Client record created from this lead.
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= e(url('quotations/form.php' . ($isEdit ? '?id=' . $quotationId : ''))) ?>" novalidate id="quotationForm">
            <?= csrf_field() ?>

            <div class="row g-3 mb-3">
                <div class="col-12 col-md-4">
                    <label class="form-label">Client *</label>
                    <select name="client_id" class="form-select" required>
                        <option value="">Select client…</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= e((string) $c['id']) ?>" <?= $prefillClientId === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= e($c['company_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Quotation Date</label>
                    <input type="date" name="quotation_date" class="form-control" value="<?= e($quotation['quotation_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Valid Until</label>
                    <input type="date" name="valid_until" class="form-control" value="<?= e($quotation['valid_until'] ?? date('Y-m-d', strtotime('+15 days'))) ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (quotation_status_options() as $s): ?>
                            <option value="<?= e($s) ?>" <?= ($quotation['status'] ?? 'draft') === $s ? 'selected' : '' ?>><?= e(ucwords($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <hr>
            <h3 class="h6">Project Details</h3>
            <div class="row g-3 mb-3">
                <div class="col-12 col-md-6">
                    <label class="form-label">Project / Website Name *</label>
                    <input type="text" name="project_title" id="projectTitleInput" class="form-control"
                           value="<?= e($quotation['project_title'] ?? ($prefillLead['project_type'] ?? '')) ?>" required>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">Website / Project Type *</label>
                    <select name="website_type" id="websiteTypeSelect" class="form-select" required>
                        <?php foreach (quotation_website_type_options() as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($quotation['website_type'] ?? 'other') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3" id="websiteTypeOtherWrap">
                    <label class="form-label">Type Label (if "Other")</label>
                    <input type="text" name="website_type_other" class="form-control" value="<?= e($quotation['website_type_other'] ?? '') ?>" placeholder="e.g. Mobile App">
                </div>
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <label class="form-label mb-0">Project Scope / Features / Implementation</label>
                    <button type="button" class="btn btn-link btn-sm p-0" id="insertScopeDefault">Insert default for this type</button>
                </div>
                <textarea name="scope_description" id="scopeTextarea" class="form-control" rows="6"><?= e($quotation['scope_description'] ?? (QUOTATION_DEFAULT_SCOPE_BY_TYPE[$quotation['website_type'] ?? 'other'] ?? '')) ?></textarea>
                <div class="form-text">Editable — adjust to the actual agreed scope for this project.</div>
            </div>

            <hr>
            <h3 class="h6">Pricing</h3>
            <div class="row g-3 mb-3 align-items-end">
                <div class="col-6 col-md-3">
                    <label class="form-label">Project Amount (₹) *</label>
                    <input type="number" step="0.01" min="0" name="amount" id="amountInput" class="form-control" value="<?= e((string) ($quotation['amount'] ?? '')) ?>" required>
                </div>
                <div class="col-6 col-md-3">
                    <div class="form-check mt-4 pt-1">
                        <input class="form-check-input" type="checkbox" name="gst_applicable" id="gstApplicableCheck" <?= ($quotation['gst_applicable'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="gstApplicableCheck">GST Applicable</label>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">GST Rate (%)</label>
                    <input type="number" step="0.01" min="0" max="100" name="gst_rate" id="gstRateInput" class="form-control" value="<?= e((string) ($quotation['gst_rate'] ?? QUOTATION_DEFAULT_GST_RATE)) ?>">
                </div>
                <div class="col-6 col-md-4">
                    <div class="small text-muted mb-0">Grand Total (approx.)</div>
                    <div class="fw-semibold" id="grandTotalPreview">₹<?= e(number_format((float) ($quotation['total_amount'] ?? 0), 2)) ?></div>
                </div>
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <label class="form-label mb-0">Payment Terms</label>
                    <button type="button" class="btn btn-link btn-sm p-0" id="insertPaymentDefault">Insert default (50% / 50%)</button>
                </div>
                <textarea name="payment_terms" id="paymentTermsTextarea" class="form-control" rows="2"><?= e($quotation['payment_terms'] ?? '') ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label">Terms &amp; Conditions</label>
                <textarea name="terms_conditions" class="form-control" rows="4"><?= e($quotation['terms_conditions'] ?? (!$isEdit ? QUOTATION_DEFAULT_TERMS : '')) ?></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label">Additional Notes (optional)</label>
                <textarea name="notes" class="form-control" rows="2"><?= e($quotation['notes'] ?? '') ?></textarea>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Quotation' ?></button>
                <a href="<?= e(url('quotations/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
var SCOPE_DEFAULTS = <?= json_encode(QUOTATION_DEFAULT_SCOPE_BY_TYPE, JSON_THROW_ON_ERROR) ?>;

function toggleWebsiteTypeOther() {
    var select = document.getElementById('websiteTypeSelect');
    var wrap = document.getElementById('websiteTypeOtherWrap');
    wrap.style.display = select.value === 'other' ? '' : 'none';
}
document.getElementById('websiteTypeSelect').addEventListener('change', toggleWebsiteTypeOther);
toggleWebsiteTypeOther();

document.getElementById('insertScopeDefault').addEventListener('click', function () {
    var type = document.getElementById('websiteTypeSelect').value;
    var textarea = document.getElementById('scopeTextarea');
    var defaultText = SCOPE_DEFAULTS[type] || '';
    if (textarea.value.trim() !== '' && !confirm('Replace the current scope text with the default for this type?')) {
        return;
    }
    textarea.value = defaultText;
});

function currentGrandTotal() {
    var amount = parseFloat(document.getElementById('amountInput').value) || 0;
    var gstApplicable = document.getElementById('gstApplicableCheck').checked;
    var gstRate = parseFloat(document.getElementById('gstRateInput').value) || 0;
    var gstAmount = gstApplicable ? Math.round(amount * gstRate) / 100 : 0;
    return Math.round((amount + gstAmount) * 100) / 100;
}

function refreshGrandTotalPreview() {
    document.getElementById('grandTotalPreview').textContent = '₹' + currentGrandTotal().toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
['amountInput', 'gstRateInput'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', refreshGrandTotalPreview);
});
document.getElementById('gstApplicableCheck').addEventListener('change', refreshGrandTotalPreview);

document.getElementById('insertPaymentDefault').addEventListener('click', function () {
    var total = currentGrandTotal();
    var advance = Math.round(total * 50) / 100;
    var balance = Math.round((total - advance) * 100) / 100;
    var fmt = function (n) { return n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var textarea = document.getElementById('paymentTermsTextarea');
    textarea.value = '50% Advance Payment: ₹' + fmt(advance) + '\nRemaining 50% Before Final Deployment: ₹' + fmt(balance);
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
