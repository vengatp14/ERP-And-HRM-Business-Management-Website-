<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('clients');

$clientId = (int) ($_GET['id'] ?? 0);
$client = find_client($clientId);

if ($client === false) {
    flash_set('error', 'Client not found.');
    redirect('clients/index.php');
}

$currentUserId = (int) current_user()['id'];

// Employees can only open clients tied to them (via an assigned lead or
// a project they're on) — even via a direct link. Admins bypass this,
// same pattern as Projects.
if (!is_admin_role(current_user()) && !user_is_assigned_to_client($clientId, $currentUserId)) {
    http_response_code(403);
    require __DIR__ . '/../errors/403.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        // Deliberately NOT gated behind require_permission('clients','edit') —
        // same pattern as the Projects status control: an employee who only
        // has clients.view access (and is assigned to this client) should
        // still be able to move the status forward without needing full
        // edit rights to billing address, GSTIN, etc.
        $status = $_POST['status'] ?? '';
        if (in_array($status, client_status_options(), true)) {
            update_client($clientId, ['status' => $status]);
            audit_log($currentUserId, 'clients', 'status_update', "Updated client #{$clientId} status to '{$status}'.");
            flash_set('status', 'Client status updated.');
        }
        redirect('clients/view.php?id=' . $clientId);
    }

    if ($action === 'mark_contacted') {
        mark_client_contacted($clientId);
        flash_set('status', 'Client marked contacted.');
        redirect('clients/view.php?id=' . $clientId);
    }

    if ($action === 'add_note') {
        $content = sanitize_string($_POST['content'] ?? '');
        if ($content === '') {
            flash_set('error', 'Note cannot be empty.');
            redirect('clients/view.php?id=' . $clientId);
        }
        add_client_note($clientId, $currentUserId, $content);
        flash_set('status', 'Note added.');
        redirect('clients/view.php?id=' . $clientId);
    }

    if ($action === 'edit_note') {
        $noteId = (int) ($_POST['note_id'] ?? 0);
        $note = find_client_note($noteId);

        if ($note === false || (int) $note['client_id'] !== $clientId) {
            flash_set('error', 'Note not found.');
            redirect('clients/view.php?id=' . $clientId);
        }

        $content = sanitize_string($_POST['content'] ?? '');
        if ($content === '') {
            flash_set('error', 'Description cannot be empty.');
            redirect('clients/view.php?id=' . $clientId);
        }

        update_client_note($noteId, $currentUserId, $content);
        flash_set('status', 'Description updated.');
        redirect('clients/view.php?id=' . $clientId);
    }

    if ($action === 'schedule_meeting') {
        // Reuses the same meetings table/logic as hr/meetings.php (see
        // includes/meeting_widget.php) — just tied to this client via
        // client_id so it shows up in the client's own meeting history
        // and never overwrites a previous meeting.
        $meetingData = [
            'title' => sanitize_string($_POST['title'] ?? ''),
            'description' => sanitize_string($_POST['description'] ?? '') ?: null,
            'meeting_date' => $_POST['meeting_date'] ?? '',
            'start_time' => $_POST['start_time'] ?? null,
            'end_time' => $_POST['end_time'] ?? null,
            'location' => sanitize_string($_POST['location'] ?? '') ?: null,
            'client_id' => $clientId,
        ];
        $attendees = array_map('intval', $_POST['attendees'] ?? []);

        $meetingError = validate_required($meetingData['title'], 'Meeting title')
            ?? validate_required($meetingData['meeting_date'], 'Meeting date');

        if ($meetingError !== null) {
            flash_set('error', $meetingError);
            redirect('clients/view.php?id=' . $clientId);
        }

        create_meeting($meetingData, $attendees, $currentUserId);
        add_client_note($clientId, $currentUserId, 'Meeting scheduled: ' . $meetingData['title'] . ' on ' . $meetingData['meeting_date'] . '.');
        flash_set('status', 'Meeting scheduled.');
        redirect('clients/view.php?id=' . $clientId);
    }

    if ($action === 'mark_entity_meeting_held') {
        $meetingId = (int) ($_POST['meeting_id'] ?? 0);
        $notes = sanitize_string($_POST['attendance_notes'] ?? '');

        $meetingStmt = db()->prepare('SELECT id FROM meetings WHERE id = :id AND client_id = :client_id');
        $meetingStmt->execute(['id' => $meetingId, 'client_id' => $clientId]);
        if ($meetingStmt->fetch() === false) {
            flash_set('error', 'Meeting not found for this client.');
            redirect('clients/view.php?id=' . $clientId);
        }

        if ($notes === '') {
            flash_set('error', 'Add a short note on who attended before marking this meeting held.');
            redirect('clients/view.php?id=' . $clientId);
        }

        mark_meeting_held($meetingId, $notes);
        flash_set('status', 'Meeting marked as held.');
        redirect('clients/view.php?id=' . $clientId);
    }

    if ($action === 'add_contact') {
        $name = sanitize_string($_POST['name'] ?? '');
        if ($name === '') {
            flash_set('error', 'Contact name is required.');
            redirect('clients/view.php?id=' . $clientId);
        }
        add_client_contact($clientId, [
            'name' => $name,
            'designation' => sanitize_string($_POST['designation'] ?? '') ?: null,
            'email' => trim((string) ($_POST['email'] ?? '')) ?: null,
            'mobile' => sanitize_string($_POST['mobile'] ?? '') ?: null,
        ]);
        add_client_note($clientId, $currentUserId, "Added contact: {$name}");
        flash_set('status', 'Contact added.');
        redirect('clients/view.php?id=' . $clientId);
    }

    if ($action === 'delete_contact') {
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        delete_client_contact($contactId, $clientId);
        flash_set('status', 'Contact removed.');
        redirect('clients/view.php?id=' . $clientId);
    }
}

