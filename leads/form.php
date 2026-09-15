<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
// permission check happens below once we know if this is add or edit

$leadId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$lead = $leadId !== null ? find_lead($leadId) : null;

if ($leadId !== null && $lead === false) {
    flash_set('error', 'Lead not found.');
    redirect('leads/index.php');
}

require_permission('leads', $leadId !== null ? 'edit' : 'add');

$isEdit = $lead !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $data = [
        'client_name' => sanitize_string($_POST['client_name'] ?? ''),
        'company' => sanitize_string($_POST['company'] ?? '') ?: null,
        'mobile' => sanitize_string($_POST['mobile'] ?? ''),
        'whatsapp' => sanitize_string($_POST['whatsapp'] ?? '') ?: null,
        'email' => trim((string) ($_POST['email'] ?? '')) ?: null,
        'address' => sanitize_string($_POST['address'] ?? '') ?: null,
        'gst_number' => strtoupper(sanitize_string($_POST['gst_number'] ?? '')) ?: null,
        'pan_number' => strtoupper(sanitize_string($_POST['pan_number'] ?? '')) ?: null,
        'budget' => ($_POST['budget'] ?? '') !== '' ? (float) $_POST['budget'] : null,
        'project_type' => sanitize_string($_POST['project_type'] ?? '') ?: null,
        'priority' => $_POST['priority'] ?? 'medium',
        'source' => $_POST['source'] ?? 'other',
        'status' => $_POST['status'] ?? 'new',
    ];

    $error = validate_required($data['client_name'], 'Client name')
        ?? validate_required($data['mobile'], 'Mobile number');

    if ($error === null && $data['email'] !== null && $data['email'] !== '') {
        $error = validate_email($data['email']);
    } elseif ($data['email'] === '') {
        $data['email'] = null;
    }

    if ($error === null && !in_array($data['priority'], ['low', 'medium', 'high'], true)) {
        $error = 'Invalid priority.';
    }
    if ($error === null && !in_array($data['status'], ['new','contacted','qualified','proposal','negotiation','won','lost','missed','cancelled'], true)) {
        $error = 'Invalid status.';
    }

    if ($error !== null) {
        flash_set('error', $error);
        redirect('leads/form.php' . ($isEdit ? '?id=' . $leadId : ''));
    }

    $currentUserId = (int) current_user()['id'];

    if ($isEdit) {
        update_lead($leadId, $data);
        log_lead_activity($leadId, $currentUserId, 'note', 'Lead details updated.');
        flash_set('status', 'Lead updated successfully.');
        redirect('leads/view.php?id=' . $leadId);
    }

    $newId = create_lead($data, $currentUserId);
    log_lead_activity($newId, $currentUserId, 'note', 'Lead created.');

    // Optional meeting scheduling at creation time — reuses the same
    // meetings table/logic as hr/meetings.php and the lead's own
    // "Schedule Next Meeting" widget (see includes/meeting_widget.php).
    // Entirely optional: the lead is already saved above regardless of
    // whether meeting details were provided.
    $scheduleMeeting = ($_POST['schedule_meeting'] ?? 'no') === 'yes';
    $meetingDate = trim((string) ($_POST['meeting_date'] ?? ''));
    if ($scheduleMeeting && $meetingDate !== '') {
        $meetingData = [
            'title' => 'Meeting with ' . $data['client_name'],
            'description' => sanitize_string($_POST['meeting_notes'] ?? '') ?: null,
            'meeting_date' => $meetingDate,
            'start_time' => $_POST['meeting_time'] ?? null,
            'end_time' => null,
            'location' => sanitize_string($_POST['meeting_location'] ?? '') ?: null,
            'lead_id' => $newId,
        ];
        $meetingAttendees = array_map('intval', $_POST['meeting_attendees'] ?? []);
        create_meeting($meetingData, $meetingAttendees, $currentUserId);
        log_lead_activity($newId, $currentUserId, 'note', 'Meeting scheduled: ' . $meetingData['title'] . ' on ' . $meetingDate . '.');
        flash_set('status', 'Lead created and meeting scheduled successfully.');
    } else {
        flash_set('status', 'Lead created successfully.');
    }

    redirect('leads/view.php?id=' . $newId);
}

