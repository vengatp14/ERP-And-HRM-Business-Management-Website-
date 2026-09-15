<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('leaves');

$currentUser = current_user();
$currentUserId = (int) $currentUser['id'];
$isManager = in_array($currentUser['role'], ['super_admin', 'admin'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'apply') {
        $data = [
            'leave_type' => sanitize_string($_POST['leave_type'] ?? 'General'),
            'start_date' => $_POST['start_date'] ?? '',
            'end_date' => $_POST['end_date'] ?? '',
            'reason' => sanitize_string($_POST['reason'] ?? '') ?: null,
        ];

        $error = validate_required($data['start_date'], 'Start date')
            ?? validate_required($data['end_date'], 'End date');
        if ($error === null && $data['end_date'] < $data['start_date']) {
            $error = 'End date cannot be before start date.';
        }

        if ($error !== null) {
            flash_set('error', $error);
            redirect('hr/leaves.php');
        }

        apply_for_leave($currentUserId, $data);
        flash_set('status', 'Leave request submitted.');
        redirect('hr/leaves.php');
    }

    if ($action === 'decide' && $isManager) {
        $decision = $_POST['decision'] ?? '';
        if (in_array($decision, ['approved', 'rejected'], true)) {
            decide_leave_request((int) $_POST['leave_id'], $decision, $currentUserId);
            flash_set('status', "Leave request {$decision}.");
        }
        redirect('hr/leaves.php');
    }
}

$myLeaves = get_leave_requests($currentUserId);
$pendingLeaves = $isManager ? get_leave_requests(null, 'pending') : [];
$allLeaves = $isManager ? get_leave_requests(null, null) : [];

$pageTitle = 'Leave Requests';
$activeMenu = 'leaves';
$breadcrumbs = [['label' => 'Leave Requests', 'url' => null]];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<h1 class="h4 mb-3">Leave Requests</h1>

<div class="row g-4 mb-4">
    <div class="col-12 <?= $isManager ? 'col-lg-5' : '' ?>">
        <div class="card">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Apply for Leave</h2></div>
            <div class="card-body">
                <form method="POST" action="<?= e(url('hr/leaves.php')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="apply">
                    <div class="mb-2">
                        <label class="form-label small">Leave Type</label>
                        <select name="leave_type" class="form-select form-select-sm">
                            <?php foreach (['General', 'Sick', 'Casual', 'Earned', 'Unpaid'] as $type): ?>
                                <option value="<?= e($type) ?>"><?= e($type) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label small">Start Date</label><input type="date" name="start_date" class="form-control form-control-sm" required></div>
                        <div class="col-6"><label class="form-label small">End Date</label><input type="date" name="end_date" class="form-control form-control-sm" required></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Reason</label>
                        <textarea name="reason" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">Submit Request</button>
                </form>
            </div>
        </div>
    </div>

    <?php if ($isManager): ?>
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Pending Approvals</h2></div>
            <div class="card-body">
                <?php if (empty($pendingLeaves)): ?>
                    <p class="text-muted mb-0">No pending leave requests.</p>
                <?php endif; ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($pendingLeaves as $leave): ?>
                        <li class="list-group-item px-0">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong><?= e($leave['full_name']) ?></strong> — <?= e($leave['leave_type']) ?>
                                    <div class="text-muted small"><?= e($leave['start_date']) ?> to <?= e($leave['end_date']) ?></div>
                                    <?php if ($leave['reason']): ?><div class="small mt-1"><?= e($leave['reason']) ?></div><?php endif; ?>
                                </div>
                                <div class="d-flex gap-1">
                                    <form method="POST" action="<?= e(url('hr/leaves.php')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="decide">
                                        <input type="hidden" name="leave_id" value="<?= e((string) $leave['id']) ?>">
                                        <input type="hidden" name="decision" value="approved">
                                        <button type="submit" class="btn btn-sm btn-outline-success">Approve</button>
                                    </form>
                                    <form method="POST" action="<?= e(url('hr/leaves.php')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="decide">
                                        <input type="hidden" name="leave_id" value="<?= e((string) $leave['id']) ?>">
                                        <input type="hidden" name="decision" value="rejected">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Reject</button>
                                    </form>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="card <?= $isManager ? 'mb-4' : '' ?>">
    <div class="card-header bg-white"><h2 class="h6 mb-0">My Leave History</h2></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Type</th><th>Dates</th><th>Status</th><th>Decided By</th></tr></thead>
                <tbody>
                    <?php if (empty($myLeaves)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No leave requests yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($myLeaves as $leave): ?>
                        <tr>
                            <td><?= e($leave['leave_type']) ?></td>
                            <td><?= e($leave['start_date']) ?> to <?= e($leave['end_date']) ?></td>
                            <td><span class="badge <?= e(leave_status_badge_class($leave['status'])) ?>"><?= e(ucfirst($leave['status'])) ?></span></td>
                            <td><?= e($leave['approver_name'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($isManager): ?>
<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">All Employees' Leave History</h2>
        <span class="text-muted small"><?= count($allLeaves) ?> total</span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th>Reason</th><th>Status</th><th>Decided By</th></tr></thead>
                <tbody>
                    <?php if (empty($allLeaves)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No leave requests yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($allLeaves as $leave): ?>
                        <tr>
                            <td><?= e($leave['full_name']) ?></td>
                            <td><?= e($leave['leave_type']) ?></td>
                            <td><?= e($leave['start_date']) ?> to <?= e($leave['end_date']) ?></td>
                            <td><?= e($leave['reason'] ?? '—') ?></td>
                            <td><span class="badge <?= e(leave_status_badge_class($leave['status'])) ?>"><?= e(ucfirst($leave['status'])) ?></span></td>
                            <td><?= e($leave['approver_name'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
