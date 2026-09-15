<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('daily_updates');

$currentUser = current_user();
$currentUserId = (int) $currentUser['id'];
$isManager = in_array($currentUser['role'], ['super_admin', 'admin'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'submit') {
        $data = [
            'heading' => sanitize_string($_POST['heading'] ?? ''),
            'description' => sanitize_string($_POST['description'] ?? ''),
            'reference_link' => trim((string) ($_POST['reference_link'] ?? '')) ?: null,
        ];

        $error = validate_required($data['heading'], 'Heading')
            ?? validate_required($data['description'], 'Description');
        if ($error === null && $data['reference_link'] !== null && !filter_var($data['reference_link'], FILTER_VALIDATE_URL)) {
            $error = "That doesn't look like a valid link — include http:// or https://.";
        }

        if ($error !== null) {
            flash_set('error', $error);
            redirect('hr/daily-updates.php');
        }

        $updateId = submit_daily_update($currentUserId, $data);

        // Attached documents are optional — same multi-file upload
        // pattern as the project completion flow (projects/view.php).
        $uploadedCount = 0;
        $uploadErrors = [];

        if (!empty($_FILES['documents']) && is_array($_FILES['documents']['name'] ?? null)) {
            $fileCount = count($_FILES['documents']['name']);
            for ($i = 0; $i < $fileCount; $i++) {
                if (($_FILES['documents']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue; // unused extra file input slot
                }
                $file = [
                    'name' => $_FILES['documents']['name'][$i],
                    'type' => $_FILES['documents']['type'][$i],
                    'tmp_name' => $_FILES['documents']['tmp_name'][$i],
                    'error' => $_FILES['documents']['error'][$i],
                    'size' => $_FILES['documents']['size'][$i],
                ];
                $result = handle_upload($file, 'documents');
                if (!$result['ok']) {
                    $uploadErrors[] = $file['name'] . ': ' . $result['error'];
                    continue;
                }
                add_daily_update_document($updateId, $currentUserId, $result);
                $uploadedCount++;
            }
        }

        if ($uploadErrors !== []) {
            flash_set('error', implode(' ', $uploadErrors));
        }

        audit_log($currentUserId, 'daily_updates', 'submit', "Logged daily work update #{$updateId}.");
        flash_set('status', "Today's update saved." . ($uploadedCount > 0 ? " {$uploadedCount} document(s) attached." : ''));
        redirect('hr/daily-updates.php');
    }

    if ($action === 'update_own') {
        $updateId = (int) ($_POST['update_id'] ?? 0);
        $data = [
            'heading' => sanitize_string($_POST['heading'] ?? ''),
            'description' => sanitize_string($_POST['description'] ?? ''),
            'reference_link' => trim((string) ($_POST['reference_link'] ?? '')) ?: null,
        ];

        $error = validate_required($data['heading'], 'Heading')
            ?? validate_required($data['description'], 'Description');
        if ($error === null && $data['reference_link'] !== null && !filter_var($data['reference_link'], FILTER_VALIDATE_URL)) {
            $error = "That doesn't look like a valid link — include http:// or https://.";
        }

        if ($error !== null) {
            flash_set('error', $error);
            redirect('hr/daily-updates.php');
        }

        if (update_daily_update($updateId, $currentUserId, $data)) {
            audit_log($currentUserId, 'daily_updates', 'edit', "Edited daily work update #{$updateId}.");
            flash_set('status', 'Update saved.');
        } else {
            flash_set('error', "Couldn't find that update to edit.");
        }
        redirect('hr/daily-updates.php');
    }

    if ($action === 'delete_own') {
        $updateId = (int) ($_POST['update_id'] ?? 0);
        if (delete_own_daily_update($updateId, $currentUserId)) {
            audit_log($currentUserId, 'daily_updates', 'delete', "Deleted daily work update #{$updateId}.");
            flash_set('status', 'Update deleted.');
        } else {
            flash_set('error', "Couldn't find that update to delete.");
        }
        redirect('hr/daily-updates.php');
    }

    if ($action === 'rate' && $isManager) {
        $updateId = (int) ($_POST['update_id'] ?? 0);
        $rating = (int) ($_POST['rating'] ?? 0);

        if ($rating < 1 || $rating > 5) {
            flash_set('error', 'Pick a rating between 1 and 5 stars.');
            redirect('hr/daily-updates.php');
        }

        if (rate_daily_update($updateId, $rating, $currentUserId)) {
            audit_log($currentUserId, 'daily_updates', 'rate', "Rated daily work update #{$updateId} as {$rating} star(s).");
            flash_set('status', 'Rating saved.');
        } else {
            flash_set('error', "Couldn't find that update to rate.");
        }
        redirect('hr/daily-updates.php');
    }
}

$todaysUpdate = get_todays_update($currentUserId);
$todaysDocuments = $todaysUpdate !== null ? get_daily_update_documents((int) $todaysUpdate['id']) : [];
$myUpdates = get_daily_updates(['user_id' => $currentUserId], 30);

$statusFilter = trim((string) ($_GET['date'] ?? ''));
$todaysStatus = $isManager ? get_todays_update_status() : [];
$allUpdates = $isManager ? get_daily_updates($statusFilter !== '' ? ['date' => $statusFilter] : [], 100) : [];
$pendingCount = $isManager ? count(array_filter($todaysStatus, static fn (array $r): bool => !$r['submitted'])) : 0;

$pageTitle = 'Daily Updates';
$activeMenu = 'daily_updates';
$breadcrumbs = [['label' => 'Daily Updates', 'url' => null]];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<h1 class="h4 mb-3">Daily Updates</h1>

<div class="row g-4 mb-4">
    <div class="col-12 <?= $isManager ? 'col-lg-6' : 'col-lg-7' ?>">
        <div class="card h-100">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><?= $todaysUpdate !== null ? "Edit Today's Update" : "Log Today's Update" ?></h2>
            </div>
            <div class="card-body">
                <?php if ($todaysUpdate !== null): ?>
                    <p class="text-muted small mb-3">You've already logged today's update — submitting again will update it.</p>
                <?php else: ?>
                    <p class="text-muted small mb-3">A quick note on what you worked on today. Link and documents are optional.</p>
                <?php endif; ?>
                <form method="POST" action="<?= e(url('hr/daily-updates.php')) ?>" enctype="multipart/form-data" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="submit">
                    <div class="mb-2">
                        <label class="form-label small">Heading</label>
                        <input type="text" name="heading" class="form-control form-control-sm" maxlength="150" required
                               value="<?= e($todaysUpdate['heading'] ?? '') ?>" placeholder="e.g. Client follow-ups &amp; site visit">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Description</label>
                        <textarea name="description" class="form-control form-control-sm" rows="4" required
                                  placeholder="What did you work on today?"><?= e($todaysUpdate['description'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Reference Link <span class="text-muted">(optional)</span></label>
                        <input type="url" name="reference_link" class="form-control form-control-sm" placeholder="https://..."
                               value="<?= e($todaysUpdate['reference_link'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Attach Documents <span class="text-muted">(optional)</span></label>
                        <input type="file" name="documents[]" class="form-control form-control-sm" multiple
                               accept=".pdf,.doc,.docx,.xls,.xlsx">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">Save Today's Update</button>
                </form>

                <?php if ($todaysDocuments !== []): ?>
                    <hr>
                    <p class="small text-muted mb-1">Attached documents:</p>
                    <ul class="list-unstyled small mb-0">
                        <?php foreach ($todaysDocuments as $doc): ?>
                            <li class="mb-1">
                                <i class="bi bi-paperclip"></i>
                                <a href="<?= e(url('hr/daily-update-document-download.php?id=' . $doc['id'])) ?>" target="_blank"><?= e($doc['original_filename']) ?></a>
                                <span class="text-muted">(<?= e(human_file_size((int) $doc['file_size_bytes'])) ?>)</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($isManager): ?>
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Today's Submission Status</h2>
                <span class="badge <?= $pendingCount > 0 ? 'text-bg-warning' : 'text-bg-success' ?>">
                    <?= $pendingCount > 0 ? "{$pendingCount} pending" : 'All submitted' ?>
                </span>
            </div>
            <div class="card-body" style="max-height: 360px; overflow-y: auto;">
                <?php if (empty($todaysStatus)): ?>
                    <p class="text-muted mb-0">No active employees found.</p>
                <?php endif; ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($todaysStatus as $row): ?>
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                            <div>
                                <?= e($row['full_name']) ?>
                                <?php if ($row['submitted'] && $row['heading']): ?>
                                    <div class="text-muted small"><?= e($row['heading']) ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="badge <?= $row['submitted'] ? 'text-bg-success' : 'text-bg-warning' ?>">
                                <?= $row['submitted'] ? 'Submitted' : 'Pending' ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="card <?= $isManager ? 'mb-4' : '' ?>">
    <div class="card-header bg-white"><h2 class="h6 mb-0">My Update History</h2></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Date</th><th>Heading</th><th>Description</th><th>Link</th><th>Rating</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                    <?php if (empty($myUpdates)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No updates logged yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($myUpdates as $update): ?>
                        <tr>
                            <td class="text-nowrap"><?= e($update['update_date']) ?></td>
                            <td><?= e($update['heading']) ?></td>
                            <td class="text-break"><?= e($update['description']) ?></td>
                            <td>
                                <?php if (!empty($update['reference_link'])): ?>
                                    <a href="<?= e($update['reference_link']) ?>" target="_blank" rel="noopener"><i class="bi bi-link-45deg"></i></a>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap"><?= star_display($update['rating'] !== null ? (int) $update['rating'] : null) ?></td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse"
                                        data-bs-target="#editUpdate<?= (int) $update['id'] ?>" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" action="<?= e(url('hr/daily-updates.php')) ?>" class="d-inline js-confirm-delete">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_own">
                                    <input type="hidden" name="update_id" value="<?= (int) $update['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <tr class="collapse" id="editUpdate<?= (int) $update['id'] ?>">
                            <td colspan="6" class="bg-light">
                                <form method="POST" action="<?= e(url('hr/daily-updates.php')) ?>" class="row g-2 py-2" novalidate>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_own">
                                    <input type="hidden" name="update_id" value="<?= (int) $update['id'] ?>">
                                    <div class="col-12 col-md-3">
                                        <input type="text" name="heading" class="form-control form-control-sm" maxlength="150" required
                                               value="<?= e($update['heading']) ?>" placeholder="Heading">
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <textarea name="description" class="form-control form-control-sm" rows="1" required
                                                  placeholder="Description"><?= e($update['description']) ?></textarea>
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <input type="url" name="reference_link" class="form-control form-control-sm"
                                               placeholder="https://... (optional)" value="<?= e($update['reference_link'] ?? '') ?>">
                                    </div>
                                    <div class="col-12 col-md-2">
                                        <button type="submit" class="btn btn-primary btn-sm w-100">Save Changes</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($isManager): ?>
<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h2 class="h6 mb-0">All Employees' Updates</h2>
        <form method="GET" action="<?= e(url('hr/daily-updates.php')) ?>" class="d-flex gap-2 align-items-center">
            <input type="date" name="date" class="form-control form-control-sm" value="<?= e($statusFilter) ?>">
            <button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button>
            <?php if ($statusFilter !== ''): ?>
                <a href="<?= e(url('hr/daily-updates.php')) ?>" class="btn btn-outline-secondary btn-sm">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead><tr><th>Employee</th><th>Date</th><th>Heading</th><th>Description</th><th>Link</th><th style="width:170px;">Rating</th></tr></thead>
                <tbody>
                    <?php if (empty($allUpdates)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No updates found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($allUpdates as $update): ?>
                        <tr>
                            <td><?= e($update['full_name']) ?></td>
                            <td class="text-nowrap"><?= e($update['update_date']) ?></td>
                            <td><?= e($update['heading']) ?></td>
                            <td class="text-break"><?= e($update['description']) ?></td>
                            <td>
                                <?php if (!empty($update['reference_link'])): ?>
                                    <a href="<?= e($update['reference_link']) ?>" target="_blank" rel="noopener"><i class="bi bi-link-45deg"></i></a>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" action="<?= e(url('hr/daily-updates.php')) ?>" class="d-flex align-items-center gap-1">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="rate">
                                    <input type="hidden" name="update_id" value="<?= (int) $update['id'] ?>">
                                    <select name="rating" class="form-select form-select-sm js-rating-auto-submit" style="width:auto;">
                                        <option value="">Not rated</option>
                                        <?php for ($s = 1; $s <= 5; $s++): ?>
                                            <option value="<?= $s ?>" <?= (int) ($update['rating'] ?? 0) === $s ? 'selected' : '' ?>>
                                                <?= str_repeat('★', $s) . str_repeat('☆', 5 - $s) ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
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

<?php if ($isManager): ?>
<script nonce="<?= e(csp_nonce()) ?>">
document.querySelectorAll('.js-rating-auto-submit').forEach(function (select) {
    select.addEventListener('change', function () {
        this.form.submit();
    });
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
