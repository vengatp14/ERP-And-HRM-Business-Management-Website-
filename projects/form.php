<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
// permission check happens below once we know if this is add or edit

$projectId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$project = $projectId !== null ? find_project($projectId) : null;

if ($projectId !== null && $project === false) {
    flash_set('error', 'Project not found.');
    redirect('projects/index.php');
}

require_permission('projects', $projectId !== null ? 'edit' : 'add');

$isEdit = $project !== null;
$existingPlan = $isEdit ? find_project_payment_plan_by_project($projectId) : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $data = [
        'client_id' => (int) ($_POST['client_id'] ?? 0),
        'title' => sanitize_string($_POST['title'] ?? ''),
        'description' => sanitize_string($_POST['description'] ?? '') ?: null,
        'project_type' => sanitize_string($_POST['project_type'] ?? '') ?: null,
        'status' => $_POST['status'] ?? 'planning',
        'priority' => $_POST['priority'] ?? 'medium',
        'budget' => is_admin_role(current_user())
            ? (($_POST['budget'] ?? '') !== '' ? (float) $_POST['budget'] : null)
            : ($project['budget'] ?? null),
        'start_date' => ($_POST['start_date'] ?? '') !== '' ? $_POST['start_date'] : null,
        'deadline' => ($_POST['deadline'] ?? '') !== '' ? $_POST['deadline'] : null,
        'manager_id' => ($_POST['manager_id'] ?? '') !== '' ? (int) $_POST['manager_id'] : null,
    ];

    $error = validate_required($data['title'], 'Project title');
    if ($error === null && $data['client_id'] <= 0) {
        $error = 'Please select a client.';
    }
    if ($error === null && find_client($data['client_id']) === false) {
        $error = 'Selected client was not found.';
    }
    if ($error === null && !in_array($data['status'], ['planning','in_progress','on_hold','pending_approval','completed','cancelled'], true)) {
        $error = 'Invalid status.';
    }
    // Only admins can close out a project as Completed — that's what
    // fires the auto GST invoice, so it shouldn't be something any
    // employee can trigger by picking an option in a dropdown.
    if ($error === null && $data['status'] === 'completed' && ($project['status'] ?? null) !== 'completed' && !is_admin_role(current_user())) {
        $error = 'Only an admin can mark a project as Completed.';
    }
    if ($error === null && !in_array($data['priority'], ['low','medium','high'], true)) {
        $error = 'Invalid priority.';
    }

    // Payment plan fields (financial data — admin only, same gating as Budget).
    $paymentType = is_admin_role(current_user()) ? ($_POST['payment_type'] ?? '') : '';
    $paymentAmount = (float) ($_POST['payment_amount'] ?? 0);
    $paymentDayOfMonth = (int) ($_POST['payment_day_of_month'] ?? 1);
    $paymentDueDate = ($_POST['payment_due_date'] ?? '') !== '' ? $_POST['payment_due_date'] : null;

    if ($error === null && in_array($paymentType, ['one_time', 'monthly'], true)) {
        if ($paymentAmount <= 0) {
            $error = 'Enter a payment amount greater than zero, or set Payment to "— None —".';
        } elseif ($paymentType === 'monthly' && ($paymentDayOfMonth < 1 || $paymentDayOfMonth > 28)) {
            $error = 'Monthly payment day must be between 1 and 28 (to keep it valid for every month).';
        } elseif ($paymentType === 'one_time' && $paymentDueDate === null) {
            $error = 'Pick a due date for the one-time payment.';
        }
    }

    if ($error !== null) {
        flash_set('error', $error);
        redirect('projects/form.php' . ($isEdit ? '?id=' . $projectId : ''));
    }

    // Track completion timestamp when a project moves into "completed".
    if ($data['status'] === 'completed' && ($project['status'] ?? null) !== 'completed') {
        $data['completed_at'] = date('Y-m-d H:i:s');
    }

    $currentUserId = (int) current_user()['id'];

    if ($isEdit) {
        update_project($projectId, $data);
        save_project_payment_plan_from_form($projectId, $existingPlan, $paymentType, $paymentAmount, $paymentDayOfMonth, $paymentDueDate, $currentUserId);
        add_project_note($projectId, $currentUserId, 'Project details updated.');
        if ($data['status'] === 'completed' && ($project['status'] ?? null) !== 'completed') {
            $autoInvoiceId = auto_generate_invoice_for_completed_project($projectId, $currentUserId);
            flash_set('status', $autoInvoiceId !== null
                ? 'Project marked Completed — a draft GST invoice was created automatically. Review it in Billing.'
                : 'Project marked Completed.');
        } else {
            flash_set('status', 'Project updated successfully.');
        }
        redirect('projects/view.php?id=' . $projectId);
    }

    $newId = create_project($data, $currentUserId);
    save_project_payment_plan_from_form($newId, false, $paymentType, $paymentAmount, $paymentDayOfMonth, $paymentDueDate, $currentUserId);
    add_project_note($newId, $currentUserId, 'Project created.');
    flash_set('status', 'Project created successfully.');
    redirect('projects/view.php?id=' . $newId);
}

