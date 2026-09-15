<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_role('super_admin', 'admin');

$currentUserId = (int) current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

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

    $newId = create_certificate($userId, $type, $referenceLabel, $details, $currentUserId);
    audit_log($currentUserId, 'employees', 'certificate_issued', "Issued {$type} for user #{$userId} (certificate #{$newId}).");
    flash_set('status', CERTIFICATE_TYPES[$type] . ' saved. You can download it from the list below.');
    redirect('hr/certificates.php');
}

$employees = db()->query("SELECT id, full_name, department, designation FROM users WHERE role = 'employee' AND deleted_at IS NULL ORDER BY full_name")->fetchAll();
$recentCertificates = list_certificates(null, 30);

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
            <div class="card-header bg-white"><h2 class="h6 mb-0">Issue New Certificate</h2></div>
            <div class="card-body">
                <form method="POST" action="<?= e(url('hr/certificates.php')) ?>" novalidate>
                    <?= csrf_field() ?>

                    <div class="mb-2">
                        <label class="form-label small">Employee *</label>
                        <select name="user_id" class="form-select form-select-sm" required>
                            <option value="">Select…</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= e((string) $emp['id']) ?>"><?= e($emp['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Certificate Type *</label>
                        <select name="type" id="certType" class="form-select form-select-sm" required>
                            <option value="">Select…</option>
                            <?php foreach (CERTIFICATE_TYPES as $key => $label): ?>
                                <option value="<?= e($key) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="cert-fields" data-type="pay_slip" style="display:none;">
                        <div class="mb-2">
                            <label class="form-label small">Pay Month *</label>
                            <input type="month" name="pay_month" class="form-control form-control-sm" value="<?= e(date('Y-m')) ?>">
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-4"><label class="form-label small">Basic (₹)</label><input type="number" step="0.01" name="basic_pay" class="form-control form-control-sm"></div>
                            <div class="col-4"><label class="form-label small">Allowances</label><input type="number" step="0.01" name="allowances" class="form-control form-control-sm"></div>
                            <div class="col-4"><label class="form-label small">Deductions</label><input type="number" step="0.01" name="deductions" class="form-control form-control-sm"></div>
                        </div>
                        <div class="form-text mb-2">Check the <a href="<?= e(url('hr/salaries.php')) ?>" target="_blank">Salary page</a> for this employee's exact figures.</div>
                    </div>

                    <div class="cert-fields" data-type="offer_letter" style="display:none;">
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label small">Designation</label>
                                <select name="designation" class="form-select form-select-sm">
                                    <option value="">Select…</option>
                                    <?php foreach ($designationSuggestions as $d): ?>
                                        <option value="<?= e($d) ?>"><?= e($d) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6"><label class="form-label small">Department</label><input type="text" name="department" class="form-control form-control-sm"></div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label small">Joining Date</label><input type="date" name="joining_date" class="form-control form-control-sm"></div>
                            <div class="col-6"><label class="form-label small">Annual CTC (₹)</label><input type="text" name="ctc" class="form-control form-control-sm" placeholder="e.g. 4,50,000"></div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">Additional Terms / Notes</label>
                            <textarea name="notes" class="form-control form-control-sm" rows="3"><?= e($defaultOfferNotes) ?></textarea>
                            <div class="form-text">Default text — feel free to edit or clear it before saving.</div>
                        </div>
                    </div>

                    <div class="cert-fields" data-type="experience_certificate" style="display:none;">
                        <div class="mb-2">
                            <label class="form-label small">Designation Held</label>
                            <select name="designation" class="form-select form-select-sm">
                                <option value="">Select…</option>
                                <?php foreach ($designationSuggestions as $d): ?>
                                    <option value="<?= e($d) ?>"><?= e($d) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label small">From Date</label><input type="date" name="from_date" class="form-control form-control-sm"></div>
                            <div class="col-6"><label class="form-label small">To Date</label><input type="date" name="to_date" class="form-control form-control-sm"></div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">Remarks</label>
                            <textarea name="remarks" class="form-control form-control-sm" rows="3"><?= e($defaultExperienceRemarks) ?></textarea>
                            <div class="form-text">Default text — feel free to edit or clear it before saving.</div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm w-100">Save Certificate</button>
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
                                    <td><?= e($cert['reference_label'] ?? '—') ?></td>
                                    <td><?= e(date('d M Y', strtotime($cert['issued_at']))) ?></td>
                                    <td class="text-end">
                                        <a href="<?= e(url('hr/certificate-download.php?id=' . $cert['id'])) ?>" target="_blank"
                                           class="btn btn-sm btn-outline-primary"><i class="bi bi-download"></i> Download</a>
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
