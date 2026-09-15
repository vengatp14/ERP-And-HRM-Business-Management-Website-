<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
// permission check happens below once we know if this is add or edit

$expenseId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$expense = $expenseId !== null ? find_expense($expenseId) : null;

if ($expenseId !== null && $expense === false) {
    flash_set('error', 'Expense not found.');
    redirect('accounts/expenses.php');
}

require_permission('accounts', $expenseId !== null ? 'edit' : 'add');

$isEdit = $expense !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $data = [
        'category' => sanitize_string($_POST['category'] ?? ''),
        'description' => sanitize_string($_POST['description'] ?? '') ?: null,
        'amount' => (float) ($_POST['amount'] ?? 0),
        'expense_date' => $_POST['expense_date'] ?? date('Y-m-d'),
        'payment_method' => $_POST['payment_method'] ?? 'bank_transfer',
        'vendor' => sanitize_string($_POST['vendor'] ?? '') ?: null,
        'reference' => sanitize_string($_POST['reference'] ?? '') ?: null,
        'project_id' => ($_POST['project_id'] ?? '') !== '' ? (int) $_POST['project_id'] : null,
    ];

    $error = validate_required($data['category'], 'Category');
    if ($error === null && $data['amount'] <= 0) {
        $error = 'Amount must be greater than zero.';
    }
    if ($error === null && !in_array($data['payment_method'], ['cash','bank_transfer','upi','cheque','card','other'], true)) {
        $error = 'Invalid payment method.';
    }

    if ($error !== null) {
        flash_set('error', $error);
        redirect('accounts/expense_form.php' . ($isEdit ? '?id=' . $expenseId : ''));
    }

    $currentUserId = (int) current_user()['id'];

    if ($isEdit) {
        update_expense($expenseId, $data);
        flash_set('status', 'Expense updated successfully.');
    } else {
        create_expense($data, $currentUserId);
        flash_set('status', 'Expense recorded successfully.');
    }
    redirect('accounts/expenses.php');
}

$categories = list_expense_categories();
$projects = db()->query("SELECT id, title FROM projects WHERE deleted_at IS NULL ORDER BY title ASC")->fetchAll();

$pageTitle = $isEdit ? 'Edit Expense' : 'Add Expense';
$activeMenu = 'accounts';
$breadcrumbs = [
    ['label' => 'Accounts', 'url' => url('accounts/index.php')],
    ['label' => 'Expenses', 'url' => url('accounts/expenses.php')],
    ['label' => $isEdit ? 'Edit' : 'Add', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="card">
    <div class="card-header bg-white"><h2 class="h6 mb-0"><?= e($pageTitle) ?></h2></div>
    <div class="card-body">
        <form method="POST" action="<?= e(url('accounts/expense_form.php' . ($isEdit ? '?id=' . $expenseId : ''))) ?>" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-6 col-md-4">
                    <label class="form-label">Category *</label>
                    <input type="text" name="category" class="form-control" list="categoryList" required value="<?= e($expense['category'] ?? '') ?>">
                    <datalist id="categoryList">
                        <?php foreach ($categories as $cat): ?><option value="<?= e($cat) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Amount (₹) *</label>
                    <input type="number" step="0.01" name="amount" class="form-control" required value="<?= e((string) ($expense['amount'] ?? '')) ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Expense Date</label>
                    <input type="date" name="expense_date" class="form-control" value="<?= e($expense['expense_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-select">
                        <?php foreach (['cash','bank_transfer','upi','cheque','card','other'] as $m): ?>
                            <option value="<?= e($m) ?>" <?= ($expense['payment_method'] ?? 'bank_transfer') === $m ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $m))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control" value="<?= e($expense['description'] ?? '') ?>">
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label">Vendor</label>
                    <input type="text" name="vendor" class="form-control" value="<?= e($expense['vendor'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label">Reference</label>
                    <input type="text" name="reference" class="form-control" value="<?= e($expense['reference'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label">Project (optional)</label>
                    <select name="project_id" class="form-select">
                        <option value="">— None —</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= e((string) $p['id']) ?>" <?= (int) ($expense['project_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Save Expense' ?></button>
                <a href="<?= e(url('accounts/expenses.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
