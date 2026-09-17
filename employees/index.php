<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('employees');

$isManager = in_array(current_user()['role'], ['super_admin', 'admin'], true);

if ($isManager && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $targetId = (int) ($_POST['user_id'] ?? 0);
    $currentUserId = (int) current_user()['id'];
    $action = $_POST['action'] ?? '';

    if ($targetId === $currentUserId) {
        flash_set('error', "You can't change your own account.");
        redirect('employees/index.php');
    }

    $target = find_user_by_id($targetId);
    if (!$target) {
        flash_set('error', 'Employee not found.');
        redirect('employees/index.php');
    }

    if ($action === 'toggle_status') {
        $newStatus = $target['status'] === 'active' ? 'inactive' : 'active';
        db()->prepare('UPDATE users SET status = :status WHERE id = :id')
            ->execute(['status' => $newStatus, 'id' => $targetId]);
        audit_log($currentUserId, 'employees', 'status_change', "Set user #{$targetId} status to {$newStatus}.");
        flash_set('status', $newStatus === 'active'
            ? 'Employee activated. They can now log in.'
            : 'Employee deactivated. They can no longer log in.');
    } elseif ($action === 'delete') {
        db()->prepare('UPDATE users SET deleted_at = :now WHERE id = :id')
            ->execute(['now' => date('Y-m-d H:i:s'), 'id' => $targetId]);
        audit_log($currentUserId, 'employees', 'delete', "Deleted user #{$targetId}.");
        flash_set('status', 'Employee deleted.');
    }

    redirect('employees/index.php');
}

$filters = [
    'status' => $_GET['status'] ?? '',
    'department' => $_GET['department'] ?? '',
    'search' => trim((string) ($_GET['q'] ?? '')),
];

$totalEmployees = count_employees($filters);
$pagination = paginate($totalEmployees, 15);
$employees = list_employees($filters, $pagination['perPage'], $pagination['offset']);
$departments = list_departments();

// Today's attendance status per employee — reuses the existing
// attendance_for_date() lookup (includes/hr.php) already used by
// hr/attendance.php, keyed to the current date only (so it never
// becomes a permanent profile value).
$todayStatusByUserId = [];
foreach (attendance_for_date(date('Y-m-d')) as $row) {
    $todayStatusByUserId[(int) $row['user_id']] = $row['status'];
}