require_once __DIR__ . '/../includes/meeting_widget.php';

$notes = get_client_notes($clientId);
$contacts = get_client_contacts($clientId);
$clientMeetings = get_meetings_for_client($clientId);

// Only fetch history for notes that have actually been edited.
$noteHistory = [];
foreach ($notes as $note) {
    if ($note['updated_at'] !== null) {
        $noteHistory[$note['id']] = get_client_note_history((int) $note['id']);
    }
}

$pageTitle = $client['company_name'];
$activeMenu = 'clients';
$breadcrumbs = [
    ['label' => 'Clients', 'url' => url('clients/index.php')],
    ['label' => $client['company_name'], 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1"><?= e($client['company_name']) ?></h1>
        <form method="POST" action="<?= e(url('clients/view.php?id=' . $clientId)) ?>" class="d-inline-flex align-items-center gap-2 mt-1">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_status">
            <label class="small text-muted mb-0">Status</label>
            <select name="status" class="form-select form-select-sm js-auto-submit" style="width:auto;">
                <?php foreach (client_status_options() as $s): ?>
                    <option value="<?= e($s) ?>" <?= $client['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <p class="text-muted mb-0"><?= e($client['contact_name']) ?></p>
    </div>
    <?php if (user_can(current_user(), 'clients', 'edit')): ?>
        <a href="<?= e(url('clients/form.php?id=' . $clientId)) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i> Edit</a>
    <?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="card mb-4">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Details</h2></div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5">Mobile</dt><dd class="col-7"><?= e($client['mobile']) ?></dd>
                    <dt class="col-5">WhatsApp</dt><dd class="col-7"><?= e($client['whatsapp'] ?? '—') ?></dd>
                    <dt class="col-5">Email</dt><dd class="col-7"><?= e($client['email'] ?? '—') ?></dd>
                    <dt class="col-5">GSTIN</dt><dd class="col-7"><?= e($client['gstin'] ?? '—') ?></dd>
                    <dt class="col-5">PAN No.</dt><dd class="col-7"><?= e($client['pan_number'] ?? '—') ?></dd>
                    <dt class="col-5">Billing Address</dt><dd class="col-7"><?= nl2br(e($client['billing_address'] ?? '—')) ?></dd>
                    <dt class="col-5">City</dt><dd class="col-7"><?= e($client['city'] ?? '—') ?></dd>
                    <dt class="col-5">State</dt><dd class="col-7"><?= e($client['state'] ?? '—') ?></dd>
                    <dt class="col-5">Pincode</dt><dd class="col-7"><?= e($client['pincode'] ?? '—') ?></dd>
                    <dt class="col-5">Added By</dt><dd class="col-7"><?= e($client['created_by_name'] ?? '—') ?></dd>
                    <dt class="col-5">Assigned To</dt><dd class="col-7"><?= e($client['assigned_to_name'] ?? '—') ?></dd>
                    <dt class="col-5">Next Contact</dt>
                    <dd class="col-7">
                        <?php if (!empty($client['next_contact_at'])): ?>
                            <span class="badge <?= e(client_contact_status_badge_class($client['contact_status'])) ?>">
                                <?= e(date('d M Y, h:i A', strtotime($client['next_contact_at']))) ?>
                                · <?= e(ucfirst($client['contact_status'])) ?>
                            </span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </dd>
                </dl>
                <?php if (!empty($client['next_contact_at']) && $client['contact_status'] !== 'contacted'): ?>
                    <form method="POST" action="<?= e(url('clients/view.php?id=' . $clientId)) ?>" class="mt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mark_contacted">
                        <button type="submit" class="btn btn-outline-success btn-sm w-100">
                            <i class="bi bi-check2"></i> Mark Contacted
                        </button>
                    </form>
                <?php endif; ?>
                <?php if (!empty($client['notes'])): ?>
                    <hr>
                    <div class="text-muted"><?= nl2br(e($client['notes'])) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Additional Contacts</h2>
            </div>
            <div class="card-body">
                <ul class="list-group list-group-flush small mb-3">
                    <?php if (empty($contacts)): ?>
                        <li class="list-group-item text-muted px-0">No additional contacts yet.</li>
                    <?php endif; ?>
                    <?php foreach ($contacts as $contact): ?>
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-start">
                            <div>
                                <strong><?= e($contact['name']) ?></strong>
                                <?php if ($contact['designation']): ?><span class="text-muted"> — <?= e($contact['designation']) ?></span><?php endif; ?>
                                <div class="text-muted"><?= e($contact['mobile'] ?? '') ?> <?= e($contact['email'] ?? '') ?></div>
                            </div>
                            <form method="POST" action="<?= e(url('clients/view.php?id=' . $clientId)) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_contact">
                                <input type="hidden" name="contact_id" value="<?= e((string) $contact['id']) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <form method="POST" action="<?= e(url('clients/view.php?id=' . $clientId)) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_contact">
                    <div class="mb-2">
                        <input type="text" name="name" class="form-control form-control-sm" placeholder="Name" required>
                    </div>
                    <div class="mb-2">
                        <input type="text" name="designation" class="form-control form-control-sm" placeholder="Designation">
                    </div>
                    <div class="mb-2">
                        <input type="tel" name="mobile" class="form-control form-control-sm" placeholder="Mobile">
                    </div>
                    <div class="mb-2">
                        <input type="email" name="email" class="form-control form-control-sm" placeholder="Email">
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm w-100">Add Contact</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <?= render_entity_meetings_card($clientMeetings, url('clients/view.php?id=' . $clientId), 'client') ?>

        <div class="card mb-4">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Add Note</h2></div>
            <div class="card-body">
                <form method="POST" action="<?= e(url('clients/view.php?id=' . $clientId)) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_note">
                    <div class="mb-2">
                        <textarea name="content" class="form-control form-control-sm" rows="2" placeholder="Log a call, meeting, or update..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Save Note</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Description</h2></div>
            <div class="card-body">
                <?php if (empty($notes)): ?>
                    <p class="text-muted mb-0">No entries yet.</p>
                <?php endif; ?>
                <?php if (!empty($notes)): ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th style="width:170px;">Date</th>
                                <th style="width:90px;" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notes as $note): ?>
                                <tr>
                                    <td>
                                        <?= nl2br(e($note['content'])) ?>
                                        <div class="text-muted small"><?= e($note['user_name'] ?? 'System') ?></div>
                                    </td>
                                    <td>
                                        <div><?= e($note['created_at']) ?></div>
                                        <?php if ($note['updated_at']): ?>
                                            <div class="text-muted small">Edited <?= e($note['updated_at']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-secondary js-edit-note"
                                                data-id="<?= e((string) $note['id']) ?>"
                                                data-content="<?= e($note['content']) ?>"
                                                title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php if (!empty($noteHistory[$note['id']])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary js-view-note-history"
                                                    data-history='<?= e(json_encode($noteHistory[$note['id']])) ?>'
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

<!-- Edit note modal: one shared modal, filled via JS from the row's data-* attrs -->
<div class="modal fade" id="editNoteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= e(url('clients/view.php?id=' . $clientId)) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_note">
                <input type="hidden" name="note_id" id="editNoteId" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Description</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <textarea name="content" id="editNoteContent" class="form-control form-control-sm" rows="4" required></textarea>
                    </div>
                    <p class="text-muted small mb-0">Saving records the edit date automatically and keeps the previous version in this entry's history.</p>
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
<div class="modal fade" id="noteHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <ul class="list-unstyled mb-0" id="noteHistoryList"></ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    var editModalEl = document.getElementById('editNoteModal');
    var editModal = new bootstrap.Modal(editModalEl);

    document.querySelectorAll('.js-edit-note').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('editNoteId').value = btn.dataset.id;
            document.getElementById('editNoteContent').value = btn.dataset.content || '';
            editModal.show();
        });
    });

    var historyModalEl = document.getElementById('noteHistoryModal');
    var historyModal = new bootstrap.Modal(historyModalEl);
    var historyList = document.getElementById('noteHistoryList');

    document.querySelectorAll('.js-view-note-history').forEach(function (btn) {
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
                li.innerHTML =
                    '<div class="text-muted small">Before ' + entry.changed_at + ' — edited by ' + who + '</div>' +
                    '<p class="mb-0 mt-1">' + entry.old_content.replace(/</g, '&lt;').replace(/\n/g, '<br>') + '</p>';
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
