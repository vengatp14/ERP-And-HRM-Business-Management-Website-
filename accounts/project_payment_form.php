<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_role('super_admin', 'admin');

$planId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$plan = $planId !== null ? find_project_payment_plan($planId) : null;

if ($planId !== null && $plan === false) {
    flash_set('error', 'Payment plan not found.');
    redirect('accounts/project_payments.php');
}

$isEdit = $plan !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $paymentType = $_POST['payment_type'] ?? 'monthly';
    $data = [
        'project_id' => (int) ($_POST['project_id'] ?? 0),
        'payment_type' => $paymentType,
        'amount' => (float) ($_POST['amount'] ?? 0),
        'day_of_month' => $paymentType === 'monthly' ? (int) ($_POST['day_of_month'] ?? 1) : null,
        'due_date' => $paymentType === 'one_time' && ($_POST['due_date'] ?? '') !== '' ? $_POST['due_date'] : null,
        'payment_method' => $_POST['payment_method'] ?? 'bank_transfer',
        'reference' => sanitize_string($_POST['reference'] ?? '') ?: null,
        'notes' => sanitize_string($_POST['notes'] ?? '') ?: null,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    $error = null;
    if ($data['project_id'] <= 0 || find_project($data['project_id']) === false) {
        $error = 'Please select a valid project.';
    }
    if ($error === null && !in_array($paymentType, ['one_time', 'monthly'], true)) {
        $error = 'Invalid payment type.';
    }
    if ($error === null && $data['amount'] <= 0) {
        $error = 'Amount must be greater than zero.';
    }
    if ($error === null && $paymentType === 'monthly' && ($data['day_of_month'] < 1 || $data['day_of_month'] > 28)) {
        $error = 'Day of month must be between 1 and 28 (to keep it valid for every month).';
    }
    if ($error === null && $paymentType === 'one_time' && $data['due_date'] === null) {
        $error = 'Pick a due date for the one-time payment.';
    }
    if ($error === null && !$isEdit && find_project_payment_plan_by_project($data['project_id']) !== false) {
        $error = 'This project already has a payment plan — edit it instead of creating a second one.';
    }
    if ($error === null && !in_array($data['payment_method'], ['cash', 'bank_transfer', 'upi', 'cheque', 'card', 'other'], true)) {
        $error = 'Invalid payment method.';
    }

    if ($error !== null) {
        flash_set('error', $error);
        redirect('accounts/project_payment_form.php' . ($isEdit ? '?id=' . $planId : ''));
    }

    $currentUserId = (int) current_user()['id'];

    if ($isEdit) {
        update_project_payment_plan($planId, $data);
        flash_set('status', 'Payment plan updated successfully.');
    } else {
        create_project_payment_plan($data, $currentUserId);
        flash_set('status', 'Payment plan created. You\'ll get a reminder to confirm it when due.');
    }
    redirect('accounts/project_payments.php');
}

$projects = db()->query("SELECT id, title FROM projects WHERE deleted_at IS NULL ORDER BY title ASC")->fetchAll();

$pageTitle = $isEdit ? 'Edit Payment Plan' : 'New Payment Plan';
$activeMenu = 'accounts';
$breadcrumbs = [
    ['label' => 'Accounts', 'url' => url('accounts/index.php')],
    ['label' => 'Monthly Payments', 'url' => url('accounts/project_payments.php')],
    ['label' => $isEdit ? 'Edit' : 'New', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="card">
    <div class="card-header bg-white"><h2 class="h6 mb-0"><?= e($pageTitle) ?></h2></div>
    <div class="card-body">
        <p class="text-muted small">This does not add income immediately. When the due date arrives, you'll get a notification to confirm before it's added to Income — you can also skip a given month.</p>
        <form method="POST" action="<?= e(url('accounts/project_payment_form.php' . ($isEdit ? '?id=' . $planId : ''))) ?>" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label">Project *</label>
                    <select name="project_id" class="form-select" required <?= $isEdit ? 'disabled' : '' ?>>
                        <option value="">Select project…</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= e((string) $p['id']) ?>" <?= (int) ($plan['project_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
                                <?= e($p['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="project_id" value="<?= e((string) $plan['project_id']) ?>">
                        <div class="form-text">Project can't be changed after creation — delete and re-create if needed.</div>
                    <?php endif; ?>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Payment Type *</label>
                    <select name="payment_type" id="paymentType" class="form-select" required>
                        <option value="monthly" <?= ($plan['payment_type'] ?? 'monthly') === 'monthly' ? 'selected' : '' ?>>Monthly Payment</option>
                        <option value="one_time" <?= ($plan['payment_type'] ?? '') === 'one_time' ? 'selected' : '' ?>>One-Time Payment</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Amount (₹) *</label>
                    <input type="number" step="0.01" name="amount" class="form-control" required value="<?= e((string) ($plan['amount'] ?? '')) ?>">
                </div>

                <div class="col-6 col-md-3" id="dayWrap">
                    <label class="form-label">Day of Month *</label>
                    <input type="number" min="1" max="28" name="day_of_month" class="form-control" value="<?= e((string) ($plan['day_of_month'] ?? '1')) ?>">
                    <div class="form-text">1–28 (kept ≤28 so it always falls in every month).</div>
                </div>
                <div class="col-6 col-md-3" id="dueWrap" style="display:none;">
                    <label class="form-label">Due Date *</label>
                    <input type="date" name="due_date" class="form-control" value="<?= e($plan['due_date'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-select">
                        <?php foreach (['cash','bank_transfer','upi','cheque','card','other'] as $m): ?>
                            <option value="<?= e($m) ?>" <?= ($plan['payment_method'] ?? 'bank_transfer') === $m ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $m))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label">Reference</label>
                    <input type="text" name="reference" class="form-control" value="<?= e($plan['reference'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control" value="<?= e($plan['notes'] ?? '') ?>">
                </div>

                <div class="col-12">
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="isActive" name="is_active" <?= (!$isEdit || (int) ($plan['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="isActive">Active (send a reminder when due)</label>
                    </div>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Payment Plan' ?></button>
                <a href="<?= e(url('accounts/project_payments.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    var typeSelect = document.getElementById('paymentType');
    var dayWrap = document.getElementById('dayWrap');
    var dueWrap = document.getElementById('dueWrap');
    if (!typeSelect || !dayWrap || !dueWrap) { return; }
    function sync() {
        dayWrap.style.display = typeSelect.value === 'monthly' ? '' : 'none';
        dueWrap.style.display = typeSelect.value === 'one_time' ? '' : 'none';
    }
    typeSelect.addEventListener('change', sync);
    sync();
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
