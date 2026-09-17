<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_role('super_admin', 'admin');

$currentUserId = (int) current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $editCertId = (int) ($_POST['certificate_id'] ?? 0);
    $userId = (int) ($_POST['user_id'] ?? 0);
    $type = $_POST['type'] ?? '';
    $employee = $userId > 0 ? find_user_by_id($userId) : false;

    if (!$employee) {
        flash_set('error', 'Please select an employee.');
        redirect('hr/certificates.php');
    }
    if (!array_key_exists($type, CERTIFICATE_TYPES)) {
        flash_set('error', 'Please select a certificate type.');
        redirect('hr/certificates.php');
    }

    if ($type === 'pay_slip') {
        $payMonth = $_POST['pay_month'] ?? date('Y-m');
        $details = [
            'pay_month' => $payMonth,
            'basic_pay' => (float) ($_POST['basic_pay'] ?? 0),
            'allowances' => (float) ($_POST['allowances'] ?? 0),
            'deductions' => (float) ($_POST['deductions'] ?? 0),
        ];
        $referenceLabel = $payMonth;
    } elseif ($type === 'offer_letter') {
        $details = [
            'designation' => sanitize_string($_POST['designation'] ?? ''),
            'department' => sanitize_string($_POST['department'] ?? ''),
            'joining_date' => $_POST['joining_date'] ?? null,
            'ctc' => sanitize_string($_POST['ctc'] ?? ''),
            'notes' => sanitize_string($_POST['notes'] ?? ''),
        ];
        $referenceLabel = $details['joining_date'] ?? null;
    } else { // experience_certificate
        $details = [
            'designation' => sanitize_string($_POST['designation'] ?? ''),
            'from_date' => $_POST['from_date'] ?? null,
            'to_date' => $_POST['to_date'] ?? null,
            'remarks' => sanitize_string($_POST['remarks'] ?? ''),
        ];
        $referenceLabel = ($details['from_date'] ?? '') . ' – ' . ($details['to_date'] ?? '');
    }

    if ($editCertId > 0) {
        update_certificate($editCertId, $referenceLabel, $details);
        audit_log($currentUserId, 'employees', 'certificate_updated', "Corrected {$type} certificate #{$editCertId} for user #{$userId}.");
        flash_set('status', CERTIFICATE_TYPES[$type] . ' updated. The download now reflects the corrected details.');
    } else {
        $newId = create_certificate($userId, $type, $referenceLabel, $details, $currentUserId);
        audit_log($currentUserId, 'employees', 'certificate_issued', "Issued {$type} for user #{$userId} (certificate #{$newId}).");
        flash_set('status', CERTIFICATE_TYPES[$type] . ' saved. You can download it from the list below.');
    }
    redirect('hr/certificates.php');
}

$employees = db()->query("SELECT id, full_name, department, designation FROM users WHERE role = 'employee' AND deleted_at IS NULL ORDER BY full_name")->fetchAll();
$recentCertificates = list_certificates(null, 30);

// Edit flow — ?edit=<certificate_id> pre-fills the form below with the
// certificate's current type/details (see update_certificate() in
// includes/certificates.php, which corrects the row in place).
$editCertId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editCertificate = $editCertId > 0 ? find_certificate($editCertId) : false;
if ($editCertId > 0 && $editCertificate === false) {
    flash_set('error', 'Certificate not found.');
    redirect('hr/certificates.php');
}
$editDetails = $editCertificate ? (json_decode((string) $editCertificate['details'], true) ?? []) : [];

// Fixed designation options for the Offer Letter / Experience Certificate
// forms — a locked dropdown, only these two titles can be selected.
$designationSuggestions = ['Software Developer', 'Graphic Designer'];

// Default text pre-filled for the notes/remarks fields. Kept as a plain
// default — fully editable, and the admin can change or clear it before saving.
$defaultOfferNotes = "This offer is subject to verification of documents and satisfactory background check. "
    . "Your employment will also be governed by the company's standard policies as amended from time to time.";
$defaultExperienceRemarks = "Conduct was found satisfactory throughout the tenure.";

