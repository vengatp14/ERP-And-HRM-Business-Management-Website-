<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('leads');

$leadId = (int) ($_GET['id'] ?? 0);
$lead = find_lead($leadId);

if ($lead === false) {
    flash_set('error', 'Lead not found.');
    redirect('leads/index.php');
}

$currentUserId = (int) current_user()['id'];

// Leads are visible to every logged-in user with menu access — no
// per-lead assignment restriction (see leads/index.php).

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        // Deliberately NOT gated behind require_permission('leads','edit') —
        // same pattern as Projects/Clients: an employee who only has
        // leads.view access (and is assigned to this lead) should still be
        // able to move the status forward without needing full edit rights.
        $status = $_POST['status'] ?? '';
        if (in_array($status, ['new','contacted','qualified','proposal','negotiation','won','lost','missed','cancelled'], true)) {
            update_lead($leadId, ['status' => $status]);
            audit_log($currentUserId, 'leads', 'status_update', "Updated lead #{$leadId} status to '{$status}'.");
            flash_set('status', 'Lead status updated.');
        }
        redirect('leads/view.php?id=' . $leadId);
    }

    if ($action === 'log_activity') {
        $type = $_POST['type'] ?? 'note';
        $content = sanitize_string($_POST['content'] ?? '');
        $clientResponse = $_POST['client_response'] ?? '';
        $nextFollowUp = $_POST['next_follow_up_at'] ?? '';

        if (!in_array($type, ['note', 'call', 'whatsapp', 'email', 'follow_up'], true)) {
            flash_set('error', 'Invalid activity type.');
            redirect('leads/view.php?id=' . $leadId);
        }

        log_lead_activity(
            $leadId,
            $currentUserId,
            $type,
            $content !== '' ? $content : null,
            $clientResponse !== '' ? $clientResponse : null,
            $nextFollowUp !== '' ? $nextFollowUp : null
        );

        // A logged follow-up with a clear outcome resets the missed-attempt
        // counter and clears "missed" status back to active — the client
        // did respond, even if the outcome was "call later".
        if ($clientResponse !== '' && in_array($lead['status'], ['missed'], true)) {
            update_lead($leadId, ['status' => 'contacted', 'missed_attempts' => 0]);
        }

        flash_set('status', 'Activity logged.');
        redirect('leads/view.php?id=' . $leadId);
    }

    if ($action === 'edit_activity') {
        $activityId = (int) ($_POST['activity_id'] ?? 0);
        $activity = find_lead_activity($activityId);

        if ($activity === false || (int) $activity['lead_id'] !== $leadId) {
            flash_set('error', 'Follow-up not found.');
            redirect('leads/view.php?id=' . $leadId);
        }

        $content = sanitize_string($_POST['content'] ?? '');
        $clientResponse = $_POST['client_response'] ?? '';
        $nextFollowUp = $_POST['next_follow_up_at'] ?? '';

        update_lead_activity($activityId, $currentUserId, [
            'content' => $content !== '' ? $content : null,
            'client_response' => $clientResponse !== '' ? $clientResponse : null,
            'next_follow_up_at' => $nextFollowUp !== '' ? $nextFollowUp : null,
        ]);

        flash_set('status', 'Follow-up updated.');
        redirect('leads/view.php?id=' . $leadId);
    }

    if ($action === 'schedule_meeting') {
        // Reuses the same meetings table/logic as hr/meetings.php (see
        // includes/meeting_widget.php) — just tied to this lead via
        // lead_id so it shows up in the lead's own meeting history and
        // never overwrites a previous meeting.
        $meetingData = [
            'title' => sanitize_string($_POST['title'] ?? ''),
            'description' => sanitize_string($_POST['description'] ?? '') ?: null,
            'meeting_date' => $_POST['meeting_date'] ?? '',
            'start_time' => $_POST['start_time'] ?? null,
            'end_time' => $_POST['end_time'] ?? null,
            'location' => sanitize_string($_POST['location'] ?? '') ?: null,
            'lead_id' => $leadId,
        ];
        $attendees = array_map('intval', $_POST['attendees'] ?? []);

        $meetingError = validate_required($meetingData['title'], 'Meeting title')
            ?? validate_required($meetingData['meeting_date'], 'Meeting date');

        if ($meetingError !== null) {
            flash_set('error', $meetingError);
            redirect('leads/view.php?id=' . $leadId);
        }

        create_meeting($meetingData, $attendees, $currentUserId);
        log_lead_activity($leadId, $currentUserId, 'note', 'Meeting scheduled: ' . $meetingData['title'] . ' on ' . $meetingData['meeting_date'] . '.');
        flash_set('status', 'Meeting scheduled.');
        redirect('leads/view.php?id=' . $leadId);
    }

    if ($action === 'mark_entity_meeting_held') {
        $meetingId = (int) ($_POST['meeting_id'] ?? 0);
        $notes = sanitize_string($_POST['attendance_notes'] ?? '');

        $meetingStmt = db()->prepare('SELECT id FROM meetings WHERE id = :id AND lead_id = :lead_id');
        $meetingStmt->execute(['id' => $meetingId, 'lead_id' => $leadId]);
        if ($meetingStmt->fetch() === false) {
            flash_set('error', 'Meeting not found for this lead.');
            redirect('leads/view.php?id=' . $leadId);
        }

        if ($notes === '') {
            flash_set('error', 'Add a short note on who attended before marking this meeting held.');
            redirect('leads/view.php?id=' . $leadId);
        }

        mark_meeting_held($meetingId, $notes);
        flash_set('status', 'Meeting marked as held.');
        redirect('leads/view.php?id=' . $leadId);
    }

    if ($action === 'upload_document') {
        $category = ($_POST['doc_category'] ?? 'documents') === 'images' ? 'images' : 'documents';

        if (empty($_FILES['document']) || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash_set('error', 'Please choose a file to upload.');
            redirect('leads/view.php?id=' . $leadId);
        }

        $result = handle_upload($_FILES['document'], $category);

        if (!$result['ok']) {
            flash_set('error', $result['error']);
            redirect('leads/view.php?id=' . $leadId);
        }

        add_lead_document($leadId, $currentUserId, $result);
        log_lead_activity($leadId, $currentUserId, 'note', 'Uploaded document: ' . $result['original_filename']);
        flash_set('status', 'Document uploaded successfully.');
        redirect('leads/view.php?id=' . $leadId);
    }
}

