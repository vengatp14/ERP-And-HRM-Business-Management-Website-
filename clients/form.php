<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
// permission check happens below once we know if this is add or edit

$clientId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$client = $clientId !== null ? find_client($clientId) : null;

if ($clientId !== null && $client === false) {
    flash_set('error', 'Client not found.');
    redirect('clients/index.php');
}

require_permission('clients', $clientId !== null ? 'edit' : 'add');

$isEdit = $client !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['branding_action'])) {
    csrf_verify_or_die();

    $data = [
        'company_name' => sanitize_string($_POST['company_name'] ?? ''),
        'contact_name' => sanitize_string($_POST['contact_name'] ?? ''),
        'mobile' => sanitize_string($_POST['mobile'] ?? ''),
        'whatsapp' => sanitize_string($_POST['whatsapp'] ?? '') ?: null,
        'email' => trim((string) ($_POST['email'] ?? '')) ?: null,
        'gstin' => strtoupper(sanitize_string($_POST['gstin'] ?? '')) ?: null,
        'pan_number' => strtoupper(sanitize_string($_POST['pan_number'] ?? '')) ?: null,
        'billing_address' => sanitize_string($_POST['billing_address'] ?? '') ?: null,
        'city' => sanitize_string($_POST['city'] ?? '') ?: null,
        'state' => sanitize_string($_POST['state'] ?? '') ?: null,
        'pincode' => sanitize_string($_POST['pincode'] ?? '') ?: null,
        'status' => $_POST['status'] ?? 'active',
        'notes' => sanitize_string($_POST['notes'] ?? '') ?: null,
        'next_contact_at' => trim((string) ($_POST['next_contact_at'] ?? '')) !== '' ? str_replace('T', ' ', $_POST['next_contact_at']) . ':00' : null,
        'assigned_to' => ($_POST['assigned_to'] ?? '') !== '' ? (int) $_POST['assigned_to'] : null,
    ];

    // Changing the next-contact date resets the contact status back to
    // pending — the same "new deadline, new chance" behaviour as a
    // lead's next_follow_up_at getting updated.
    if ($isEdit && $client !== false && ($client['next_contact_at'] ?? null) !== $data['next_contact_at']) {
        $data['contact_status'] = 'pending';
    }

    $error = validate_required($data['company_name'], 'Company name')
        ?? validate_required($data['contact_name'], 'Contact name')
        ?? validate_required($data['mobile'], 'Mobile number');

    if ($error === null && $data['email'] !== null && $data['email'] !== '') {
        $error = validate_email($data['email']);
    }

    if ($error === null && !in_array($data['status'], client_status_options(), true)) {
        $error = 'Invalid status.';
    }

    if ($error === null && $data['gstin'] !== null && !preg_match('/^[0-9A-Z]{15}$/', $data['gstin'])) {
        $error = 'GSTIN must be 15 characters (letters and numbers).';
    }

    if ($error !== null) {
        flash_set('error', $error);
        redirect('clients/form.php' . ($isEdit ? '?id=' . $clientId : ''));
    }

    $currentUserId = (int) current_user()['id'];

    if ($isEdit) {
        update_client($clientId, $data);
        add_client_note($clientId, $currentUserId, 'Client details updated.');
        flash_set('status', 'Client updated successfully.');
        redirect('clients/view.php?id=' . $clientId);
    }

    $newId = create_client($data, $currentUserId);
    add_client_note($newId, $currentUserId, 'Client created.');
    flash_set('status', 'Client created successfully.');
    redirect('clients/view.php?id=' . $newId);
}

