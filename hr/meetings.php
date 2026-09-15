<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('meetings');

$currentUser = current_user();
$currentUserId = (int) $currentUser['id'];
$isManager = in_array($currentUser['role'], ['super_admin', 'admin'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $data = [
            'title' => sanitize_string($_POST['title'] ?? ''),
            'description' => sanitize_string($_POST['description'] ?? '') ?: null,
            'meeting_date' => $_POST['meeting_date'] ?? '',
            'start_time' => $_POST['start_time'] ?? null,
            'end_time' => $_POST['end_time'] ?? null,
            'location' => sanitize_string($_POST['location'] ?? '') ?: null,
        ];
        $attendees = array_map('intval', $_POST['attendees'] ?? []);

        $error = validate_required($data['title'], 'Title')
            ?? validate_required($data['meeting_date'], 'Meeting date');

        if ($error === null && empty($attendees)) {
            $error = 'Select at least one attendee, or employees won\'t see this meeting in their schedule.';
        }

        if ($error !== null) {
            flash_set('error', $error);
            redirect('hr/meetings.php');
        }

        create_meeting($data, $attendees, $currentUserId);
        flash_set('status', 'Meeting scheduled.');
        redirect('hr/meetings.php');
    }

    if ($action === 'mark_held') {
        // Meetings only defines a "view" permission (see includes/permissions.php);
        // require_menu_access('meetings') at the top of this file already
        // gates the whole page, so no extra action-level check is needed here.
        $meetingId = (int) ($_POST['meeting_id'] ?? 0);
        $notes = sanitize_string($_POST['attendance_notes'] ?? '');

        // A blank note doesn't count as attendance — the whole point is
        // that someone has to actually describe who attended / what
        // happened, not just click a button. No text, no "held".
        if ($notes === '') {
            flash_set('error', 'Add a short note on who attended before marking this meeting held.');
            redirect('hr/meetings.php');
        }

        mark_meeting_held($meetingId, $notes);
        flash_set('status', 'Meeting marked as held.');
        redirect('hr/meetings.php');
    }
}

// Also run it opportunistically on every list view (cheap query if
// nothing's overdue), so statuses stay accurate without needing cron —
// same pattern as process_missed_leads() in the Leads module.
process_missed_meetings();

// All meetings are visible to every user (not just attendees/creator/managers).
$meetings = get_meetings();
$employees = db()->query("SELECT id, full_name FROM users WHERE status = 'active' ORDER BY full_name")->fetchAll();
$preselectedAttendeeId = isset($_GET['attendee']) ? (int) $_GET['attendee'] : null;

$calMonth = calendar_resolve_month();
$calendarEvents = get_meeting_calendar_events($calMonth['start'], $calMonth['end'], null);

$pageTitle = 'Meetings';
$activeMenu = 'meetings';
$breadcrumbs = [['label' => 'Meetings', 'url' => null]];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<h1 class="h4 mb-3">Meetings</h1>

<?= render_calendar_widget('meetingsCalendar', $calendarEvents, url('hr/meetings.php')) ?>

