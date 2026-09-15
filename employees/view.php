<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('employees');

$isManager = in_array(current_user()['role'], ['super_admin', 'admin'], true);
$employeeId = (int) ($_GET['id'] ?? 0);
$employee = find_user_by_id($employeeId);

// Plain employees may only view their own profile; managers can view anyone's.
if ($employee === false || $employee['deleted_at'] !== null) {
    flash_set('error', 'Employee not found.');
    redirect('employees/index.php');
}
if (!$isManager && $employeeId !== (int) current_user()['id']) {
    flash_set('error', 'You can only view your own profile.');
    redirect('employees/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_task') {
        $title = sanitize_string($_POST['title'] ?? '');
        $projectId = (int) ($_POST['project_id'] ?? 0);
        if ($title === '') {
            flash_set('error', 'Task title is required.');
            redirect('employees/view.php?id=' . $employeeId);
        }
        if ($projectId <= 0) {
            flash_set('error', 'Please select a project.');
            redirect('employees/view.php?id=' . $employeeId);
        }
        add_project_task(
            $projectId,
            $title,
            $employeeId,
            ($_POST['due_date'] ?? '') !== '' ? $_POST['due_date'] : null
        );
        flash_set('status', 'Task added.');
        redirect('employees/view.php?id=' . $employeeId);
    }

    if ($action === 'update_task_status') {
        $taskId = (int) ($_POST['task_id'] ?? 0);
        $status = $_POST['status'] ?? 'pending';
        $task = null;
        foreach (get_tasks_for_employee($employeeId) as $t) {
            if ((int) $t['id'] === $taskId) {
                $task = $t;
                break;
            }
        }
        if ($task !== null && in_array($status, ['pending', 'in_progress', 'done'], true)) {
            update_project_task_status($taskId, (int) $task['project_id'], $status);
        }
        redirect('employees/view.php?id=' . $employeeId);
    }
}

$tasks = get_tasks_for_employee($employeeId);
$employeeProjects = get_projects_for_employee($employeeId);