$clients = list_active_clients_for_select();
$employees = db()->query("SELECT id, full_name FROM users WHERE status = 'active' ORDER BY full_name")->fetchAll();

$pageTitle = $isEdit ? 'Edit Project' : 'Add Project';
$activeMenu = 'projects';
$breadcrumbs = [
    ['label' => 'Projects', 'url' => url('projects/index.php')],
    ['label' => $isEdit ? 'Edit' : 'Add', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="card">
    <div class="card-header bg-white"><h2 class="h6 mb-0"><?= e($pageTitle) ?></h2></div>
    <div class="card-body">
        <?php if (empty($clients) && !$isEdit): ?>
            <div class="alert alert-warning">
                You need at least one active client before creating a project.
                <a href="<?= e(url('clients/form.php')) ?>">Add a client first</a>.
            </div>
        <?php endif; ?>
        <form method="POST" action="<?= e(url('projects/form.php' . ($isEdit ? '?id=' . $projectId : ''))) ?>" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-12 col-md-8">
                    <label class="form-label">Project Title *</label>
                    <input type="text" name="title" class="form-control" required value="<?= e($project['title'] ?? '') ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label">Client *</label>
                    <select name="client_id" class="form-select" required>
                        <option value="">Select client…</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= e((string) $c['id']) ?>" <?= (int) ($project['client_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= e($c['company_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3"><?= e($project['description'] ?? '') ?></textarea>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">Project Type</label>
                    <input type="text" name="project_type" class="form-control" value="<?= e($project['project_type'] ?? '') ?>">
                </div>
                <?php if (is_admin_role(current_user())): ?>
                <div class="col-6 col-md-3">
                    <label class="form-label">Budget (₹)</label>
                    <input type="number" step="0.01" name="budget" class="form-control" value="<?= e((string) ($project['budget'] ?? '')) ?>">
                </div>
                <?php endif; ?>
                <?php if (is_admin_role(current_user())): ?>
                <div class="col-12"><hr class="my-1"></div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Payment</label>
                    <select name="payment_type" id="paymentType" class="form-select">
                        <option value="">— None —</option>
                        <option value="one_time" <?= ($existingPlan['payment_type'] ?? '') === 'one_time' ? 'selected' : '' ?>>One-Time Payment</option>
                        <option value="monthly" <?= ($existingPlan['payment_type'] ?? '') === 'monthly' ? 'selected' : '' ?>>Monthly Payment</option>
                    </select>
                    <div class="form-text">Reminds you (and adds to Income once confirmed) when a payment is due.</div>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Amount (₹)</label>
                    <input type="number" step="0.01" name="payment_amount" id="paymentAmount" class="form-control" value="<?= e((string) ($existingPlan['amount'] ?? '')) ?>">
                </div>
                <div class="col-6 col-md-3" id="paymentDayWrap" style="display:none;">
                    <label class="form-label">Day of Month</label>
                    <input type="number" min="1" max="28" name="payment_day_of_month" id="paymentDayOfMonth" class="form-control" value="<?= e((string) ($existingPlan['day_of_month'] ?? '1')) ?>">
                    <div class="form-text">1–28, e.g. rent/maintenance due on the 5th.</div>
                </div>
                <div class="col-6 col-md-3" id="paymentDueWrap" style="display:none;">
                    <label class="form-label">Due Date</label>
                    <input type="date" name="payment_due_date" id="paymentDueDate" class="form-control" value="<?= e($existingPlan['due_date'] ?? '') ?>">
                </div>
                <?php endif; ?>
                <div class="col-6 col-md-3">
                    <label class="form-label">Priority</label>
                    <select name="priority" class="form-select">
                        <?php foreach (['low','medium','high'] as $p): ?>
                            <option value="<?= e($p) ?>" <?= ($project['priority'] ?? 'medium') === $p ? 'selected' : '' ?>><?= e(ucfirst($p)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" id="projectStatus" class="form-select" data-original-status="<?= e($project['status'] ?? 'planning') ?>">
                        <?php foreach (['planning','in_progress','on_hold','pending_approval','completed','cancelled'] as $s): ?>
                            <?php $lockCompleted = $s === 'completed' && ($project['status'] ?? null) !== 'completed' && !is_admin_role(current_user()); ?>
                            <option value="<?= e($s) ?>" <?= ($project['status'] ?? 'planning') === $s ? 'selected' : '' ?> <?= $lockCompleted ? 'disabled' : '' ?>>
                                <?= e(ucwords(str_replace('_', ' ', $s))) ?><?= $lockCompleted ? ' (admin only)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!is_admin_role(current_user()) && ($project['status'] ?? null) !== 'completed'): ?>
                        <div class="form-text">Only an admin can mark a project Completed.</div>
                    <?php endif; ?>
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label">Manager</label>
                    <select name="manager_id" class="form-select">
                        <option value="">Unassigned</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= e((string) $emp['id']) ?>" <?= (int) ($project['manager_id'] ?? 0) === (int) $emp['id'] ? 'selected' : '' ?>>
                                <?= e($emp['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?= e($project['start_date'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label">Deadline</label>
                    <input type="date" name="deadline" class="form-control" value="<?= e($project['deadline'] ?? '') ?>">
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Project' ?></button>
                <a href="<?= e(url('projects/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
// Marking a project Completed auto-creates a draft GST invoice from its
// budget (see auto_generate_invoice_for_completed_project() in
// includes/billing.php) — confirm before that fires, since it's a
// one-way trigger the first time status flips to Completed.
(function () {
    var form = document.querySelector('form[action*="projects/form.php"]');
    var statusSelect = document.getElementById('projectStatus');
    if (!form || !statusSelect) {
        return;
    }
    form.addEventListener('submit', function (e) {
        var originalStatus = statusSelect.getAttribute('data-original-status');
        if (statusSelect.value === 'completed' && originalStatus !== 'completed') {
            var ok = confirm('Mark this project as Completed? This will automatically create a draft GST invoice in Billing using the project budget.');
            if (!ok) {
                e.preventDefault();
            }
        }
    });
})();

// Toggle the Day of Month / Due Date field to match the chosen payment type.
(function () {
    var typeSelect = document.getElementById('paymentType');
    var dayWrap = document.getElementById('paymentDayWrap');
    var dueWrap = document.getElementById('paymentDueWrap');
    if (!typeSelect || !dayWrap || !dueWrap) {
        return;
    }
    function sync() {
        dayWrap.style.display = typeSelect.value === 'monthly' ? '' : 'none';
        dueWrap.style.display = typeSelect.value === 'one_time' ? '' : 'none';
    }
    typeSelect.addEventListener('change', sync);
    sync();
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