$pageTitle = $isEdit ? 'Edit Lead' : 'Add Lead';
$activeMenu = 'leads';
$breadcrumbs = [
    ['label' => 'Leads', 'url' => url('leads/index.php')],
    ['label' => $isEdit ? 'Edit' : 'Add', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="card">
    <div class="card-header bg-white"><h2 class="h6 mb-0"><?= e($pageTitle) ?></h2></div>
    <div class="card-body">
        <form method="POST" action="<?= e(url('leads/form.php' . ($isEdit ? '?id=' . $leadId : ''))) ?>" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label">Client Name *</label>
                    <input type="text" name="client_name" class="form-control" required
                           value="<?= e($lead['client_name'] ?? '') ?>">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Company</label>
                    <input type="text" name="company" class="form-control" value="<?= e($lead['company'] ?? '') ?>">
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">Mobile *</label>
                    <input type="tel" name="mobile" class="form-control" required value="<?= e($lead['mobile'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">WhatsApp</label>
                    <input type="tel" name="whatsapp" class="form-control" value="<?= e($lead['whatsapp'] ?? '') ?>">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= e($lead['email'] ?? '') ?>">
                </div>

                <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2"><?= e($lead['address'] ?? '') ?></textarea>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">GST Number</label>
                    <input type="text" name="gst_number" class="form-control text-uppercase" value="<?= e($lead['gst_number'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">PAN Number</label>
                    <input type="text" name="pan_number" class="form-control text-uppercase" value="<?= e($lead['pan_number'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Budget (₹)</label>
                    <input type="number" step="0.01" name="budget" class="form-control" value="<?= e((string) ($lead['budget'] ?? '')) ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Project Type</label>
                    <input type="text" name="project_type" class="form-control" value="<?= e($lead['project_type'] ?? '') ?>">
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">Priority</label>
                    <select name="priority" class="form-select">
                        <?php foreach (['low','medium','high'] as $p): ?>
                            <option value="<?= e($p) ?>" <?= ($lead['priority'] ?? 'medium') === $p ? 'selected' : '' ?>><?= e(ucfirst($p)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Source</label>
                    <select name="source" class="form-select">
                        <?php foreach (['website','referral','social_media','cold_call','walk_in','advertisement','other'] as $s): ?>
                            <option value="<?= e($s) ?>" <?= ($lead['source'] ?? 'other') === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" id="leadStatus" class="form-select" data-original-status="<?= e($lead['status'] ?? 'new') ?>">
                        <?php foreach (['new','contacted','qualified','proposal','negotiation','won','lost','missed','cancelled'] as $s): ?>
                            <option value="<?= e($s) ?>" <?= ($lead['status'] ?? 'new') === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if (!$isEdit): ?>
                <?php $employees = db()->query("SELECT id, full_name FROM users WHERE status = 'active' ORDER BY full_name")->fetchAll(); ?>
                <div class="mt-4 border rounded p-3">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="scheduleMeetingToggle" name="schedule_meeting" value="yes">
                        <label class="form-check-label fw-semibold" for="scheduleMeetingToggle">Schedule Meeting?</label>
                    </div>
                    <div class="form-text mb-2">Optional — the lead is created either way. Fill this in only if you'd like to also schedule a meeting right away.</div>
                    <div id="scheduleMeetingFields" class="d-none">
                        <div class="row g-2 mb-2">
                            <div class="col-6 col-md-3">
                                <label class="form-label small">Meeting Date</label>
                                <input type="date" name="meeting_date" class="form-control form-control-sm">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small">Meeting Time</label>
                                <input type="time" name="meeting_time" class="form-control form-control-sm">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label small">Location / Link</label>
                                <input type="text" name="meeting_location" class="form-control form-control-sm">
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">Meeting Notes</label>
                            <textarea name="meeting_notes" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                        <div class="mb-1">
                            <label class="form-label small mb-1">Attendees (optional)</label>
                            <div class="border rounded p-2 bg-white" style="max-height: 140px; overflow-y: auto;">
                                <?php foreach ($employees as $emp): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="meeting_attendees[]"
                                               value="<?= e((string) $emp['id']) ?>" id="meeting-attendee-<?= e((string) $emp['id']) ?>">
                                        <label class="form-check-label small" for="meeting-attendee-<?= e((string) $emp['id']) ?>">
                                            <?= e($emp['full_name']) ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Lead' ?></button>
                <a href="<?= e(url('leads/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    var toggle = document.getElementById('scheduleMeetingToggle');
    var fields = document.getElementById('scheduleMeetingFields');
    if (!toggle || !fields) {
        return;
    }
    toggle.addEventListener('change', function () {
        fields.classList.toggle('d-none', !toggle.checked);
    });
})();

// Marking a lead Won auto-creates a Client record from its details (see
// convert_won_lead_to_client() in includes/leads.php) — confirm before
// that fires, since it's a one-way trigger the first time status flips
// to Won.
(function () {
    var form = document.querySelector('form[action*="leads/form.php"]');
    var statusSelect = document.getElementById('leadStatus');
    if (!form || !statusSelect) {
        return;
    }
    form.addEventListener('submit', function (e) {
        var originalStatus = statusSelect.getAttribute('data-original-status');
        if (statusSelect.value === 'won' && originalStatus !== 'won') {
            var ok = confirm('Mark this lead as Won? This will automatically create a Client record from its details.');
            if (!ok) {
                e.preventDefault();
            }
        }
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