$pageTitle = 'Certificates';
$activeMenu = 'employees';
$breadcrumbs = [
    ['label' => 'Employees', 'url' => url('employees/index.php')],
    ['label' => 'Certificates', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<h1 class="h4 mb-3">Certificates</h1>

<div class="row g-4">
    <div class="col-12 col-lg-5">
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0"><?= $editCertificate ? 'Edit Certificate — ' . e($editCertificate['full_name']) : 'Issue New Certificate' ?></h2>
                <?php if ($editCertificate): ?>
                    <a href="<?= e(url('hr/certificates.php')) ?>" class="small text-decoration-none">Cancel edit</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($editCertificate): ?>
                    <div class="alert alert-info small py-2">
                        Editing certificate #<?= e((string) $editCertificate['id']) ?>. Saving corrects this certificate in place —
                        the <a href="<?= e(url('hr/certificate-download.php?id=' . $editCertificate['id'])) ?>" target="_blank" class="alert-link">download</a>
                        will immediately reflect the corrected details.
                    </div>
                <?php endif; ?>
                <form method="POST" action="<?= e(url('hr/certificates.php')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="certificate_id" value="<?= e((string) ($editCertificate['id'] ?? 0)) ?>">

                    <div class="mb-2">
                        <label class="form-label small">Employee *</label>
                        <select name="user_id" class="form-select form-select-sm" required <?= $editCertificate ? 'disabled' : '' ?>>
                            <option value="">Select…</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= e((string) $emp['id']) ?>" <?= $editCertificate && (int) $editCertificate['user_id'] === (int) $emp['id'] ? 'selected' : '' ?>><?= e($emp['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($editCertificate): ?><input type="hidden" name="user_id" value="<?= e((string) $editCertificate['user_id']) ?>"><?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Certificate Type *</label>
                        <select name="type" id="certType" class="form-select form-select-sm" required <?= $editCertificate ? 'disabled' : '' ?>>
                            <option value="">Select…</option>
                            <?php foreach (CERTIFICATE_TYPES as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $editCertificate && $editCertificate['type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($editCertificate): ?><input type="hidden" name="type" value="<?= e($editCertificate['type']) ?>"><?php endif; ?>
                    </div>

                    <div class="cert-fields" data-type="pay_slip" style="<?= (!$editCertificate || $editCertificate['type'] !== 'pay_slip') ? 'display:none;' : '' ?>">
                        <div class="mb-2">
                            <label class="form-label small">Pay Month *</label>
                            <input type="month" name="pay_month" class="form-control form-control-sm" value="<?= e($editDetails['pay_month'] ?? date('Y-m')) ?>">
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-4"><label class="form-label small">Basic (₹)</label><input type="number" step="0.01" name="basic_pay" class="form-control form-control-sm" value="<?= e((string) ($editDetails['basic_pay'] ?? '')) ?>"></div>
                            <div class="col-4"><label class="form-label small">Allowances</label><input type="number" step="0.01" name="allowances" class="form-control form-control-sm" value="<?= e((string) ($editDetails['allowances'] ?? '')) ?>"></div>
                            <div class="col-4"><label class="form-label small">Deductions</label><input type="number" step="0.01" name="deductions" class="form-control form-control-sm" value="<?= e((string) ($editDetails['deductions'] ?? '')) ?>"></div>
                        </div>
                        <div class="form-text mb-2">Check the <a href="<?= e(url('hr/salaries.php')) ?>" target="_blank">Salary page</a> for this employee's exact figures.</div>
                    </div>

                    <div class="cert-fields" data-type="offer_letter" style="<?= (!$editCertificate || $editCertificate['type'] !== 'offer_letter') ? 'display:none;' : '' ?>">
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small">Designation</label>
                                <select name="designation" class="form-select form-select-sm">
                                    <option value="">Select…</option>
                                    <?php foreach ($designationSuggestions as $d): ?>
                                        <option value="<?= e($d) ?>" <?= ($editDetails['designation'] ?? '') === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6"><label class="form-label small">Department</label><input type="text" name="department" class="form-control form-control-sm" value="<?= e($editDetails['department'] ?? '') ?>"></div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label small">Joining Date</label><input type="date" name="joining_date" class="form-control form-control-sm" value="<?= e($editDetails['joining_date'] ?? '') ?>"></div>
                            <div class="col-6"><label class="form-label small">Annual CTC (₹)</label><input type="text" name="ctc" class="form-control form-control-sm" placeholder="e.g. 4,50,000" value="<?= e($editDetails['ctc'] ?? '') ?>"></div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">Additional Terms / Notes</label>
                            <textarea name="notes" class="form-control form-control-sm" rows="3"><?= e($editDetails['notes'] ?? $defaultOfferNotes) ?></textarea>
                            <div class="form-text">Default text — feel free to edit or clear it before saving.</div>
                        </div>
                    </div>

                    <div class="cert-fields" data-type="experience_certificate" style="<?= (!$editCertificate || $editCertificate['type'] !== 'experience_certificate') ? 'display:none;' : '' ?>">
                        <div class="mb-2">
                            <label class="form-label small">Designation Held</label>
                            <select name="designation" class="form-select form-select-sm">
                                <option value="">Select…</option>
                                <?php foreach ($designationSuggestions as $d): ?>
                                    <option value="<?= e($d) ?>" <?= ($editDetails['designation'] ?? '') === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label small">From Date</label><input type="date" name="from_date" class="form-control form-control-sm" value="<?= e($editDetails['from_date'] ?? '') ?>"></div>
                            <div class="col-6"><label class="form-label small">To Date</label><input type="date" name="to_date" class="form-control form-control-sm" value="<?= e($editDetails['to_date'] ?? '') ?>"></div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">Remarks</label>
                            <textarea name="remarks" class="form-control form-control-sm" rows="3"><?= e($editDetails['remarks'] ?? $defaultExperienceRemarks) ?></textarea>
                            <div class="form-text">Default text — feel free to edit or clear it before saving.</div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm w-100"><?= $editCertificate ? 'Save Corrections & Regenerate' : 'Save Certificate' ?></button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Recently Issued</h2></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead><tr><th>Employee</th><th>Type</th><th>Reference</th><th>Issued</th><th class="text-end">Action</th></tr></thead>
                        <tbody>
                            <?php if (empty($recentCertificates)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">No certificates issued yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($recentCertificates as $cert): ?>
                                <tr>
                                    <td><?= e($cert['full_name']) ?></td>
                                    <td><?= e(CERTIFICATE_TYPES[$cert['type']] ?? $cert['type']) ?></td>
                                    <td><?= e($cert['reference_label'] ?? 'Not Specified') ?></td>
                                    <td><?= e(date('d M Y', strtotime($cert['issued_at']))) ?></td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <a href="<?= e(url('hr/certificates.php?edit=' . $cert['id'])) ?>"
                                               class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                                            <a href="<?= e(url('hr/certificate-download.php?id=' . $cert['id'])) ?>" target="_blank"
                                               class="btn btn-sm btn-outline-primary"><i class="bi bi-download"></i> Download</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('certType').addEventListener('change', function () {
    var selected = this.value;
    document.querySelectorAll('.cert-fields').forEach(function (el) {
        el.style.display = el.dataset.type === selected ? '' : 'none';
    });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
