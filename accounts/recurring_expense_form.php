<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_role('super_admin', 'admin');

$ruleId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$rule = $ruleId !== null ? find_recurring_expense($ruleId) : null;

if ($ruleId !== null && $rule === false) {
    flash_set('error', 'Auto expense rule not found.');
    redirect('accounts/recurring_expenses.php');
}

$isEdit = $rule !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $data = [
        'category' => sanitize_string($_POST['category'] ?? ''),
        'description' => sanitize_string($_POST['description'] ?? '') ?: null,
        'amount' => (float) ($_POST['amount'] ?? 0),
        'day_of_month' => (int) ($_POST['day_of_month'] ?? 1),
        'payment_method' => $_POST['payment_method'] ?? 'bank_transfer',
        'vendor' => sanitize_string($_POST['vendor'] ?? '') ?: null,
        'reference' => sanitize_string($_POST['reference'] ?? '') ?: null,
        'project_id' => ($_POST['project_id'] ?? '') !== '' ? (int) $_POST['project_id'] : null,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    $error = validate_required($data['category'], 'Category');
    if ($error === null && $data['amount'] <= 0) {
        $error = 'Amount must be greater than zero.';
    }
    if ($error === null && ($data['day_of_month'] < 1 || $data['day_of_month'] > 28)) {
        $error = 'Day of month must be between 1 and 28 (to keep it valid for every month).';
    }
    if ($error === null && !in_array($data['payment_method'], ['cash','bank_transfer','upi','cheque','card','other'], true)) {
        $error = 'Invalid payment method.';
    }

    if ($error !== null) {
        flash_set('error', $error);
        redirect('accounts/recurring_expense_form.php' . ($isEdit ? '?id=' . $ruleId : ''));
    }

    $currentUserId = (int) current_user()['id'];

    if ($isEdit) {
        update_recurring_expense($ruleId, $data);
        flash_set('status', 'Auto expense updated successfully.');
    } else {
        create_recurring_expense($data, $currentUserId);
        flash_set('status', 'Auto expense created. You\'ll get a monthly reminder to confirm it.');
    }
    redirect('accounts/recurring_expenses.php');
}

$categories = list_expense_categories();
$projects = db()->query("SELECT id, title FROM projects WHERE deleted_at IS NULL ORDER BY title ASC")->fetchAll();

$pageTitle = $isEdit ? 'Edit Auto Expense' : 'New Auto Expense';
$activeMenu = 'accounts';
$breadcrumbs = [
    ['label' => 'Accounts', 'url' => url('accounts/index.php')],
    ['label' => 'Auto Expenses', 'url' => url('accounts/recurring_expenses.php')],
    ['label' => $isEdit ? 'Edit' : 'New', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="card">
    <div class="card-header bg-white"><h2 class="h6 mb-0"><?= e($pageTitle) ?></h2></div>
    <div class="card-body">
        <p class="text-muted small">This does not add an expense immediately. Every month, once the day below arrives, you'll get a notification to confirm before it's added to Expenses — you can also skip a given month.</p>
        <form method="POST" action="<?= e(url('accounts/recurring_expense_form.php' . ($isEdit ? '?id=' . $ruleId : ''))) ?>" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-6 col-md-4">
                    <label class="form-label">Category *</label>
                    <input type="text" name="category" class="form-control" list="categoryList" required value="<?= e($rule['category'] ?? '') ?>">
                    <datalist id="categoryList">
                        <?php foreach ($categories as $cat): ?><option value="<?= e($cat) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Default Amount (₹) *</label>
                    <input type="number" step="0.01" name="amount" class="form-control" required value="<?= e((string) ($rule['amount'] ?? '')) ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Day of Month *</label>
                    <input type="number" min="1" max="28" name="day_of_month" class="form-control" required value="<?= e((string) ($rule['day_of_month'] ?? '1')) ?>">
                    <div class="form-text">1–28 (kept ≤28 so it always falls in every month).</div>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-select">
                        <?php foreach (['cash','bank_transfer','upi','cheque','card','other'] as $m): ?>
                            <option value="<?= e($m) ?>" <?= ($rule['payment_method'] ?? 'bank_transfer') === $m ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $m))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control" value="<?= e($rule['description'] ?? '') ?>">
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label">Vendor</label>
                    <input type="text" name="vendor" class="form-control" value="<?= e($rule['vendor'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label">Reference</label>
                    <input type="text" name="reference" class="form-control" value="<?= e($rule['reference'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label">Project (optional)</label>
                    <select name="project_id" class="form-select">
                        <option value="">— None —</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= e((string) $p['id']) ?>" <?= (int) ($rule['project_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="isActive" name="is_active" <?= (!$isEdit || (int) ($rule['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="isActive">Active (send a monthly confirm reminder)</label>
                    </div>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Auto Expense' ?></button>
                <a href="<?= e(url('accounts/recurring_expenses.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
