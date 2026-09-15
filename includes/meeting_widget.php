<?php

declare(strict_types=1);

/**
 * includes/meeting_widget.php
 * Shared "Meetings" card for leads/view.php and clients/view.php —
 * shows the full meeting history for that lead/client (see
 * get_meetings_for_lead()/get_meetings_for_client() in includes/hr.php)
 * and a "Schedule Next Meeting" form. Reuses the same meetings table,
 * create_meeting()/mark_meeting_held() functions, and "mark attended"
 * modal pattern already used by hr/meetings.php — nothing here
 * duplicates that logic, it only reuses it in a lead/client context.
 *
 * Each call to "Schedule Next Meeting" inserts a brand-new meeting row
 * (create_meeting() never updates an existing one), so a lead/client
 * naturally builds up a full history: past/completed meetings stay
 * exactly as they are while new ones are added.
 *
 * The including page must:
 *   - handle POST action 'schedule_meeting' (title, meeting_date,
 *     start_time, end_time, location, attendees[]) by calling
 *     create_meeting() with lead_id/client_id set from the page's own
 *     $leadId/$clientId (never from posted input, to prevent scheduling
 *     against a different lead/client than the one being viewed)
 *   - handle POST action 'mark_entity_meeting_held' (meeting_id,
 *     attendance_notes) by confirming the meeting belongs to this
 *     lead/client, then calling mark_meeting_held()
 */
function render_entity_meetings_card(array $meetings, string $postUrl, string $idPrefix): string
{
    $employees = db()->query("SELECT id, full_name FROM users WHERE status = 'active' ORDER BY full_name")->fetchAll();

    ob_start();
    ?>
    <div class="card mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Meetings</h2>
            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse"
                    data-bs-target="#<?= e($idPrefix) ?>ScheduleMeetingForm">
                <i class="bi bi-calendar-plus"></i> Schedule Next Meeting
            </button>
        </div>
        <div class="card-body">
            <div class="collapse mb-3" id="<?= e($idPrefix) ?>ScheduleMeetingForm">
                <form method="POST" action="<?= e($postUrl) ?>" novalidate class="border rounded p-3 bg-light-subtle">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="schedule_meeting">
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
                    <div class="mb-2">
                        <label class="form-label small mb-1">Attendees (optional)</label>
                        <div class="border rounded p-2 bg-white" style="max-height: 140px; overflow-y: auto;">
                            <?php foreach ($employees as $emp): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="attendees[]"
                                           value="<?= e((string) $emp['id']) ?>" id="<?= e($idPrefix) ?>-attendee-<?= e((string) $emp['id']) ?>">
                                    <label class="form-check-label small" for="<?= e($idPrefix) ?>-attendee-<?= e((string) $emp['id']) ?>">
                                        <?= e($emp['full_name']) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">Schedule Meeting</button>
                </form>
            </div>

            <?php if (empty($meetings)): ?>
                <p class="text-muted mb-0">No meetings scheduled yet.</p>
            <?php endif; ?>

            <ul class="list-group list-group-flush">
                <?php $attendModals = ''; ?>
                <?php foreach ($meetings as $meeting): ?>
                    <?php
                    $attendees = get_meeting_attendees((int) $meeting['id']);
                    $isMissed = $meeting['status'] === 'missed';
                    $modalId = $idPrefix . 'AttendModal' . (int) $meeting['id'];
                    ?>
                    <li class="list-group-item px-0">
                        <div class="d-flex justify-content-between">
                            <strong><?= e($meeting['title']) ?></strong>
                            <span class="text-muted small">
                                <?php if ($meeting['status'] === 'held'): ?>
                                    <span class="badge text-bg-success me-1">Completed</span>
                                <?php elseif ($isMissed): ?>
                                    <span class="badge text-bg-danger me-1"><i class="bi bi-exclamation-triangle-fill"></i> Missed</span>
                                <?php else: ?>
                                    <span class="badge text-bg-info me-1">Scheduled</span>
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
                            <?php if (!empty($meeting['attendance_notes'])): ?>
                                <div class="small text-muted mt-1"><?= nl2br(e($meeting['attendance_notes'])) ?></div>
                            <?php endif; ?>
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
                        <div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <form method="POST" action="<?= e($postUrl) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="mark_entity_meeting_held">
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
                                            <textarea name="attendance_notes" class="form-control" rows="3" required></textarea>
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
    <?= $attendModals ?? '' ?>
    <?php
    return ob_get_clean();
}