<div class="row g-4">
    <div class="col-12 col-lg-5">
        <div class="card">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Schedule Meeting</h2></div>
            <div class="card-body">
                <form method="POST" action="<?= e(url('hr/meetings.php')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="mb-2">
                        <label class="form-label small">Title *</label>
                        <input type="text" name="title" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Description</label>
                        <textarea name="description" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-4"><label class="form-label small">Date *</label><input type="date" name="meeting_date" class="form-control form-control-sm" required></div>
                        <div class="col-4"><label class="form-label small">Start</label><input type="time" name="start_time" class="form-control form-control-sm"></div>
                        <div class="col-4"><label class="form-label small">End</label><input type="time" name="end_time" class="form-control form-control-sm"></div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Location / Link</label>
                        <input type="text" name="location" class="form-control form-control-sm">
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small mb-0">Attendees *</label>
                            <button type="button" class="btn btn-link btn-sm p-0" id="meetingSelectAll">Select all</button>
                        </div>
                        <?php if ($preselectedAttendeeId !== null): ?>
                            <div class="form-text mb-1">Pre-selected from the Employees page — add more if needed.</div>
                        <?php endif; ?>
                        <div class="border rounded p-2" style="max-height: 180px; overflow-y: auto;">
                            <?php foreach ($employees as $emp): ?>
                                <div class="form-check">
                                    <input class="form-check-input meeting-attendee-checkbox" type="checkbox" name="attendees[]"
                                           value="<?= e((string) $emp['id']) ?>" id="attendee-<?= e((string) $emp['id']) ?>"
                                           <?= (int) $emp['id'] === $preselectedAttendeeId ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="attendee-<?= e((string) $emp['id']) ?>">
                                        <?= e($emp['full_name']) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">Only selected attendees will see this meeting in their schedule.</div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">Schedule</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header bg-white"><h2 class="h6 mb-0">All Meetings</h2></div>
            <div class="card-body">
                <?php if (empty($meetings)): ?>
                    <p class="text-muted mb-0">No meetings scheduled.</p>
                <?php endif; ?>
                <ul class="list-group list-group-flush">
                    <?php $attendModals = ''; ?>
                    <?php foreach ($meetings as $meeting): ?>
                        <?php
                        $attendees = get_meeting_attendees((int) $meeting['id']);
                        $isMissed = $meeting['status'] === 'missed';
                        $modalId = 'attendModal' . (int) $meeting['id'];
                        ?>
                        <li class="list-group-item px-0">
                            <div class="d-flex justify-content-between">
                                <strong><?= e($meeting['title']) ?></strong>
                                <span class="text-muted small">
                                    <?php if ($isMissed): ?>
                                        <span class="badge text-bg-danger me-1"><i class="bi bi-exclamation-triangle-fill"></i> Missed</span>
                                    <?php endif; ?>
                                    <?= e($meeting['meeting_date']) ?><?= $meeting['start_time'] ? ' · ' . e($meeting['start_time']) : '' ?>
                                </span>
                            </div>
                            <?php if ($meeting['location']): ?><div class="text-muted small"><i class="bi bi-geo-alt"></i> <?= e($meeting['location']) ?></div><?php endif; ?>
                            <?php if ($meeting['description']): ?><p class="mb-1 mt-1 small"><?= nl2br(e($meeting['description'])) ?></p><?php endif; ?>
                            <?php if (!empty($attendees)): ?>
                                <div class="small text-muted">Attendees: <?= e(implode(', ', array_column($attendees, 'full_name'))) ?></div>
                            <?php endif; ?>
                            <?php if ($meeting['status'] === 'held'): ?>
                                <div class="small mt-1">
                                    <span class="badge text-bg-success"><i class="bi bi-check2-circle"></i> Attended</span>
                                    <?php if (!empty($meeting['attendance_notes'])): ?>
                                        <div class="text-muted mt-1"><?= nl2br(e($meeting['attendance_notes'])) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($isMissed): ?>
                                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 mt-2"
                                        data-bs-toggle="modal" data-bs-target="#<?= e($modalId) ?>">
                                    <i class="bi bi-arrow-counterclockwise"></i> Mark attended anyway
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline-success btn-sm mt-2 py-0 px-2"
                                        data-bs-toggle="modal" data-bs-target="#<?= e($modalId) ?>">
                                    <i class="bi bi-check2"></i> Confirm attended
                                </button>
                            <?php endif; ?>
                        </li>
                        <?php if ($meeting['status'] !== 'held'): ob_start(); ?>
                            <!-- "Mark attended" popup: kept out of the card body so the list stays
                                 compact — the description field (required server-side too) only
                                 shows once someone actually opens this. -->
                            <div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <form method="POST" action="<?= e(url('hr/meetings.php')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="mark_held">
                                            <input type="hidden" name="meeting_id" value="<?= e((string) $meeting['id']) ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title"><?= e($meeting['title']) ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <?php if ($isMissed): ?>
                                                    <div class="alert alert-danger small py-2 mb-3">
                                                        <i class="bi bi-exclamation-triangle-fill"></i>
                                                        This meeting was auto-marked <strong>Missed</strong> because it wasn't confirmed in time.
                                                    </div>
                                                <?php endif; ?>
                                                <label class="form-label small mb-1">Describe who attended / what was discussed</label>
                                                <textarea name="attendance_notes" class="form-control" rows="3"
                                                          placeholder="e.g. Sonu and Mary attended, discussed pricing follow-up..." required></textarea>
                                                <div class="form-text">Only marked attended once a description is entered here — nothing happens otherwise.</div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-sm <?= $isMissed ? 'btn-danger' : 'btn-success' ?>">
                                                    <i class="bi bi-check2"></i> Confirm attended
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php $attendModals .= ob_get_clean(); endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?= $attendModals ?>

<script nonce="<?= e(csp_nonce()) ?>">
var selectAllBtn = document.getElementById('meetingSelectAll');
if (selectAllBtn) {
    selectAllBtn.addEventListener('click', function () {
        var boxes = document.querySelectorAll('.meeting-attendee-checkbox');
        var allChecked = Array.prototype.every.call(boxes, function (b) { return b.checked; });
        boxes.forEach(function (b) { b.checked = !allChecked; });
        selectAllBtn.textContent = allChecked ? 'Select all' : 'Clear all';
    });
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
