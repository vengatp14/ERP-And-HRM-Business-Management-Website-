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
        set_project_payment_plan_active($id, true);
        flash_set('status', 'Payment plan activated.');
    } elseif ($action === 'deactivate') {
        set_project_payment_plan_active($id, false);
        flash_set('status', 'Payment plan deactivated. It will stop generating reminders.');
    } elseif ($action === 'delete') {
        soft_delete_project_payment_plan($id);
        flash_set('status', 'Payment plan deleted.');
    }
    redirect('accounts/project_payments.php');
}

$plans = list_project_payment_plans();

$calMonth = calendar_resolve_month();
$calendarEvents = get_project_payment_calendar_events($calMonth['start'], $calMonth['end']);

$pageTitle = 'Monthly Payments';
$activeMenu = 'accounts';
$breadcrumbs = [
    ['label' => 'Accounts', 'url' => url('accounts/index.php')],
    ['label' => 'Monthly Payments', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0">Monthly Payments</h1>
        <p class="text-muted small mb-0">Recurring monthly project payment collection. On the due date you'll get a notification to confirm before it's added to Income — nothing is posted automatically.</p>
    </div>
    <a href="<?= e(url('accounts/project_payment_form.php')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> New Payment Plan</a>
</div>

<?= render_calendar_widget('projectPaymentsCalendar', $calendarEvents, url('accounts/project_payments.php')) ?>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Client</th>
                        <th>Type</th>
                        <th>Due</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($plans)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No payment plans set up yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($plans as $plan): ?>
                        <tr>
                            <td><a href="<?= e(url('projects/view.php?id=' . $plan['project_id'])) ?>"><?= e($plan['project_title']) ?></a></td>
                            <td><?= e($plan['client_name'] ?? '—') ?></td>
                            <td><?= e(project_payment_type_label($plan['payment_type'])) ?></td>
                            <td>
                                <?php if ($plan['payment_type'] === 'monthly'): ?>
                                    Every <?= e((string) $plan['day_of_month']) ?><?= e(ordinal_suffix((int) $plan['day_of_month'])) ?>
                                <?php else: ?>
                                    <?= e($plan['due_date'] ?? '—') ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">₹<?= e(number_format((float) $plan['amount'], 2)) ?></td>
                            <td>
                                <?php if ((int) $plan['is_active'] === 1): ?>
                                    <span class="badge text-bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary" type="button"
                                            data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false"
                                            aria-label="Actions for <?= e($plan['project_title']) ?>">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" href="<?= e(url('accounts/project_payment_form.php?id=' . $plan['id'])) ?>">Edit</a></li>
                                        <li>
                                            <form method="POST" action="<?= e(url('accounts/project_payments.php')) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= e((string) $plan['id']) ?>">
                                                <?php if ((int) $plan['is_active'] === 1): ?>
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
                                            <form method="POST" action="<?= e(url('accounts/project_payments.php')) ?>" onsubmit="return confirm('Delete this payment plan? Past income already added will NOT be removed.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= e((string) $plan['id']) ?>">
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