require_once __DIR__ . '/../includes/meeting_widget.php';

$activities = get_lead_activities($leadId);
$documents = get_lead_documents($leadId);
$leadMeetings = get_meetings_for_lead($leadId);

// Only fetch history for activities that have actually been edited —
// avoids an extra query per row for the common case.
$activityHistory = [];
foreach ($activities as $activity) {
    if ($activity['updated_at'] !== null) {
        $activityHistory[$activity['id']] = get_lead_activity_history((int) $activity['id']);
    }
}

$pageTitle = $lead['client_name'];
$activeMenu = 'leads';
$breadcrumbs = [
    ['label' => 'Leads', 'url' => url('leads/index.php')],
    ['label' => $lead['client_name'], 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">
            <?= e($lead['client_name']) ?>
            <span class="badge <?= e(lead_priority_badge_class($lead['priority'])) ?>"><?= e(ucfirst($lead['priority'])) ?> priority</span>
        </h1>
        <form method="POST" action="<?= e(url('leads/view.php?id=' . $leadId)) ?>" class="d-inline-flex align-items-center gap-2 mt-1">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_status">
            <label class="small text-muted mb-0">Status</label>
            <select name="status" class="form-select form-select-sm js-auto-submit" style="width:auto;">
                <?php foreach (['new','contacted','qualified','proposal','negotiation','won','lost','missed','cancelled'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= $lead['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <p class="text-muted mb-0"><?= e($lead['company'] ?? 'No company listed') ?></p>
    </div>
    <?php if (user_can(current_user(), 'leads', 'edit')): ?>
        <a href="<?= e(url('leads/form.php?id=' . $leadId)) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i> Edit</a>
    <?php endif; ?>
</div>

<?php if ($lead['status'] === 'won'): ?>
    <?php
    // Optional Lead -> Convert to Client -> Create Quotation prompt
    // (req #6). Conversion itself already happened automatically in
    // update_lead() above the moment status became 'won' — this is
    // purely an offer, never forced, and safe to show every time the
    // lead is revisited (not just right after the transition).
    $wonClient = find_client_by_source_lead($leadId);
    $wonClientQuotations = $wonClient !== false ? get_quotations_for_client((int) $wonClient['id']) : [];
    ?>
    <?php if ($wonClient !== false && user_can(current_user(), 'quotations', 'add')): ?>
        <div class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <i class="bi bi-check-circle"></i>
                Converted to client <strong><?= e($wonClient['company_name']) ?></strong>.
                <?php if (!empty($wonClientQuotations)): ?>
                    <?= count($wonClientQuotations) ?> quotation<?= count($wonClientQuotations) === 1 ? '' : 's' ?> on file for this client.
                <?php else: ?>
                    No quotation created yet for this client — optional.
                <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= e(url('clients/view.php?id=' . $wonClient['id'])) ?>" class="btn btn-outline-secondary btn-sm">View Client</a>
                <?php if (!empty($wonClientQuotations)): ?>
                    <a href="<?= e(url('quotations/view.php?id=' . $wonClientQuotations[0]['id'])) ?>" class="btn btn-outline-success btn-sm">View Latest Quotation</a>
                <?php endif; ?>
                <a href="<?= e(url('quotations/form.php?client_id=' . $wonClient['id'] . '&lead_id=' . $leadId)) ?>" class="btn btn-success btn-sm">
                    <i class="bi bi-file-earmark-plus"></i> Create Quotation
                </a>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="card mb-4">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Details</h2></div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5">Mobile</dt><dd class="col-7"><?= e($lead['mobile']) ?></dd>
                    <dt class="col-5">WhatsApp</dt><dd class="col-7"><?= !empty($lead['whatsapp']) ? e($lead['whatsapp']) : '<span class="text-muted">Not Provided</span>' ?></dd>
                    <dt class="col-5">Email</dt><dd class="col-7"><?= !empty($lead['email']) ? e($lead['email']) : '<span class="text-muted">Not Provided</span>' ?></dd>
                    <dt class="col-5">Address</dt><dd class="col-7"><?= !empty($lead['address']) ? nl2br(e($lead['address'])) : '<span class="text-muted">Not Mentioned</span>' ?></dd>
                    <dt class="col-5">GST No.</dt><dd class="col-7"><?= !empty($lead['gst_number']) ? e($lead['gst_number']) : '<span class="badge text-bg-secondary">Non-GST</span>' ?></dd>
                    <dt class="col-5">PAN No.</dt><dd class="col-7"><?= !empty($lead['pan_number']) ? e($lead['pan_number']) : '<span class="text-muted">Not Provided</span>' ?></dd>
                    <dt class="col-5">Budget</dt><dd class="col-7"><?= $lead['budget'] !== null ? '₹' . e(number_format((float) $lead['budget'], 2)) : '<span class="text-muted">Not Specified</span>' ?></dd>
                    <dt class="col-5">Project Type</dt><dd class="col-7"><?= !empty($lead['project_type']) ? e($lead['project_type']) : '<span class="text-muted">Not Specified</span>' ?></dd>
                    <dt class="col-5">Source</dt><dd class="col-7"><?= e(ucwords(str_replace('_', ' ', $lead['source']))) ?></dd>
                    <dt class="col-5">Missed Attempts</dt><dd class="col-7"><?= e((string) $lead['missed_attempts']) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Quick Follow-up</h2></div>
            <div class="card-body">
                <div class="d-flex gap-2 mb-3">
                    <?php
                    $waNumber = preg_replace('/\D/', '', $lead['whatsapp'] ?? $lead['mobile']);
                    $callNumber = preg_replace('/\D/', '', $lead['mobile']);
                    ?>
                    <a href="tel:<?= e($callNumber) ?>" class="btn btn-outline-success btn-sm flex-fill js-followup-trigger" data-type="call">
                        <i class="bi bi-telephone"></i> Call
                    </a>
                    <a href="https://wa.me/<?= e($waNumber) ?>" target="_blank" rel="noopener" class="btn btn-outline-success btn-sm flex-fill js-followup-trigger" data-type="whatsapp">
                        <i class="bi bi-whatsapp"></i> WhatsApp
                    </a>
                    <?php if (!empty($lead['email'])): ?>
                        <a href="mailto:<?= e($lead['email']) ?>" class="btn btn-outline-success btn-sm flex-fill js-followup-trigger" data-type="email">
                            <i class="bi bi-envelope"></i> Email
                        </a>
                    <?php endif; ?>
                </div>
                <p class="text-muted small mb-0">
                    After using one of these, a prompt will pop up shortly asking how it went — so the
                    follow-up gets logged even if you forget to fill in the form below.
                </p>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Log Follow-up</h2></div>
            <div class="card-body">
                <form method="POST" action="<?= e(url('leads/view.php?id=' . $leadId)) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="log_activity">

                    <div class="mb-2">
                        <label class="form-label small">Type</label>
                        <select name="type" class="form-select form-select-sm">
                            <option value="call">Call</option>
                            <option value="whatsapp">WhatsApp</option>
                            <option value="email">Email</option>
                            <option value="note">Note</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Client Response</label>
                        <select name="client_response" class="form-select form-select-sm">
                            <option value="">— Not applicable —</option>
                            <option value="interested">Interested</option>
                            <option value="busy">Busy</option>
                            <option value="call_later">Call Later</option>
                            <option value="meeting_fixed">Meeting Fixed</option>
                            <option value="not_interested">Not Interested</option>
                            <option value="no_response">No Response</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Notes</label>
                        <textarea name="content" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Next Follow-up</label>
                        <input type="datetime-local" name="next_follow_up_at" class="form-control form-control-sm">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">Save</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Documents</h2></div>
            <div class="card-body">
                <form method="POST" action="<?= e(url('leads/view.php?id=' . $leadId)) ?>" enctype="multipart/form-data" class="mb-3" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_document">
                    <div class="mb-2">
                        <select name="doc_category" class="form-select form-select-sm">
                            <option value="documents">Document (PDF/Word/Excel)</option>
                            <option value="images">Image</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <input type="file" name="document" class="form-control form-control-sm" required>
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm w-100">Upload</button>
                </form>

                <ul class="list-group list-group-flush small">
                    <?php if (empty($documents)): ?>
                        <li class="list-group-item text-muted px-0">No documents uploaded yet.</li>
                    <?php endif; ?>
                    <?php foreach ($documents as $doc): ?>
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                            <a href="<?= e(url('leads/document-download.php?id=' . $doc['id'])) ?>" class="text-truncate" style="max-width: 180px;">
                                <i class="bi bi-file-earmark-arrow-down me-1"></i><?= e($doc['original_filename']) ?>
                            </a>
                            <span class="text-muted"><?= e(human_file_size((int) $doc['file_size_bytes'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <?= render_entity_meetings_card($leadMeetings, url('leads/view.php?id=' . $leadId), 'lead') ?>

        <div class="card w-100">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Timeline</h2></div>
            <div class="card-body">
                <?php if (empty($activities)): ?>
                    <p class="text-muted mb-0">No activity yet.</p>
                <?php endif; ?>
                <?php if (!empty($activities)): ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th style="width:110px;">Type</th>
                                <th>Description</th>
                                <th style="width:150px;">Response</th>
                                <th style="width:170px;">Next Follow-up</th>
                                <th style="width:170px;">Date</th>
                                <th style="width:110px;" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activities as $activity): ?>
                                <tr>
                                    <td>
                                        <span class="text-capitalize"><?= e(str_replace('_', ' ', $activity['type'])) ?></span>
                                        <div class="text-muted small"><?= $activity['user_name'] ? e($activity['user_name']) : 'System' ?></div>
                                    </td>
                                    <td><?= $activity['content'] ? nl2br(e($activity['content'])) : '<span class="text-muted">—</span>' ?></td>
                                    <td>
                                        <?php if ($activity['client_response']): ?>
                                            <span class="badge text-bg-light border"><?= e(str_replace('_', ' ', $activity['client_response'])) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $activity['next_follow_up_at'] ? e($activity['next_follow_up_at']) : '<span class="text-muted">—</span>' ?></td>
                                    <td>
                                        <div><?= e($activity['created_at']) ?></div>
                                        <?php if ($activity['updated_at']): ?>
                                            <div class="text-muted small">Edited <?= e($activity['updated_at']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-secondary js-edit-activity"
                                                data-id="<?= e((string) $activity['id']) ?>"
                                                data-content="<?= e($activity['content'] ?? '') ?>"
                                                data-response="<?= e($activity['client_response'] ?? '') ?>"
                                                data-next="<?= e($activity['next_follow_up_at'] ? date('Y-m-d\TH:i', strtotime($activity['next_follow_up_at'])) : '') ?>"
                                                title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php if (!empty($activityHistory[$activity['id']])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary js-view-history"
                                                    data-history='<?= e(json_encode($activityHistory[$activity['id']])) ?>'
                                                    title="Edit history">
                                                <i class="bi bi-clock-history"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Edit follow-up modal: one shared modal, filled via JS from the row's data-* attrs -->
<div class="modal fade" id="editActivityModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= e(url('leads/view.php?id=' . $leadId)) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_activity">
                <input type="hidden" name="activity_id" id="editActivityId" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Follow-up</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label small">Client Response</label>
                        <select name="client_response" id="editActivityResponse" class="form-select form-select-sm">
                            <option value="">— Not applicable —</option>
                            <option value="interested">Interested</option>
                            <option value="busy">Busy</option>
                            <option value="call_later">Call Later</option>
                            <option value="meeting_fixed">Meeting Fixed</option>
                            <option value="not_interested">Not Interested</option>
                            <option value="no_response">No Response</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Description</label>
                        <textarea name="content" id="editActivityContent" class="form-control form-control-sm" rows="3"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Next Follow-up</label>
                        <input type="datetime-local" name="next_follow_up_at" id="editActivityNext" class="form-control form-control-sm">
                    </div>
                    <p class="text-muted small mb-0">Saving records the edit date automatically and keeps the previous version in this follow-up's history.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- History modal: populated via JS from the row's data-history JSON -->
<div class="modal fade" id="activityHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <ul class="list-unstyled mb-0" id="activityHistoryList"></ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Delayed follow-up outcome popup: shown 10-30s after Call/WhatsApp/Email is used -->
<div class="modal fade" id="followupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= e(url('leads/view.php?id=' . $leadId)) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="log_activity">
                <input type="hidden" name="type" id="followupModalType" value="call">
                <div class="modal-header">
                    <h5 class="modal-title">How did it go?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small" id="followupModalIntro">Following up on your recent contact attempt.</p>
                    <div class="mb-2">
                        <label class="form-label small">Client Response</label>
                        <select name="client_response" class="form-select form-select-sm" required>
                            <option value="">Select an outcome…</option>
                            <option value="interested">Interested</option>
                            <option value="busy">Busy</option>
                            <option value="call_later">Call Later</option>
                            <option value="meeting_fixed">Meeting Fixed</option>
                            <option value="not_interested">Not Interested</option>
                            <option value="no_response">No Response</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Notes</label>
                        <textarea name="content" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Next Follow-up</label>
                        <input type="datetime-local" name="next_follow_up_at" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Not Now</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save Follow-up</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    var modalEl = document.getElementById('followupModal');
    var modal = new bootstrap.Modal(modalEl);
    var typeLabels = { call: 'phone call', whatsapp: 'WhatsApp message', email: 'email' };

    document.querySelectorAll('.js-followup-trigger').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var type = btn.getAttribute('data-type');
            var delayMs = 10000 + Math.floor(Math.random() * 20000); // 10–30 seconds
            setTimeout(function () {
                document.getElementById('followupModalType').value = type;
                document.getElementById('followupModalIntro').textContent =
                    'Following up on your recent ' + (typeLabels[type] || 'contact attempt') + '.';
                modal.show();
            }, delayMs);
        });
    });
})();

(function () {
    var editModalEl = document.getElementById('editActivityModal');
    var editModal = new bootstrap.Modal(editModalEl);

    document.querySelectorAll('.js-edit-activity').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('editActivityId').value = btn.dataset.id;
            document.getElementById('editActivityContent').value = btn.dataset.content || '';
            document.getElementById('editActivityResponse').value = btn.dataset.response || '';
            document.getElementById('editActivityNext').value = btn.dataset.next || '';
            editModal.show();
        });
    });

    var historyModalEl = document.getElementById('activityHistoryModal');
    var historyModal = new bootstrap.Modal(historyModalEl);
    var historyList = document.getElementById('activityHistoryList');
    var responseLabels = {
        interested: 'Interested', busy: 'Busy', call_later: 'Call Later',
        meeting_fixed: 'Meeting Fixed', not_interested: 'Not Interested', no_response: 'No Response'
    };

    document.querySelectorAll('.js-view-history').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var history;
            try {
                history = JSON.parse(btn.dataset.history);
            } catch (e) {
                history = [];
            }
            historyList.innerHTML = '';
            history.forEach(function (entry) {
                var li = document.createElement('li');
                li.className = 'border-start ps-3 pb-3 border-2';
                var who = entry.changed_by_name || 'System';
                var respLabel = entry.old_client_response ? (responseLabels[entry.old_client_response] || entry.old_client_response) : null;
                li.innerHTML =
                    '<div class="text-muted small">Before ' + entry.changed_at + ' — edited by ' + who + '</div>' +
                    (entry.old_content ? '<p class="mb-1 mt-1">' + entry.old_content.replace(/</g, '&lt;').replace(/\n/g, '<br>') + '</p>' : '<p class="mb-1 mt-1 text-muted">(no description)</p>') +
                    (respLabel ? '<span class="badge text-bg-light border me-1">Response: ' + respLabel + '</span>' : '') +
                    (entry.old_next_follow_up_at ? '<span class="badge text-bg-light border">Next follow-up: ' + entry.old_next_follow_up_at + '</span>' : '');
                historyList.appendChild(li);
            });
            if (history.length === 0) {
                historyList.innerHTML = '<li class="text-muted">No earlier versions.</li>';
            }
            historyModal.show();
        });
    });
})();

document.querySelectorAll('.js-auto-submit').forEach(function (select) {
    select.addEventListener('change', function () { select.form.submit(); });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