$pageTitle = 'Employees';
$activeMenu = 'employees';
$breadcrumbs = [['label' => 'Employees', 'url' => null]];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Employees <span class="text-muted fs-6">(<?= e((string) $totalEmployees) ?>)</span></h1>
    <?php if ($isManager): ?>
        <div class="d-flex gap-2">
            <a href="<?= e(url('hr/salaries.php')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-cash-stack"></i> Salary</a>
            <a href="<?= e(url('hr/certificates.php')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-text"></i> Certificates</a>
            <a href="<?= e(url('employees/form.php')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Add Employee</a>
        </div>
    <?php endif; ?>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="<?= e(url('employees/index.php')) ?>" class="row g-2">
            <div class="col-12 col-md-5">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name, email, mobile, designation"
                       value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-6 col-md-3">
                <select name="department" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= e($dept) ?>" <?= $filters['department'] === $dept ? 'selected' : '' ?>><?= e($dept) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <?php foreach (['active', 'inactive', 'suspended'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill">Filter</button>
                <a href="<?= e(url('employees/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-3">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Designation</th>
                        <th>Department</th>
                        <th>Mobile</th>
                        <th>Email</th>
                        <th>Active</th>
                        <th>Attendance</th>
                        <th>Quick Contact</th>
                        <?php if ($isManager): ?><th class="text-end">Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)): ?>
                        <tr><td colspan="<?= $isManager ? 9 : 8 ?>" class="text-center text-muted py-4">No employees found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($employees as $emp): ?>
                        <?php $isSelf = (int) $emp['id'] === (int) current_user()['id']; ?>
                        <tr>
                            <td>
                                <?= e($emp['full_name']) ?>
                                <?php if ($isSelf): ?><span class="badge text-bg-light border text-muted">You</span><?php endif; ?>
                            </td>
                            <td><?= e($emp['designation'] ?? '—') ?></td>
                            <td><?= e($emp['department'] ?? '—') ?></td>
                            <td><?= e($emp['mobile'] ?? '—') ?></td>
                            <td><?= e($emp['email']) ?></td>
                            <td><span class="badge <?= e(employee_status_badge_class($emp['status'])) ?>"><?= e(ucfirst($emp['status'])) ?></span></td>
                            <td>
                                <?php
                                    // Read-only — reflects the actual attendance record for today
                                    // (employee's own punch-in/out, or a manager's entry from the
                                    // Team Roster on hr/attendance.php). This table intentionally
                                    // offers no way to set it directly; see includes/hr.php
                                    // attendance_for_date() / hr/attendance.php for where
                                    // attendance is actually recorded.
                                    // Collapsed to the three values this column is allowed to show
                                    // (Present / Absent / Not Marked) — half_day counts as Present,
                                    // on_leave counts as Absent here; the full status (including Half
                                    // Day / On Leave) is still visible on the Attendance page's roster.
                                    $todayStatus = $todayStatusByUserId[(int) $emp['id']] ?? null;
                                    $attendanceLabel = match ($todayStatus) {
                                        'present', 'half_day' => 'Present',
                                        'absent', 'on_leave' => 'Absent',
                                        default => 'Not Marked',
                                    };
                                ?>
                                <?php if ($attendanceLabel === 'Absent'): ?>
                                    <span class="badge text-bg-danger">Absent</span>
                                <?php elseif ($attendanceLabel === 'Present'): ?>
                                    <span class="badge text-bg-success">Present</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Not Marked</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($emp['mobile'])): ?>
                                    <div class="contact-actions">
                                        <a href="<?= e(tel_link($emp['mobile'])) ?>"
                                           class="contact-action-btn call" title="Call <?= e($emp['full_name']) ?>">
                                            <i class="bi bi-telephone-fill"></i>
                                        </a>
                                        <a href="<?= e(whatsapp_link($emp['mobile'])) ?>" target="_blank" rel="noopener"
                                           class="contact-action-btn whatsapp" title="WhatsApp <?= e($emp['full_name']) ?>">
                                            <i class="bi bi-whatsapp"></i>
                                        </a>
                                    </div>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <?php if ($isManager): ?>
                                <td class="text-end">
                                    <div class="d-inline-flex align-items-center gap-2">
                                        <?php if ((int) $emp['id'] !== (int) current_user()['id']): ?>
                                            <form method="POST" action="<?= e(url('employees/index.php')) ?>" class="d-inline status-toggle"
                                                  title="<?= $emp['status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?= e((string) $emp['id']) ?>">
                                                <div class="form-check form-switch mb-0">
                                                    <input class="form-check-input js-status-toggle-input" type="checkbox" role="switch"
                                                           <?= $emp['status'] === 'active' ? 'checked' : '' ?>
                                                           aria-label="<?= $emp['status'] === 'active' ? 'Deactivate employee' : 'Activate employee' ?>">
                                                </div>
                                            </form>
                                        <?php endif; ?>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary" type="button"
                                                    data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false"
                                                    aria-label="Actions for <?= e($emp['full_name']) ?>">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li><a class="dropdown-item" href="<?= e(url('employees/view.php?id=' . $emp['id'])) ?>">View</a></li>
                                                <li><a class="dropdown-item" href="<?= e(url('employees/form.php?id=' . $emp['id'])) ?>">Edit</a></li>
                                                <?php if ((int) $emp['id'] !== (int) current_user()['id']): ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <form method="POST" action="<?= e(url('employees/index.php')) ?>" class="js-confirm-delete">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="user_id" value="<?= e((string) $emp['id']) ?>">
                                                            <button type="submit" class="dropdown-item text-danger">Delete</button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= render_pagination($pagination['page'], $pagination['totalPages']) ?>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
document.addEventListener('change', function (e) {
    if (e.target.matches('.js-status-toggle-input')) {
        e.target.disabled = true;
        e.target.form.submit();
    }
});

</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
