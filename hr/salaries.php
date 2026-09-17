<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_role('super_admin', 'admin');

$currentUserId = (int) current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $data = [
        'id' => (int) ($_POST['salary_id'] ?? 0),
        'user_id' => (int) ($_POST['user_id'] ?? 0),
        'pay_month' => $_POST['pay_month'] ?? date('Y-m'),
        'basic_pay' => (float) ($_POST['basic_pay'] ?? 0),
        'allowances' => (float) ($_POST['allowances'] ?? 0),
        'deductions' => (float) ($_POST['deductions'] ?? 0),
        'status' => $_POST['status'] ?? 'pending',
        'paid_on' => $_POST['paid_on'] ?? null,
        'notes' => sanitize_string($_POST['notes'] ?? '') ?: null,
    ];

    if ($data['user_id'] <= 0) {
        flash_set('error', 'Please select an employee.');
        redirect('hr/salaries.php');
    }

    $savedId = save_salary_record($data, $currentUserId);
    audit_log($currentUserId, 'employees', $data['id'] > 0 ? 'salary_updated' : 'salary_created',
        ($data['id'] > 0 ? "Updated" : "Created") . " salary record #{$savedId} for user #{$data['user_id']} ({$data['pay_month']}).");
    flash_set('status', $data['id'] > 0 ? 'Salary record updated. The payslip download now reflects the corrected figures.' : 'Salary record saved.');
    redirect('hr/salaries.php?month=' . $data['pay_month']);
}

$viewMonth = $_GET['month'] ?? date('Y-m');
$records = get_salary_records($viewMonth);
$employees = db()->query("SELECT id, full_name FROM users WHERE status = 'active' ORDER BY full_name")->fetchAll();

// Edit flow — ?edit=<salary_id> pre-fills the Record/Update Salary form
// below with the existing values (see save_salary_record() in
// includes/hr.php, which UPDATEs in place when a salary_id is posted
// instead of inserting a duplicate).
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRecord = $editId > 0 ? find_salary_record($editId) : false;
if ($editId > 0 && $editRecord === false) {
    flash_set('error', 'Salary record not found.');
    redirect('hr/salaries.php?month=' . $viewMonth);
}

// Attendance-based calculator: pick an employee + month to see that
// month's attendance % and the suggested pay it works out to.
$calcUserId = isset($_GET['calc_user_id']) && $_GET['calc_user_id'] !== '' ? (int) $_GET['calc_user_id'] : null;
$calcMonth = $_GET['calc_month'] ?? $viewMonth;
$calcResult = $calcUserId !== null ? suggested_salary_for_month($calcUserId, $calcMonth) : null;

// Full pay history for the employee picked above — every month they've
// ever been paid, regardless of $viewMonth.
$calcEmployeeHistory = $calcUserId !== null ? get_salary_records(null, $calcUserId) : [];
$calcEmployeeName = null;
if ($calcUserId !== null) {
    foreach ($employees as $emp) {
        if ((int) $emp['id'] === $calcUserId) {
            $calcEmployeeName = $emp['full_name'];
            break;
        }
    }
}

