<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_role('super_admin', 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $type = (string) ($_POST['branding_type'] ?? '');
    $action = (string) ($_POST['branding_action'] ?? '');

    if ($action === 'update_text') {
        update_company_name_address(
            sanitize_string($_POST['company_name'] ?? ''),
            sanitize_string($_POST['company_address'] ?? ''),
            sanitize_string($_POST['company_gstin'] ?? ''),
            sanitize_string($_POST['company_mobile'] ?? '')
        );
        flash_set('status', 'Company details updated.');
        redirect('admin/company-branding.php');
    }

    if ($action === 'update_social') {
        $links = [];
        foreach (array_keys(COMPANY_SOCIAL_PLATFORMS) as $key) {
            $links[$key] = [
                'name' => sanitize_string($_POST['social'][$key]['name'] ?? ''),
                'url' => sanitize_string($_POST['social'][$key]['url'] ?? ''),
            ];
        }
        update_company_social_links($links);
        flash_set('status', 'Social media profiles updated. They will now show on printed/soft-copy bills.');
        redirect('admin/company-branding.php');
    }

    if ($action === 'update_bank') {
        update_company_bank_details([
            'account_name' => sanitize_string($_POST['bank_account_name'] ?? ''),
            'bank_name' => sanitize_string($_POST['bank_name'] ?? ''),
            'account_number' => sanitize_string($_POST['bank_account_number'] ?? ''),
            'ifsc' => sanitize_string($_POST['bank_ifsc'] ?? ''),
            'branch' => sanitize_string($_POST['bank_branch'] ?? ''),
            'other_details' => sanitize_string($_POST['bank_other_details'] ?? ''),
        ]);
        flash_set('status', 'Bank account details updated. They will now show in the Payment Details section of every generated bill.');
        redirect('admin/company-branding.php');
    }

    if ($action === 'add_qr') {
        if (!isset($_FILES['qr_file']) || ($_FILES['qr_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash_set('error', 'Choose a QR image to upload.');
            redirect('admin/company-branding.php');
        }
        $result = add_company_payment_qr((string) ($_POST['method_label'] ?? ''), $_FILES['qr_file']);
        if (!is_int($result)) {
            flash_set('error', $result);
            redirect('admin/company-branding.php');
        }
        flash_set('status', 'Payment QR added. It will now show on every generated bill.');
        redirect('admin/company-branding.php');
    }

    if ($action === 'remove_qr') {
        remove_company_payment_qr((int) ($_POST['qr_id'] ?? 0));
        flash_set('status', 'Payment QR removed.');
        redirect('admin/company-branding.php');
    }

    if (!in_array($type, COMPANY_BRANDING_TYPES, true)) {
        flash_set('error', 'Invalid branding type.');
        redirect('admin/company-branding.php');
    }

    if ($action === 'remove') {
        remove_company_branding_file($type);
        flash_set('status', ucfirst($type) . ' removed. Falling back to the default placeholder.');
        redirect('admin/company-branding.php');
    }

    if ($action === 'upload') {
        if (!isset($_FILES['branding_file']) || ($_FILES['branding_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash_set('error', 'Choose a file to upload.');
            redirect('admin/company-branding.php');
        }
        $result = update_company_branding_file($type, $_FILES['branding_file']);
        if ($result !== true) {
            flash_set('error', $result);
            redirect('admin/company-branding.php');
        }
        flash_set('status', ucfirst($type) . ' updated. It will now show on every GST bill.');
        redirect('admin/company-branding.php');
    }

    redirect('admin/company-branding.php');
}

$branding = get_company_branding();
$socialLinks = company_social_links();
$paymentQrs = list_company_payment_qrs();

$pageTitle = 'Company Branding';
$activeMenu = 'company_branding';
$breadcrumbs = [['label' => 'Company Branding', 'url' => null]];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="card mb-4">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Company Details</h2>
        <div class="small text-muted">
            Name, address, GST number, and mobile number shown on Pay Slips, Offer Letters, Experience
            Certificates, and GST bills. Until this is filled in, the app falls back to <?= e(COMPANY_NAME) ?>
            and the values in .env.
        </div>
    </div>
    <div class="card-body">
        <form method="POST" action="<?= e(url('admin/company-branding.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="branding_action" value="update_text">
            <div class="mb-2">
                <label class="form-label small">Company Name</label>
                <input type="text" name="company_name" class="form-control form-control-sm"
                       value="<?= e($branding['company_name'] ?? '') ?>" placeholder="<?= e(COMPANY_NAME) ?>">
            </div>
            <div class="mb-2">
                <label class="form-label small">Company Address</label>
                <textarea name="company_address" class="form-control form-control-sm" rows="2"
                          placeholder="<?= e(COMPANY_ADDRESS ?: 'e.g. No.12, Anna Salai, Coimbatore') ?>"><?= e($branding['company_address'] ?? '') ?></textarea>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-12 col-sm-6">
                    <label class="form-label small">GST No.</label>
                    <input type="text" name="company_gstin" class="form-control form-control-sm text-uppercase"
                           value="<?= e($branding['company_gstin'] ?? '') ?>"
                           placeholder="<?= e(COMPANY_GSTIN ?: 'e.g. 33AAAAA0000A1Z5') ?>" maxlength="20">
                </div>
                <div class="col-12 col-sm-6">
                    <label class="form-label small">Mobile Number</label>
                    <input type="text" name="company_mobile" class="form-control form-control-sm"
                           value="<?= e($branding['company_mobile'] ?? '') ?>"
                           placeholder="<?= e(COMPANY_MOBILE ?: 'e.g. +91 98765 43210') ?>" maxlength="20">
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Social Media Profiles</h2>
        <div class="small text-muted">
            Shown on printed/soft-copy GST bills (profile name only on the printed copy;
            clickable through to the URL below on the soft copy). Leave a platform blank to
            keep it off the bill entirely.
        </div>
    </div>
    <div class="card-body">
        <form method="POST" action="<?= e(url('admin/company-branding.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="branding_action" value="update_social">
            <?php foreach (COMPANY_SOCIAL_PLATFORMS as $key => $meta): ?>
                <div class="row g-2 mb-2 align-items-center">
                    <div class="col-12 col-sm-2">
                        <label class="form-label small mb-0"><i class="bi <?= e($meta['icon']) ?>"></i> <?= e($meta['label']) ?></label>
                    </div>
                    <div class="col-12 col-sm-4">
                        <input type="text" name="social[<?= e($key) ?>][name]" class="form-control form-control-sm"
                               value="<?= e($socialLinks[$key]['name'] ?? '') ?>" placeholder="Profile name, e.g. companyname">
                    </div>
                    <div class="col-12 col-sm-6">
                        <input type="url" name="social[<?= e($key) ?>][url]" class="form-control form-control-sm"
                               value="<?= e($socialLinks[$key]['url'] ?? '') ?>" placeholder="<?= e($meta['placeholder']) ?>">
                    </div>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary btn-sm mt-1">Save</button>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Bank Account Details</h2>
        <div class="small text-muted">
            Shown in the Payment Details section of every generated bill (see GST Billing).
            Leave a field blank to leave it off the bill. Use exactly the information your
            bank has provided — nothing here is assumed or invented.
        </div>
    </div>
    <div class="card-body">
        <form method="POST" action="<?= e(url('admin/company-branding.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="branding_action" value="update_bank">
            <div class="row g-2 mb-2">
                <div class="col-12 col-sm-6">
                    <label class="form-label small">Account Name</label>
                    <input type="text" name="bank_account_name" class="form-control form-control-sm"
                           value="<?= e($branding['bank_account_name'] ?? '') ?>" placeholder="e.g. Your Company Pvt Ltd">
                </div>
                <div class="col-12 col-sm-6">
                    <label class="form-label small">Bank Name</label>
                    <input type="text" name="bank_name" class="form-control form-control-sm"
                           value="<?= e($branding['bank_name'] ?? '') ?>" placeholder="e.g. Punjab National Bank">
                </div>
            </div>
            <div class="row g-2 mb-2">
                <div class="col-12 col-sm-4">
                    <label class="form-label small">Account Number</label>
                    <input type="text" name="bank_account_number" class="form-control form-control-sm"
                           value="<?= e($branding['bank_account_number'] ?? '') ?>">
                </div>
                <div class="col-12 col-sm-4">
                    <label class="form-label small">IFSC</label>
                    <input type="text" name="bank_ifsc" class="form-control form-control-sm text-uppercase"
                           value="<?= e($branding['bank_ifsc'] ?? '') ?>">
                </div>
                <div class="col-12 col-sm-4">
                    <label class="form-label small">Branch</label>
                    <input type="text" name="bank_branch" class="form-control form-control-sm"
                           value="<?= e($branding['bank_branch'] ?? '') ?>">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small">Other Payment Details (optional)</label>
                <input type="text" name="bank_other_details" class="form-control form-control-sm"
                       value="<?= e($branding['bank_other_details'] ?? '') ?>" placeholder="e.g. Swift code, secondary account, etc.">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Payment QR Codes</h2>
        <div class="small text-muted">
            Add one or more UPI/payment QR codes (Google Pay, PhonePe, a business UPI QR, etc.).
            Every configured QR shows on every generated bill's Payment Details section — remove one to
            take it off future bills. Use only the actual QR image(s) provided; nothing here is generated.
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($paymentQrs)): ?>
            <div class="text-muted small fst-italic mb-3">No payment QR configured yet.</div>
        <?php else: ?>
            <div class="row g-3 mb-3">
                <?php foreach ($paymentQrs as $qr): ?>
                    <div class="col-6 col-sm-4 col-md-3">
                        <div class="border rounded p-2 text-center h-100 d-flex flex-column">
                            <img src="<?= e(url('admin/company-payment-qr-download.php?id=' . $qr['id'])) ?>" alt="<?= e($qr['method_label']) ?>"
                                 class="mb-2" style="max-height:140px;max-width:100%;object-fit:contain;">
                            <div class="small fw-semibold mb-2 flex-grow-1"><?= e($qr['method_label']) ?></div>
                            <form method="POST" action="<?= e(url('admin/company-branding.php')) ?>" class="js-confirm-remove-qr">
                                <?= csrf_field() ?>
                                <input type="hidden" name="branding_action" value="remove_qr">
                                <input type="hidden" name="qr_id" value="<?= e((string) $qr['id']) ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm w-100">Remove</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="POST" action="<?= e(url('admin/company-branding.php')) ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="branding_action" value="add_qr">
            <div class="col-12 col-sm-4">
                <label class="form-label small">Payment Method</label>
                <input type="text" name="method_label" class="form-control form-control-sm" placeholder="e.g. Google Pay" required>
            </div>
            <div class="col-12 col-sm-5">
                <label class="form-label small">QR Image</label>
                <input type="file" name="qr_file" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp" required>
            </div>
            <div class="col-12 col-sm-3">
                <button type="submit" class="btn btn-primary btn-sm w-100">Add QR</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Company Branding</h2>
        <div class="small text-muted">
            Logo, seal, and signature shown on every GST bill (<?= e(company_name()) ?>'s side of the invoice).
            Upload here to change them — no file replacement or code changes needed. Until something is
            uploaded, the bundled placeholder image is used so bills still print correctly.
        </div>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <?php foreach (['logo' => 'Logo', 'seal' => 'Seal', 'signature' => 'Signature'] as $type => $label): ?>
                <div class="col-12 col-md-4">
                    <div class="text-muted small mb-2"><?= e($label) ?></div>
                    <div class="mb-2">
                        <?php $previewUrl = company_branding_url($type); ?>
                        <?php if ($previewUrl): ?>
                            <img src="<?= e($previewUrl) ?>" alt="<?= e($label) ?>" style="max-height:100px;max-width:100%;object-fit:contain;" class="border rounded p-2 bg-white">
                        <?php else: ?>
                            <div class="text-muted small fst-italic">No image.</div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($branding["{$type}_stored_filename"])): ?>
                        <div class="small text-success mb-2"><i class="bi bi-check-circle"></i> Custom <?= e(strtolower($label)) ?> in use</div>
                        <form method="POST" action="<?= e(url('admin/company-branding.php')) ?>" class="d-inline js-confirm-remove">
                            <?= csrf_field() ?>
                            <input type="hidden" name="branding_action" value="remove">
                            <input type="hidden" name="branding_type" value="<?= e($type) ?>">
                            <button type="submit" class="btn btn-outline-secondary btn-sm">Remove (revert to default)</button>
                        </form>
                    <?php else: ?>
                        <div class="small text-muted mb-2 fst-italic">Using the bundled default placeholder.</div>
                    <?php endif; ?>
                    <form method="POST" action="<?= e(url('admin/company-branding.php')) ?>" enctype="multipart/form-data" class="d-flex flex-column flex-sm-row gap-2 mt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="branding_action" value="upload">
                        <input type="hidden" name="branding_type" value="<?= e($type) ?>">
                        <input type="file" name="branding_file" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp" required>
                        <button type="submit" class="btn btn-primary btn-sm text-nowrap"><?= !empty($branding["{$type}_stored_filename"]) ? 'Replace' : 'Upload' ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
document.querySelectorAll('.js-confirm-remove').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!confirm('Remove this and revert to the default placeholder?')) e.preventDefault(); });
});
document.querySelectorAll('.js-confirm-remove-qr').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!confirm('Remove this payment QR? It will no longer show on bills.')) e.preventDefault(); });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>