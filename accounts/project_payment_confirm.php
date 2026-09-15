<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_role('super_admin', 'admin');

$planId = (int) ($_GET['plan_id'] ?? $_POST['plan_id'] ?? 0);
$monthKey = (string) ($_GET['month'] ?? $_POST['month'] ?? current_month_key());

if (!preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
    flash_set('error', 'Invalid month.');
    redirect('accounts/project_payments.php');
}

$plan = find_project_payment_plan($planId);
if ($plan === false) {
    flash_set('error', 'Payment plan not found.');
    redirect('accounts/project_payments.php');
}

$run = find_project_payment_plan_run($planId, $monthKey);
if ($run === false) {
    flash_set('error', 'No pending confirmation found for this period.');
    redirect('accounts/project_payments.php');
}

$currentUserId = (int) current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    if ($run['status'] !== 'pending') {
        flash_set('status', 'This period was already ' . e($run['status']) . '.');
        redirect('accounts/project_payments.php');
    }

    if ($action === 'confirm') {
        $incomeData = [
            'amount' => (float) ($_POST['amount'] ?? $plan['amount']),
            'payment_date' => $_POST['payment_date'] ?? date('Y-m-d'),
            'payment_method' => $plan['payment_method'],
            'reference' => $plan['reference'],
            'notes' => $plan['notes'],
        ];

        if ($incomeData['amount'] <= 0) {
            flash_set('error', 'Amount must be greater than zero.');
            redirect('accounts/project_payment_confirm.php?plan_id=' . $planId . '&month=' . $monthKey);
        }

        $incomeId = confirm_project_payment_run((int) $run['id'], (int) $plan['project_id'], $planId, $incomeData, $currentUserId);
        $newIncome = find_project_income($incomeId);
        $numberSuffix = $newIncome !== false && !empty($newIncome['invoice_number']) ? " ({$newIncome['invoice_number']})" : '';
        flash_set('status', "Payment added to Income for {$plan['project_title']} — ₹" . number_format($incomeData['amount'], 2) . $numberSuffix . '.');
        redirect('accounts/index.php');
    }

    if ($action === 'skip') {
        skip_project_payment_run((int) $run['id'], $currentUserId);
        flash_set('status', 'Skipped for this period.' . ($plan['payment_type'] === 'monthly' ? ' You\'ll be asked again next month.' : ''));
        redirect('accounts/project_payments.php');
    }
}

$pageTitle = 'Confirm Payment';
$activeMenu = 'accounts';
$breadcrumbs = [
    ['label' => 'Accounts', 'url' => url('accounts/index.php')],
    ['label' => 'Monthly Payments', 'url' => url('accounts/project_payments.php')],
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
                <h2 class="h6 mb-0"><i class="bi bi-cash-coin text-primary"></i> <?= e(project_payment_type_label($plan['payment_type'])) ?></h2>
            </div>
            <div class="card-body">
                <?php if ($run['status'] !== 'pending'): ?>
                    <div class="alert alert-info mb-0">
                        This period (<?= e($monthKey) ?>) for <strong><?= e($plan['project_title']) ?></strong> was already
                        <strong><?= e($run['status']) ?></strong> on <?= e((string) $run['resolved_at']) ?>.
                    </div>
                <?php else: ?>
                    <p class="text-muted">This payment is due. Confirm to add it to <strong>Income</strong>, or skip it — you can adjust the amount or date below before confirming.</p>

                    <dl class="row mb-4">
                        <dt class="col-4 col-sm-3">Project</dt><dd class="col-8 col-sm-9"><?= e($plan['project_title']) ?></dd>
                        <?php if (!empty($plan['client_name'])): ?><dt class="col-4 col-sm-3">Client</dt><dd class="col-8 col-sm-9"><?= e($plan['client_name']) ?></dd><?php endif; ?>
                        <dt class="col-4 col-sm-3">Payment Method</dt><dd class="col-8 col-sm-9"><?= e(ucwords(str_replace('_', ' ', $plan['payment_method']))) ?></dd>
                        <dt class="col-4 col-sm-3">Due</dt><dd class="col-8 col-sm-9"><?= e($run['due_date']) ?></dd>
                    </dl>

                    <form method="POST" action="<?= e(url('accounts/project_payment_confirm.php?plan_id=' . $planId . '&month=' . $monthKey)) ?>" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="plan_id" value="<?= e((string) $planId) ?>">
                        <input type="hidden" name="month" value="<?= e($monthKey) ?>">

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label">Amount (₹) *</label>
                                <input type="number" step="0.01" name="amount" class="form-control" required value="<?= e((string) $plan['amount']) ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Payment Date</label>
                                <input type="date" name="payment_date" class="form-control" value="<?= e(date('Y-m-d')) ?>">
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" name="action" value="confirm" class="btn btn-success"><i class="bi bi-check-lg"></i> Yes, Add to Income</button>
                            <button type="submit" name="action" value="skip" class="btn btn-outline-secondary" onclick="return confirm('Skip this payment for <?= e($monthKey) ?>?');"><i class="bi bi-x-lg"></i> Skip</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
