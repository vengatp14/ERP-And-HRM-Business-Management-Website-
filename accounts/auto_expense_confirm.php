<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_role('super_admin', 'admin');

$ruleId = (int) ($_GET['rule_id'] ?? $_POST['rule_id'] ?? 0);
$monthKey = (string) ($_GET['month'] ?? $_POST['month'] ?? current_month_key());

if (!preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
    flash_set('error', 'Invalid month.');
    redirect('accounts/recurring_expenses.php');
}

$rule = find_recurring_expense($ruleId);
if ($rule === false) {
    flash_set('error', 'Auto expense rule not found.');
    redirect('accounts/recurring_expenses.php');
}

$run = find_recurring_expense_run($ruleId, $monthKey);
if ($run === false) {
    flash_set('error', 'No pending confirmation found for this month.');
    redirect('accounts/recurring_expenses.php');
}

$currentUserId = (int) current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    if ($run['status'] !== 'pending') {
        flash_set('status', 'This month was already ' . e($run['status']) . '.');
        redirect('accounts/expenses.php');
    }

    if ($action === 'confirm') {
        $data = [
            'category' => $rule['category'],
            'description' => sanitize_string($_POST['description'] ?? (string) $rule['description']) ?: null,
            'amount' => (float) ($_POST['amount'] ?? $rule['amount']),
            'expense_date' => $_POST['expense_date'] ?? date('Y-m-d'),
            'payment_method' => $rule['payment_method'],
            'vendor' => $rule['vendor'],
            'reference' => $rule['reference'],
            'project_id' => $rule['project_id'] !== null ? (int) $rule['project_id'] : null,
        ];

        if ($data['amount'] <= 0) {
            flash_set('error', 'Amount must be greater than zero.');
            redirect('accounts/auto_expense_confirm.php?rule_id=' . $ruleId . '&month=' . $monthKey);
        }

        confirm_recurring_expense_run((int) $run['id'], $data, $currentUserId);
        flash_set('status', "Expense added for {$rule['category']} — ₹" . number_format($data['amount'], 2) . '.');
        redirect('accounts/expenses.php');
    }

    if ($action === 'skip') {
        skip_recurring_expense_run((int) $run['id'], $currentUserId);
        flash_set('status', 'Skipped for this month. You\'ll be asked again next month.');
        redirect('accounts/recurring_expenses.php');
    }
}

$pageTitle = 'Confirm Auto Expense';
$activeMenu = 'accounts';
$breadcrumbs = [
    ['label' => 'Accounts', 'url' => url('accounts/index.php')],
    ['label' => 'Auto Expenses', 'url' => url('accounts/recurring_expenses.php')],
    ['label' => 'Confirm', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="bi bi-arrow-repeat text-primary"></i> Monthly Auto Expense</h2>
            </div>
            <div class="card-body">
                <?php if ($run['status'] !== 'pending'): ?>
                    <div class="alert alert-info mb-0">
                        This month (<?= e($monthKey) ?>) for <strong><?= e($rule['category']) ?></strong> was already
                        <strong><?= e($run['status']) ?></strong> on <?= e((string) $run['resolved_at']) ?>.
                    </div>
                <?php else: ?>
                    <p class="text-muted">This recurring expense is due. Confirm to add it to <strong>Expenses</strong>, or skip it for this month — you can adjust the amount or date below before confirming.</p>

                    <dl class="row mb-4">
                        <dt class="col-4 col-sm-3">Category</dt><dd class="col-8 col-sm-9"><?= e($rule['category']) ?></dd>
                        <?php if (!empty($rule['vendor'])): ?><dt class="col-4 col-sm-3">Vendor</dt><dd class="col-8 col-sm-9"><?= e($rule['vendor']) ?></dd><?php endif; ?>
                        <?php if (!empty($rule['project_title'])): ?><dt class="col-4 col-sm-3">Project</dt><dd class="col-8 col-sm-9"><?= e($rule['project_title']) ?></dd><?php endif; ?>
                        <dt class="col-4 col-sm-3">Payment Method</dt><dd class="col-8 col-sm-9"><?= e(ucwords(str_replace('_', ' ', $rule['payment_method']))) ?></dd>
                        <dt class="col-4 col-sm-3">Month</dt><dd class="col-8 col-sm-9"><?= e($monthKey) ?></dd>
                    </dl>

                    <form method="POST" action="<?= e(url('accounts/auto_expense_confirm.php?rule_id=' . $ruleId . '&month=' . $monthKey)) ?>" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="rule_id" value="<?= e((string) $ruleId) ?>">
                        <input type="hidden" name="month" value="<?= e($monthKey) ?>">

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label">Amount (₹) *</label>
                                <input type="number" step="0.01" name="amount" class="form-control" required value="<?= e((string) $rule['amount']) ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Expense Date</label>
                                <input type="date" name="expense_date" class="form-control" value="<?= e(date('Y-m-d')) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <input type="text" name="description" class="form-control" value="<?= e($rule['description'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" name="action" value="confirm" class="btn btn-success"><i class="bi bi-check-lg"></i> Yes, Add Expense</button>
                            <button type="submit" name="action" value="skip" class="btn btn-outline-secondary" onclick="return confirm('Skip this recurring expense for <?= e($monthKey) ?>? You will be asked again next month.');"><i class="bi bi-x-lg"></i> Skip This Month</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