$ratingMonth = trim((string) ($_GET['rating_month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $ratingMonth)) {
    $ratingMonth = date('Y-m');
}
$monthlyRating = monthly_rating_summary($employeeId, $ratingMonth);
$ratingHistory = monthly_rating_history($employeeId, 6);

$pageTitle = 'Employee Profile';
$activeMenu = 'employees';
$breadcrumbs = [
    ['label' => 'Employees', 'url' => url('employees/index.php')],
    ['label' => $employee['full_name'], 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';

function profile_row(string $label, ?string $value, string $icon = 'bi-dash'): string
{
    $display = ($value !== null && $value !== '') ? e($value) : '<span class="text-muted">Not specified</span>';
    return '<div class="col-12 col-md-6"><div class="d-flex gap-2"><i class="bi ' . e($icon) . ' text-muted mt-1"></i>'
        . '<div class="min-w-0"><div class="text-muted small">' . e($label) . '</div><div class="text-break">' . $display . '</div></div></div></div>';
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Employee Profile</h1>
    <div class="d-flex gap-2">
        <?php if ($isManager): ?>
            <a href="<?= e(url('hr/meetings.php?attendee=' . $employee['id'])) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-calendar-plus"></i> Arrange Meeting
            </a>
            <a href="<?= e(url('employees/form.php?id=' . $employee['id'])) ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-pencil"></i> Edit
            </a>
        <?php endif; ?>
        <a href="<?= e(url('employees/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
            <?php if (!empty($employee['profile_photo'])): ?>
                <img src="<?= e(url('uploads/images/' . $employee['profile_photo'])) ?>" alt="<?= e($employee['full_name']) ?>"
                     class="rounded-circle" style="width:96px;height:96px;object-fit:cover;">
            <?php else: ?>
                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center text-muted"
                     style="width:96px;height:96px;"><i class="bi bi-person fs-1"></i></div>
            <?php endif; ?>
            <div>
                <div class="fs-4 fw-semibold"><?= e($employee['full_name']) ?></div>
                <div class="text-muted"><?= e($employee['designation'] ?? '—') ?><?= $employee['department'] ? ' · ' . e($employee['department']) : '' ?></div>
                <span class="badge <?= e(employee_status_badge_class($employee['status'])) ?> mt-1"><?= e(ucfirst($employee['status'])) ?></span>
            </div>
        </div>

        <hr>

        <div class="row g-3">
            <?= profile_row('Date of Birth', $employee['date_of_birth'] ?? null, 'bi-cake2') ?>
            <?= profile_row('Blood Group', $employee['blood_group'] ?? null, 'bi-droplet') ?>
            <?= profile_row('Phone Number', $employee['mobile'] ?? null, 'bi-telephone') ?>
            <?= profile_row('Alternate Phone Number', $employee['alternate_mobile'] ?? null, 'bi-telephone-plus') ?>
            <?= profile_row('Date of Joining', $employee['joining_date'] ?? null, 'bi-calendar-check') ?>
            <?= profile_row('Email', $employee['email'] ?? null, 'bi-envelope') ?>
            <div class="col-12">
                <div class="d-flex gap-2">
                    <i class="bi bi-geo-alt text-muted mt-1"></i>
                    <div class="min-w-0">
                        <div class="text-muted small">Address</div>
                        <div class="text-break"><?= !empty($employee['address']) ? nl2br(e($employee['address'])) : '<span class="text-muted">Not specified</span>' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($employee['mobile'])): ?>
            <hr>
            <div class="contact-actions">
                <a href="<?= e(tel_link($employee['mobile'])) ?>" class="contact-action-btn call" title="Call">
                    <i class="bi bi-telephone-fill"></i>
                </a>
                <a href="<?= e(whatsapp_link($employee['mobile'])) ?>" target="_blank" rel="noopener" class="contact-action-btn whatsapp" title="WhatsApp">
                    <i class="bi bi-whatsapp"></i>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h2 class="h6 mb-0">Monthly Performance Rating</h2>
        <form method="GET" action="<?= e(url('employees/view.php')) ?>" class="d-flex gap-2 align-items-center">
            <input type="hidden" name="id" value="<?= (int) $employeeId ?>">
            <input type="month" name="rating_month" class="form-control form-control-sm" value="<?= e($ratingMonth) ?>">
            <button type="submit" class="btn btn-outline-secondary btn-sm">View</button>
        </form>
    </div>
    <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="fs-3"><?= star_display($monthlyRating['rounded'] > 0 ? $monthlyRating['rounded'] : null, 'No ratings yet') ?></div>
            <div class="text-muted small">
                <?php if ($monthlyRating['rated_count'] > 0): ?>
                    Average <?= e(number_format((float) $monthlyRating['average'], 1)) ?> / 5
                    &middot; <?= (int) $monthlyRating['rated_count'] ?> of <?= (int) $monthlyRating['total_count'] ?> updates rated
                    for <?= e(date('F Y', strtotime($ratingMonth . '-01'))) ?>
                <?php else: ?>
                    No daily updates rated yet for <?= e(date('F Y', strtotime($ratingMonth . '-01'))) ?>.
                <?php endif; ?>
            </div>
        </div>
        <hr>
        <p class="text-muted small mb-2">Last 6 months</p>
        <div class="row g-2">
            <?php foreach ($ratingHistory as $hist): ?>
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="border rounded p-2 text-center h-100">
                        <div class="small text-muted"><?= e(date('M Y', strtotime($hist['month'] . '-01'))) ?></div>
                        <div><?= star_display($hist['rounded'] > 0 ? $hist['rounded'] : null, '—') ?></div>
                        <div class="small text-muted"><?= (int) $hist['rated_count'] ?>/<?= (int) $hist['total_count'] ?> rated</div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Tasks</h2></div>
    <div class="card-body">
        <ul class="list-group list-group-flush small">
            <?php if (empty($tasks)): ?>
                <li class="list-group-item text-muted px-0">No tasks assigned.</li>
            <?php endif; ?>
            <?php foreach ($tasks as $task): ?>
                <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="<?= $task['status'] === 'done' ? 'text-decoration-line-through text-muted' : '' ?>"><?= e($task['title']) ?></span>
                        <div class="text-muted">
                            <?= e($task['project_title']) ?>
                            <?php if ($task['due_date']): ?> · Due <?= e($task['due_date']) ?><?php endif; ?>
                        </div>
                    </div>
                    <form method="POST" action="<?= e(url('employees/view.php?id=' . $employeeId)) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_task_status">
                        <input type="hidden" name="task_id" value="<?= e((string) $task['id']) ?>">
                        <select name="status" class="form-select form-select-sm js-auto-submit">
                            <?php foreach (['pending', 'in_progress', 'done'] as $s): ?>
                                <option value="<?= e($s) ?>" <?= $task['status'] === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>

        <form method="POST" action="<?= e(url('employees/view.php?id=' . $employeeId)) ?>" class="row g-2" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_task">
            <div class="col-12 col-md-5"><input type="text" name="title" class="form-control form-control-sm" placeholder="Task title" required></div>
            <div class="col-6 col-md-3">
                <select name="project_id" class="form-select form-select-sm" required>
                    <option value="">Select project…</option>
                    <?php foreach ($employeeProjects as $proj): ?>
                        <option value="<?= e((string) $proj['id']) ?>"><?= e($proj['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3"><input type="date" name="due_date" class="form-control form-control-sm"></div>
            <div class="col-12 col-md-1"><button type="submit" class="btn btn-outline-primary btn-sm w-100">Add</button></div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