$pageTitle = 'Salary';
$activeMenu = 'employees';
$breadcrumbs = [
    ['label' => 'Employees', 'url' => url('employees/index.php')],
    ['label' => 'Salary', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<h1 class="h4 mb-3">Salary</h1>

<div class="card mb-4">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Calculate from Attendance</h2></div>
    <div class="card-body">
        <form method="GET" action="<?= e(url('hr/salaries.php')) ?>" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="month" value="<?= e($viewMonth) ?>">
            <div class="col-12 col-md-5">
                <label class="form-label small">Employee</label>
                <select name="calc_user_id" class="form-select form-select-sm js-auto-submit">
                    <option value="">Select employee…</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= e((string) $emp['id']) ?>" <?= $calcUserId === (int) $emp['id'] ? 'selected' : '' ?>><?= e($emp['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-8 col-md-4">
                <label class="form-label small">Month</label>
                <input type="month" name="calc_month" class="form-control form-control-sm js-auto-submit" value="<?= e($calcMonth) ?>">
            </div>
            <div class="col-4 col-md-3">
                <button type="submit" class="btn btn-outline-primary btn-sm w-100">Check</button>
            </div>
        </form>

        <?php if ($calcResult !== null): ?>
            <?php $s = $calcResult['summary']; ?>
            <?php if ($calcResult['monthly_salary'] <= 0): ?>
                <div class="alert alert-danger small mb-3">
                    <strong><?= e($calcEmployeeName ?? 'This employee') ?> has no Monthly Salary set</strong> — that's why the suggested pay below shows ₹0.00.
                    Set it on their <a href="<?= e(url('employees/form.php?id=' . $calcUserId)) ?>" class="alert-link">Employee profile</a> first, then come back and check again.
                </div>
            <?php endif; ?>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered mb-0 text-center">
                    <thead class="table-light">
                        <tr><th>Present</th><th>Absent</th><th>Half Day</th><th>On Leave</th><th>Days in Month</th><th>Attendance %</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= e((string) $s['present']) ?></td>
                            <td><?= e((string) $s['absent']) ?></td>
                            <td><?= e((string) $s['half_day']) ?></td>
                            <td><?= e((string) $s['on_leave']) ?></td>
                            <td><?= e((string) $s['total_days_in_month']) ?></td>
                            <td class="fw-semibold"><?= e(number_format($s['percentage'], 2)) ?>%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="small text-muted">
                    Monthly Salary (100%): ₹<?= e(number_format($calcResult['monthly_salary'], 2)) ?>
                    &nbsp;→&nbsp;
                    <span class="fw-semibold text-dark">Suggested pay for <?= e($calcMonth) ?>: ₹<?= e(number_format($calcResult['suggested_amount'], 2)) ?></span>
                </div>
                <button type="button" id="useCalculatedBtn" class="btn btn-sm btn-success" <?= $calcResult['monthly_salary'] <= 0 ? 'disabled' : '' ?>
                        data-user-id="<?= e((string) $calcUserId) ?>"
                        data-month="<?= e($calcMonth) ?>"
                        data-amount="<?= e((string) $calcResult['suggested_amount']) ?>">
                    Use in Salary Form
                </button>
            </div>
        <?php else: ?>
            <p class="text-muted small mb-0">Pick an employee and month to see their attendance percentage and the salary it works out to.</p>
        <?php endif; ?>

        <?php if ($calcUserId !== null): ?>
            <hr class="my-3">
            <h3 class="h6 mb-2">Salary History — <?= e($calcEmployeeName ?? '') ?></h3>
            <p class="text-muted small mb-2">Every month this employee has a salary record for — when it was paid and how much.</p>
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead><tr><th>Pay Month</th><th class="text-end">Basic</th><th class="text-end">Net Pay</th><th>Status</th><th>Paid On</th></tr></thead>
                    <tbody>
                        <?php if (empty($calcEmployeeHistory)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No salary records for this employee yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($calcEmployeeHistory as $rec): ?>
                            <tr>
                                <td><?= e($rec['pay_month']) ?></td>
                                <td class="text-end">₹<?= e(number_format((float) $rec['basic_pay'], 2)) ?></td>
                                <td class="text-end">₹<?= e(number_format((float) $rec['net_pay'], 2)) ?></td>
                                <td><span class="badge <?= $rec['status'] === 'paid' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= e(ucfirst($rec['status'])) ?></span></td>
                                <td><?= e($rec['paid_on'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-5">
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0"><?= $editRecord ? 'Edit Salary — ' . e($editRecord['full_name']) : 'Record / Update Salary' ?></h2>
                <?php if ($editRecord): ?>
                    <a href="<?= e(url('hr/salaries.php?month=' . $viewMonth)) ?>" class="small text-decoration-none">Cancel edit</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($editRecord): ?>
                    <div class="alert alert-info small py-2">
                        Editing salary record #<?= e((string) $editRecord['id']) ?>. Saving will correct this record in place —
                        the <a href="<?= e(url('hr/payslip.php?id=' . $editRecord['id'])) ?>" target="_blank" class="alert-link">payslip download</a>
                        will reflect the updated figures immediately.
                    </div>
                <?php endif; ?>
                <form method="POST" action="<?= e(url('hr/salaries.php')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="salary_id" value="<?= e((string) ($editRecord['id'] ?? 0)) ?>">
                    <div class="mb-2">
                        <label class="form-label small">Employee *</label>
                        <select name="user_id" id="salaryFormUserId" class="form-select form-select-sm" required>
                            <option value="">Select…</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= e((string) $emp['id']) ?>" <?= $editRecord && (int) $editRecord['user_id'] === (int) $emp['id'] ? 'selected' : '' ?>><?= e($emp['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Pay Month *</label>
                        <input type="month" name="pay_month" id="salaryFormPayMonth" class="form-control form-control-sm" value="<?= e($editRecord['pay_month'] ?? $viewMonth) ?>" required>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-4"><label class="form-label small">Basic (₹)</label><input type="number" step="0.01" name="basic_pay" id="salaryFormBasicPay" class="form-control form-control-sm" value="<?= e((string) ($editRecord['basic_pay'] ?? '')) ?>"></div>
                        <div class="col-4"><label class="form-label small">Allowances</label><input type="number" step="0.01" name="allowances" class="form-control form-control-sm" value="<?= e((string) ($editRecord['allowances'] ?? '')) ?>"></div>
                        <div class="col-4"><label class="form-label small">Deductions</label><input type="number" step="0.01" name="deductions" class="form-control form-control-sm" value="<?= e((string) ($editRecord['deductions'] ?? '')) ?>"></div>
                    </div>
                    <div class="form-text mb-2">Tip: use the "Calculate from Attendance" panel above to auto-fill Basic from that month's attendance %.</div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="pending" <?= ($editRecord['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="paid" <?= ($editRecord['status'] ?? '') === 'paid' ? 'selected' : '' ?>>Paid</option>
                            </select>
                        </div>
                        <div class="col-6"><label class="form-label small">Paid On</label><input type="date" name="paid_on" class="form-control form-control-sm" value="<?= e((string) ($editRecord['paid_on'] ?? '')) ?>"></div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100"><?= $editRecord ? 'Save Corrections' : 'Save' ?></button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Salary — <?= e($viewMonth) ?></h2>
                <form method="GET" action="<?= e(url('hr/salaries.php')) ?>">
                    <input type="month" name="month" class="form-control form-control-sm js-auto-submit" value="<?= e($viewMonth) ?>">
                </form>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead><tr><th>Employee</th><th class="text-end">Basic</th><th class="text-end">Net Pay</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                        <tbody>
                            <?php if (empty($records)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">No salary records for this month.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($records as $rec): ?>
                                <tr>
                                    <td><?= e($rec['full_name']) ?></td>
                                    <td class="text-end">₹<?= e(number_format((float) $rec['basic_pay'], 2)) ?></td>
                                    <td class="text-end">₹<?= e(number_format((float) $rec['net_pay'], 2)) ?></td>
                                    <td><span class="badge <?= $rec['status'] === 'paid' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= e(ucfirst($rec['status'])) ?></span></td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <a href="<?= e(url('hr/salaries.php?month=' . $viewMonth . '&edit=' . $rec['id'])) ?>"
                                               class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                                            <a href="<?= e(url('hr/payslip.php?id=' . $rec['id'])) ?>" target="_blank"
                                               class="btn btn-sm btn-outline-primary" title="Download"><i class="bi bi-download"></i></a>
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
document.querySelectorAll('.js-auto-submit').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
});

var useBtn = document.getElementById('useCalculatedBtn');
if (useBtn) {
    useBtn.addEventListener('click', function () {
        var userSelect = document.getElementById('salaryFormUserId');
        var monthInput = document.getElementById('salaryFormPayMonth');
        var basicInput = document.getElementById('salaryFormBasicPay');
        if (userSelect) userSelect.value = useBtn.dataset.userId;
        if (monthInput) monthInput.value = useBtn.dataset.month;
        if (basicInput) basicInput.value = useBtn.dataset.amount;
        var form = basicInput ? basicInput.closest('form') : null;
        if (form) form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
