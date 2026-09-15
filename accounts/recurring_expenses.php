<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_role('super_admin', 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'activate') {
        set_recurring_expense_active($id, true);
        flash_set('status', 'Auto expense activated.');
    } elseif ($action === 'deactivate') {
        set_recurring_expense_active($id, false);
        flash_set('status', 'Auto expense deactivated. It will stop generating monthly reminders.');
    } elseif ($action === 'delete') {
        soft_delete_recurring_expense($id);
        flash_set('status', 'Auto expense rule deleted.');
    }
    redirect('accounts/recurring_expenses.php');
}

$rules = list_recurring_expenses();

$pageTitle = 'Auto Expenses';
$activeMenu = 'accounts';
$breadcrumbs = [
    ['label' => 'Accounts', 'url' => url('accounts/index.php')],
    ['label' => 'Auto Expenses', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0">Auto Expenses</h1>
        <p class="text-muted small mb-0">Recurring monthly expenses (rent, subscriptions, salaries...). On the set day each month you'll get a notification to confirm before it's added — nothing is posted automatically.</p>
    </div>
    <a href="<?= e(url('accounts/recurring_expense_form.php')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> New Auto Expense</a>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Day of Month</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Vendor</th>
                        <th class="text-end">Default Amount</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rules)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No auto expenses set up yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rules as $rule): ?>
                        <tr>
                            <td>Every <?= e((string) $rule['day_of_month']) ?><?= e(ordinal_suffix((int) $rule['day_of_month'])) ?></td>
                            <td><?= e($rule['category']) ?></td>
                            <td><?= e($rule['description'] ?? '—') ?></td>
                            <td><?= e($rule['vendor'] ?? '—') ?></td>
                            <td class="text-end">₹<?= e(number_format((float) $rule['amount'], 2)) ?></td>
                            <td>
                                <?php if ((int) $rule['is_active'] === 1): ?>
                                    <span class="badge text-bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary" type="button"
                                            data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false"
                                            aria-label="Actions for <?= e($rule['category'] ?? 'auto expense rule') ?>">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" href="<?= e(url('accounts/recurring_expense_form.php?id=' . $rule['id'])) ?>">Edit</a></li>
                                        <li>
                                            <form method="POST" action="<?= e(url('accounts/recurring_expenses.php')) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= e((string) $rule['id']) ?>">
                                                <?php if ((int) $rule['is_active'] === 1): ?>
                                                    <input type="hidden" name="action" value="deactivate">
                                                    <button type="submit" class="dropdown-item">Pause</button>
                                                <?php else: ?>
                                                    <input type="hidden" name="action" value="activate">
                                                    <button type="submit" class="dropdown-item">Resume</button>
                                                <?php endif; ?>
                                            </form>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="POST" action="<?= e(url('accounts/recurring_expenses.php')) ?>" onsubmit="return confirm('Delete this auto expense rule? Past expenses already added will NOT be removed.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= e((string) $rule['id']) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <button type="submit" class="dropdown-item text-danger">Delete</button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