// Branding uploads (logo/seal/signature) are handled on the edit page
// only, as a separate small form/request each — keeps the main details
// save simple, and avoids losing entered field values if only a file
// upload fails validation.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isEdit && isset($_POST['branding_action'])) {
    csrf_verify_or_die();
    require_permission('clients', 'edit');

    $type = (string) ($_POST['branding_type'] ?? '');

    if ($_POST['branding_action'] === 'remove' && in_array($type, CLIENT_BRANDING_TYPES, true)) {
        remove_client_branding_file($clientId, $type);
        flash_set('status', ucfirst($type) . ' removed.');
    }

    if ($_POST['branding_action'] === 'upload' && in_array($type, CLIENT_BRANDING_TYPES, true)) {
        if (!isset($_FILES['branding_file']) || ($_FILES['branding_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash_set('error', 'Choose a file to upload.');
            redirect('clients/form.php?id=' . $clientId);
        }
        $result = update_client_branding_file($clientId, $type, $_FILES['branding_file']);
        if ($result !== true) {
            flash_set('error', $result);
            redirect('clients/form.php?id=' . $clientId);
        }
        flash_set('status', ucfirst($type) . ' uploaded.');
    }

    redirect('clients/form.php?id=' . $clientId);
}

$pageTitle = $isEdit ? 'Edit Client' : 'Add Client';
$activeMenu = 'clients';
$breadcrumbs = [
    ['label' => 'Clients', 'url' => url('clients/index.php')],
    ['label' => $isEdit ? 'Edit' : 'Add', 'url' => null],
];

$employees = db()->query("SELECT id, full_name FROM users WHERE status = 'active' ORDER BY full_name")->fetchAll();

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="card">
    <div class="card-header bg-white"><h2 class="h6 mb-0"><?= e($pageTitle) ?></h2></div>
    <div class="card-body">
        <form method="POST" action="<?= e(url('clients/form.php' . ($isEdit ? '?id=' . $clientId : ''))) ?>" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label">Company Name *</label>
                    <input type="text" name="company_name" class="form-control" required
                           value="<?= e($client['company_name'] ?? '') ?>">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Contact Name *</label>
                    <input type="text" name="contact_name" class="form-control" required
                           value="<?= e($client['contact_name'] ?? '') ?>">
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">Mobile *</label>
                    <input type="tel" name="mobile" class="form-control" required value="<?= e($client['mobile'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">WhatsApp</label>
                    <input type="tel" name="whatsapp" class="form-control" value="<?= e($client['whatsapp'] ?? '') ?>">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= e($client['email'] ?? '') ?>">
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">GSTIN</label>
                    <input type="text" name="gstin" class="form-control text-uppercase" maxlength="15"
                           value="<?= e($client['gstin'] ?? '') ?>" placeholder="15-character GSTIN">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">PAN Number</label>
                    <input type="text" name="pan_number" class="form-control text-uppercase" value="<?= e($client['pan_number'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (client_status_options() as $s): ?>
                            <option value="<?= e($s) ?>" <?= ($client['status'] ?? 'active') === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label">Next Contact Date</label>
                    <input type="datetime-local" name="next_contact_at" class="form-control"
                           value="<?= e(!empty($client['next_contact_at']) ? str_replace(' ', 'T', substr($client['next_contact_at'], 0, 16)) : '') ?>">
                    <div class="form-text">Drives the calendar on the Clients list — shows up as missed if this passes before you mark contacted.</div>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label">Assign To</label>
                    <select name="assigned_to" class="form-select">
                        <option value="">— Unassigned —</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= e((string) $emp['id']) ?>" <?= (int) ($client['assigned_to'] ?? 0) === (int) $emp['id'] ? 'selected' : '' ?>>
                                <?= e($emp['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Only the assigned employee (plus admins) will see this client.</div>
                </div>

                <div class="col-12">
                    <label class="form-label">Billing Address</label>
                    <textarea name="billing_address" class="form-control" rows="2"><?= e($client['billing_address'] ?? '') ?></textarea>
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label">City</label>
                    <input type="text" name="city" class="form-control" value="<?= e($client['city'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label">State</label>
                    <input type="text" name="state" class="form-control" value="<?= e($client['state'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label">Pincode</label>
                    <input type="text" name="pincode" class="form-control" value="<?= e($client['pincode'] ?? '') ?>">
                </div>

                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"><?= e($client['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Client' ?></button>
                <a href="<?= e(url('clients/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php if ($isEdit): ?>
<div class="card mt-4">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Invoice Branding</h2>
        <div class="small text-muted">Optional. If uploaded, these appear on the client's side of the GST invoice print view, alongside the company's own logo/seal/signature.</div>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <?php foreach (['logo' => 'Logo', 'seal' => 'Seal', 'signature' => 'Signature'] as $type => $label): ?>
                <div class="col-12 col-md-4">
                    <div class="text-muted small mb-2"><?= e($label) ?></div>
                    <?php if (!empty($client["{$type}_stored_filename"])): ?>
                        <div class="mb-2">
                            <img src="<?= e(url('clients/branding-download.php?id=' . $clientId . '&type=' . $type)) ?>"
                                 alt="<?= e($label) ?>" style="max-height:90px;max-width:100%;object-fit:contain;" class="border rounded p-1 bg-white">
                        </div>
                        <form method="POST" action="<?= e(url('clients/form.php?id=' . $clientId)) ?>" class="d-inline js-confirm-remove">
                            <?= csrf_field() ?>
                            <input type="hidden" name="branding_action" value="remove">
                            <input type="hidden" name="branding_type" value="<?= e($type) ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">Remove</button>
                        </form>
                    <?php else: ?>
                        <div class="text-muted small mb-2 fst-italic">Not uploaded — this side of the invoice is left blank until added.</div>
                        <form method="POST" action="<?= e(url('clients/form.php?id=' . $clientId)) ?>" enctype="multipart/form-data" class="d-flex gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="branding_action" value="upload">
                            <input type="hidden" name="branding_type" value="<?= e($type) ?>">
                            <input type="file" name="branding_file" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp" required>
                            <button type="submit" class="btn btn-outline-primary btn-sm text-nowrap">Upload</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<script nonce="<?= e(csp_nonce()) ?>">
document.querySelectorAll('.js-confirm-remove').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!confirm('Remove this file?')) e.preventDefault(); });
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
