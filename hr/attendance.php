<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('attendance');

$currentUser = current_user();
$currentUserId = (int) $currentUser['id'];
$isManager = in_array($currentUser['role'], ['super_admin', 'admin'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'punch_in') {
        if (!punch_in($currentUserId)) {
            flash_set('error', "You've already punched in today.");
        } else {
            flash_set('status', 'Punched in at ' . date('H:i') . '.');
        }
        redirect('hr/attendance.php');
    }

    if ($action === 'punch_out') {
        punch_out($currentUserId);
        flash_set('status', 'Punched out at ' . date('H:i') . '.');
        redirect('hr/attendance.php');
    }

    if ($action === 'mark_attendance' && $isManager) {
        mark_attendance(
            (int) $_POST['user_id'],
            $_POST['attendance_date'] ?? date('Y-m-d'),
            $_POST['status'] ?? 'present',
            sanitize_string($_POST['notes'] ?? '') ?: null
        );
        flash_set('status', 'Attendance updated.');
        redirect('hr/attendance.php?date=' . ($_POST['attendance_date'] ?? date('Y-m-d')));
    }
}

$viewDate = $_GET['date'] ?? date('Y-m-d');
$today = todays_attendance($currentUserId);
$roster = $isManager ? attendance_for_date($viewDate) : [];
$myHistory = attendance_history($currentUserId, date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));

// Monthly attendance % — managers can check any employee, employees see their own.
$summaryMonth = $_GET['summary_month'] ?? date('Y-m');
$summaryUserId = $isManager
    ? (isset($_GET['summary_user_id']) && $_GET['summary_user_id'] !== '' ? (int) $_GET['summary_user_id'] : null)
    : $currentUserId;
$monthlySummary = $summaryUserId !== null ? monthly_attendance_summary($summaryUserId, $summaryMonth) : null;
$allEmployees = $isManager ? db()->query("SELECT id, full_name FROM users WHERE status = 'active' ORDER BY full_name")->fetchAll() : [];

$pageTitle = 'Attendance';
$activeMenu = 'attendance';
$breadcrumbs = [['label' => 'Attendance', 'url' => null]];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<h1 class="h4 mb-3">Attendance</h1>

<div class="card mb-4">
    <div class="card-header bg-white"><h2 class="h6 mb-0">My Attendance Today</h2></div>
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <?php if ($today === false): ?>
            <form method="POST" action="<?= e(url('hr/attendance.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="punch_in">
                <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-box-arrow-in-right"></i> Punch In</button>
            </form>
            <span class="text-muted small">You haven't punched in today.</span>
        <?php else: ?>
            <span class="badge <?= e(attendance_status_badge_class($today['status'])) ?>">Checked in <?= e($today['check_in_time'] ?? '') ?></span>
            <?php if ($today['check_out_time']): ?>
                <span class="text-muted small">Checked out <?= e($today['check_out_time']) ?></span>
            <?php else: ?>
                <form method="POST" action="<?= e(url('hr/attendance.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="punch_out">
                    <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-box-arrow-right"></i> Punch Out</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Monthly Attendance %</h2></div>
    <div class="card-body">
        <form method="GET" action="<?= e(url('hr/attendance.php')) ?>" class="row g-2 align-items-end mb-3">
            <?php if ($isManager): ?>
                <div class="col-12 col-md-5">
                    <label class="form-label small">Employee</label>
                    <select name="summary_user_id" class="form-select form-select-sm js-auto-submit">
                        <option value="">Select employee…</option>
                        <?php foreach ($allEmployees as $emp): ?>
                            <option value="<?= e((string) $emp['id']) ?>" <?= $summaryUserId === (int) $emp['id'] ? 'selected' : '' ?>><?= e($emp['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-8 col-md-4">
                <label class="form-label small">Month</label>
                <input type="month" name="summary_month" class="form-control form-control-sm js-auto-submit" value="<?= e($summaryMonth) ?>">
            </div>
            <?php if ($isManager): ?>
                <div class="col-4 col-md-3">
                    <button type="submit" class="btn btn-outline-primary btn-sm w-100">Check</button>
                </div>
            <?php endif; ?>
        </form>

        <?php if ($monthlySummary !== null): ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0 text-center attendance-summary-table">
                    <thead class="table-light">
                        <tr><th>Present</th><th>Absent</th><th>Half Day</th><th>On Leave</th><th>Days in Month</th><th>Attendance %</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= e((string) $monthlySummary['present']) ?></td>
                            <td><?= e((string) $monthlySummary['absent']) ?></td>
                            <td><?= e((string) $monthlySummary['half_day']) ?></td>
                            <td><?= e((string) $monthlySummary['on_leave']) ?></td>
                            <td><?= e((string) $monthlySummary['total_days_in_month']) ?></td>
                            <td class="fw-semibold"><?= e(number_format($monthlySummary['percentage'], 2)) ?>%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted small mb-0">Select an employee to see their attendance percentage for the month.</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($isManager): ?>
<div class="card mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Team Roster</h2>
        <form method="GET" action="<?= e(url('hr/attendance.php')) ?>" class="d-flex gap-2">
            <input type="date" name="date" class="form-control form-control-sm js-auto-submit" value="<?= e($viewDate) ?>">
        </form>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead><tr><th>Employee</th><th>Status</th><th>Check In</th><th>Check Out</th><th class="text-end">Update</th></tr></thead>
                <tbody>
                    <?php foreach ($roster as $row): ?>
                        <tr>
                            <td><?= e($row['full_name']) ?></td>
                            <td><span class="badge <?= e(attendance_status_badge_class($row['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', $row['status'] ?? 'not marked'))) ?></span></td>
                            <td><?= e($row['check_in_time'] ?? '—') ?></td>
                            <td><?= e($row['check_out_time'] ?? '—') ?></td>
                            <td class="text-end">
                                <form method="POST" action="<?= e(url('hr/attendance.php')) ?>" class="d-flex gap-1 justify-content-end">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="mark_attendance">
                                    <input type="hidden" name="user_id" value="<?= e((string) $row['user_id']) ?>">
                                    <input type="hidden" name="attendance_date" value="<?= e($viewDate) ?>">
                                    <select name="status" class="form-select form-select-sm" style="width:120px;">
                                        <?php foreach (['present','absent','half_day','on_leave'] as $s): ?>
                                            <option value="<?= e($s) ?>" <?= $row['status'] === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-white"><h2 class="h6 mb-0">My Last 30 Days</h2></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Date</th><th>Status</th><th>Check In</th><th>Check Out</th></tr></thead>
                <tbody>
                    <?php if (empty($myHistory)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No attendance records yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($myHistory as $row): ?>
                        <tr>
                            <td><?= e($row['attendance_date']) ?></td>
                            <td><span class="badge <?= e(attendance_status_badge_class($row['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', $row['status']))) ?></span></td>
                            <td><?= e($row['check_in_time'] ?? '—') ?></td>
                            <td><?= e($row['check_out_time'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
document.querySelectorAll('.js-auto-submit').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
