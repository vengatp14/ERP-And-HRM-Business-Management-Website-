<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('projects');

$projectId = (int) ($_GET['id'] ?? 0);
$project = find_project($projectId);

if ($project === false) {
    flash_set('error', 'Project not found.');
    redirect('projects/index.php');
}

$currentUserId = (int) current_user()['id'];
$isAdminUser = is_admin_role(current_user());

// Employees can only open projects/properties they're assigned to
// (manager or team member) — even via a direct link. Admins bypass this.
if (!is_admin_role(current_user()) && !user_is_assigned_to_project($projectId, $currentUserId)) {
    http_response_code(403);
    require __DIR__ . '/../errors/403.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_note') {
        $content = sanitize_string($_POST['content'] ?? '');
        if ($content === '') {
            flash_set('error', 'Note cannot be empty.');
            redirect('projects/view.php?id=' . $projectId);
        }
        add_project_note($projectId, $currentUserId, $content);
        flash_set('status', 'Note added.');
        redirect('projects/view.php?id=' . $projectId);
    }

    if ($action === 'add_member') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $roleOnProject = sanitize_string($_POST['role_on_project'] ?? '') ?: null;
        if ($userId <= 0) {
            flash_set('error', 'Please select a team member.');
            redirect('projects/view.php?id=' . $projectId);
        }
        add_project_member($projectId, $userId, $roleOnProject);
        flash_set('status', 'Team member added.');
        redirect('projects/view.php?id=' . $projectId);
    }

    if ($action === 'remove_member') {
        remove_project_member($projectId, (int) ($_POST['user_id'] ?? 0));
        flash_set('status', 'Team member removed.');
        redirect('projects/view.php?id=' . $projectId);
    }

    if ($action === 'update_status') {
        // Deliberately NOT gated behind require_permission('projects','edit') —
        // an employee who only has projects.view access (and is assigned to
        // this project) should still be able to move the status forward
        // (planning/in_progress/on_hold/pending_approval/completed/cancelled)
        // without needing full edit rights to budget, deadline, team, etc.
        // The page itself is already gated by require_menu_access('projects')
        // + the assigned-to-project check above, so this is safe.
        //
        // Exception: 'completed' specifically is admin-gated. Marking a
        // project Completed auto-drafts a GST invoice off its budget
        // (auto_generate_invoice_for_completed_project()), so a non-admin
        // choosing "Completed" is redirected to 'pending_approval' instead —
        // it shows up on the Project Board for an admin to accept, and only
        // that admin accept actually flips it to Completed and fires the
        // invoice draft.
        $status = $_POST['status'] ?? '';
        if ($status === 'completed' && !$isAdminUser) {
            $status = 'pending_approval';
        }
        if (in_array($status, PROJECT_STATUSES, true)) {
            $wasCompleted = $project['status'] === 'completed';
            $wasPendingApproval = $project['status'] === 'pending_approval';
            update_project($projectId, ['status' => $status]);
            audit_log($currentUserId, 'projects', 'status_update', "Updated project #{$projectId} status to '{$status}'.");

            if ($status === 'completed' && !$wasCompleted) {
                auto_generate_invoice_for_completed_project($projectId, $currentUserId);
            }

            if ($status === 'pending_approval' && !$wasPendingApproval) {
                notify_admins(
                    'task',
                    "Awaiting approval: {$project['title']}",
                    'Marked complete and needs admin approval on the Project Board before it finalizes and drafts a GST invoice.',
                    'projects/board.php'
                );
            }

            flash_set('status', $status === 'pending_approval'
                ? 'Marked complete — sent to an admin for approval before it\'s finalized.'
                : 'Project status updated.');
        }
        redirect('projects/view.php?id=' . $projectId);
    }

    if ($action === 'submit_completion') {
        // The "Mark Complete" flow, but with proof attached: pushes the
        // project to 'pending_approval' (same target status as picking
        // it from the status dropdown above) while also recording a
        // reference link and/or uploaded documents, so the admin
        // reviewing it on the Project Board has something to check
        // before accepting the move to Completed.
        $link = trim((string) ($_POST['completion_link'] ?? ''));
        if ($link !== '' && !filter_var($link, FILTER_VALIDATE_URL)) {
            flash_set('error', "That doesn't look like a valid link — include http:// or https://.");
            redirect('projects/view.php?id=' . $projectId);
        }

        $docCategory = ($_POST['completion_doc_category'] ?? 'documents') === 'images' ? 'images' : 'documents';
        $uploadedCount = 0;
        $uploadErrors = [];

        if (!empty($_FILES['completion_documents']) && is_array($_FILES['completion_documents']['name'] ?? null)) {
            $fileCount = count($_FILES['completion_documents']['name']);
            for ($i = 0; $i < $fileCount; $i++) {
                if (($_FILES['completion_documents']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue; // unused extra file input slot
                }
                $file = [
                    'name' => $_FILES['completion_documents']['name'][$i],
                    'type' => $_FILES['completion_documents']['type'][$i],
                    'tmp_name' => $_FILES['completion_documents']['tmp_name'][$i],
                    'error' => $_FILES['completion_documents']['error'][$i],
                    'size' => $_FILES['completion_documents']['size'][$i],
                ];
                $result = handle_upload($file, $docCategory);
                if (!$result['ok']) {
                    $uploadErrors[] = $file['name'] . ': ' . $result['error'];
                    continue;
                }
                add_project_completion_document($projectId, $currentUserId, $result);
                $uploadedCount++;
            }
        }

        if ($uploadErrors !== []) {
            flash_set('error', implode(' ', $uploadErrors));
            redirect('projects/view.php?id=' . $projectId);
        }

        if ($link !== '') {
            set_project_completion_link($projectId, $link);
        }

        $wasPendingApproval = $project['status'] === 'pending_approval';
        update_project($projectId, ['status' => 'pending_approval']);
        audit_log($currentUserId, 'projects', 'status_update', "Updated project #{$projectId} status to 'pending_approval'.");

        if (!$wasPendingApproval) {
            notify_admins(
                'task',
                "Awaiting approval: {$project['title']}",
                'Marked complete and needs admin approval on the Project Board before it finalizes and drafts a GST invoice.',
                'projects/board.php'
            );
        }

        if ($uploadErrors !== []) {
            flash_set('error', implode(' ', $uploadErrors));
        }
        flash_set('status', 'Marked complete — sent to an admin for approval before it\'s finalized.'
            . ($uploadedCount > 0 ? " {$uploadedCount} document(s) attached." : ''));
        redirect('projects/view.php?id=' . $projectId);
    }

    if ($action === 'add_income') {
        // Manual top-up — record a payment directly against the
        // project without going through a payment plan's automatic
        // due-date confirm flow (accounts/project_payment_confirm.php).
        if (!$isAdminUser) {
            flash_set('error', 'Only admins can record payments.');
            redirect('projects/view.php?id=' . $projectId);
        }

        $amount = (float) ($_POST['amount'] ?? 0);
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $paymentMethod = $_POST['payment_method'] ?? 'bank_transfer';
        $reference = sanitize_string($_POST['reference'] ?? '') ?: null;
        $notes = sanitize_string($_POST['notes'] ?? '') ?: null;

        if ($amount <= 0) {
            flash_set('error', 'Amount must be greater than zero.');
            redirect('projects/view.php?id=' . $projectId);
        }
        if (!in_array($paymentMethod, ['cash', 'bank_transfer', 'upi', 'cheque', 'card', 'other'], true)) {
            $paymentMethod = 'bank_transfer';
        }

        $incomeId = record_project_income($projectId, null, [
            'amount' => $amount,
            'payment_date' => $paymentDate,
            'payment_method' => $paymentMethod,
            'reference' => $reference,
            'notes' => $notes,
        ], $currentUserId);
        $newIncome = find_project_income($incomeId);
        $numberSuffix = $newIncome !== false && !empty($newIncome['invoice_number']) ? " ({$newIncome['invoice_number']})" : '';

        audit_log($currentUserId, 'projects', 'income_add', "Recorded ₹" . number_format($amount, 2) . " payment for project #{$projectId} ({$project['title']}).");
        flash_set('status', 'Payment of ₹' . number_format($amount, 2) . ' added to Income.' . $numberSuffix);
        redirect('projects/view.php?id=' . $projectId);
    }

    if ($action === 'delete_income') {
        // Removes an income entry whether it was added manually above
        // or accepted through the automatic confirm flow — if it came
        // from a confirmed plan run, that run is reopened so it can be
        // confirmed again (see soft_delete_project_income()).
        if (!$isAdminUser) {
            flash_set('error', 'Only admins can delete payments.');
            redirect('projects/view.php?id=' . $projectId);
        }

        $incomeId = (int) ($_POST['income_id'] ?? 0);
        $income = find_project_income($incomeId);
        if ($income === false || (int) $income['project_id'] !== $projectId) {
            flash_set('error', 'Payment record not found.');
            redirect('projects/view.php?id=' . $projectId);
        }

        soft_delete_project_income($incomeId);
        audit_log($currentUserId, 'projects', 'income_delete', "Deleted ₹" . number_format((float) $income['amount'], 2) . " payment #{$incomeId} from project #{$projectId} ({$project['title']}).");
        flash_set('status', 'Payment deleted.');
        redirect('projects/view.php?id=' . $projectId);
    }
}

$notes = get_project_notes($projectId);
$members = get_project_members($projectId);
$completionDocuments = get_project_completion_documents($projectId);
$paymentPlan = is_admin_role(current_user()) ? find_project_payment_plan_by_project($projectId) : false;
$projectIncome = is_admin_role(current_user()) ? get_project_income($projectId) : [];
$projectInvoice = is_admin_role(current_user()) ? find_invoice_by_project($projectId) : false;
$employees = db()->query("SELECT id, full_name FROM users WHERE status = 'active' ORDER BY full_name")->fetchAll();

$pageTitle = $project['title'];
$activeMenu = 'projects';
$breadcrumbs = [
    ['label' => 'Projects', 'url' => url('projects/index.php')],
    ['label' => $project['title'], 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">
            <?= e($project['title']) ?>
            <span class="badge <?= e(lead_priority_badge_class($project['priority'])) ?>"><?= e(ucfirst($project['priority'])) ?> priority</span>
        </h1>
        <form method="POST" action="<?= e(url('projects/view.php?id=' . $projectId)) ?>" class="d-inline-flex align-items-center gap-2 mt-1">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_status">
            <label class="small text-muted mb-0">Status</label>
            <select id="projectStatusSelect" name="status" class="form-select form-select-sm js-auto-submit <?= e(project_status_badge_class($project['status'])) ?>" style="width:auto;">
                <?php foreach (PROJECT_STATUSES as $s): ?>
                    <?php
                        // Employees don't get a raw "Completed" option — picking
                        // it would silently redirect to pending_approval server-side
                        // (see the update_status handler), which is confusing UI.
                        // Show the honest label instead and skip 'completed' itself
                        // unless the project is already Completed (so the select
                        // still displays its real current state) or the user is an admin.
                        if (!$isAdminUser && $s === 'completed' && $project['status'] !== 'completed') {
                            continue;
                        }
                        $label = $s === 'pending_approval' && !$isAdminUser
                            ? 'Mark Complete (needs approval)'
                            : ucwords(str_replace('_', ' ', $s));
                    ?>
                    <option value="<?= e($s) ?>" <?= $project['status'] === $s ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <p class="text-muted mb-0">
            <a href="<?= e(url('clients/view.php?id=' . $project['client_id'])) ?>"><?= e($project['client_name'] ?? 'Unknown client') ?></a>
        </p>
    </div>
    <?php if (user_can(current_user(), 'projects', 'edit')): ?>
        <a href="<?= e(url('projects/form.php?id=' . $projectId)) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i> Edit</a>
    <?php endif; ?>
</div>

<!-- Picking "Mark Complete" / "Pending Approval" on the Status select
     above opens this modal instead of auto-submitting, so a link and/or
     documents can be attached in the same step that pushes the project
     to pending_approval. -->
<div class="modal fade" id="completionSubmitModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="<?= e(url('projects/view.php?id=' . $projectId)) ?>" enctype="multipart/form-data" id="completionSubmitForm" novalidate>
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Submit for approval</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="submit_completion">
                    <p class="text-muted small">Optionally add a reference link and/or upload documents for the admin to check before approving this project as complete.</p>
                    <div class="mb-3">
                        <label class="form-label small">Link</label>
                        <input type="url" name="completion_link" class="form-control form-control-sm" placeholder="https://..." value="<?= e($project['completion_link'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Documents</label>
                        <select name="completion_doc_category" class="form-select form-select-sm mb-2">
                            <option value="documents">Document (PDF/Word/Excel)</option>
                            <option value="images">Image</option>
                        </select>
                        <input type="file" name="completion_documents[]" class="form-control form-control-sm" multiple>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Submit for Approval</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="card mb-4">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Details</h2></div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5">Type</dt><dd class="col-7"><?= e($project['project_type'] ?? '—') ?></dd>
                    <?php if (is_admin_role(current_user())): ?>
                        <dt class="col-5">Budget</dt><dd class="col-7"><?= $project['budget'] !== null ? '₹' . e(number_format((float) $project['budget'], 2)) : '—' ?></dd>
                        <dt class="col-5">Paid</dt><dd class="col-7 text-success fw-semibold">₹<?= e(number_format($projectInvoice !== false ? (float) $projectInvoice['amount_paid'] : 0, 2)) ?></dd>
                    <?php endif; ?>
                    <dt class="col-5">Manager</dt><dd class="col-7"><?= e($project['manager_name'] ?? 'Unassigned') ?></dd>
                    <dt class="col-5">Start Date</dt><dd class="col-7"><?= e($project['start_date'] ?? '—') ?></dd>
                    <dt class="col-5">Deadline</dt><dd class="col-7"><?= e($project['deadline'] ?? '—') ?></dd>
                    <dt class="col-5">Completed</dt><dd class="col-7"><?= e($project['completed_at'] ?? '—') ?></dd>
                </dl>
                <?php if (!empty($project['description'])): ?>
                    <hr><div class="text-muted"><?= nl2br(e($project['description'])) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($project['completion_link']) || !empty($completionDocuments) || $project['status'] === 'pending_approval'): ?>
        <div class="card mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Completion Submission</h2>
                <?php if ($project['status'] === 'pending_approval'): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#completionSubmitModal">
                        <i class="bi bi-paperclip"></i> Add More
                    </button>
                <?php endif; ?>
            </div>
            <div class="card-body small">
                <?php if (!empty($project['completion_link'])): ?>
                    <div class="mb-2">
                        <div class="text-muted mb-1">Link</div>
                        <a href="<?= e($project['completion_link']) ?>" target="_blank" rel="noopener" class="text-break"><?= e($project['completion_link']) ?></a>
                    </div>
                <?php endif; ?>
                <?php if (!empty($completionDocuments)): ?>
                    <div class="text-muted mb-1">Documents</div>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($completionDocuments as $doc): ?>
                            <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <a href="<?= e(url('projects/completion-document-download.php?id=' . $doc['id'])) ?>" class="text-truncate" style="max-width: 180px;">
                                    <i class="bi bi-file-earmark-arrow-down me-1"></i><?= e($doc['original_filename']) ?>
                                </a>
                                <span class="text-muted"><?= e(human_file_size((int) $doc['file_size_bytes'])) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if (empty($project['completion_link']) && empty($completionDocuments)): ?>
                    <p class="text-muted mb-0">No link or documents attached yet.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (is_admin_role(current_user())): ?>
        <div class="card mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Payments</h2>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addIncomeModal"><i class="bi bi-plus-lg"></i> Add Payment</button>
                    <a href="<?= e(url($paymentPlan !== false ? 'accounts/project_payment_form.php?id=' . $paymentPlan['id'] : 'accounts/project_payment_form.php')) ?>" class="btn btn-outline-secondary btn-sm"><?= $paymentPlan !== false ? 'Edit Plan' : 'Set Up Payment' ?></a>
                </div>
            </div>
            <div class="card-body small">
                <?php if ($paymentPlan !== false): ?>
                    <dl class="row mb-0">
                        <dt class="col-5">Type</dt><dd class="col-7"><?= e(project_payment_type_label($paymentPlan['payment_type'])) ?></dd>
                        <dt class="col-5">Amount</dt><dd class="col-7">₹<?= e(number_format((float) $paymentPlan['amount'], 2)) ?></dd>
                        <dt class="col-5">Due</dt><dd class="col-7">
                            <?= $paymentPlan['payment_type'] === 'monthly'
                                ? 'Every ' . e((string) $paymentPlan['day_of_month']) . e(ordinal_suffix((int) $paymentPlan['day_of_month']))
                                : e($paymentPlan['due_date'] ?? '—') ?>
                        </dd>
                        <dt class="col-5">Status</dt><dd class="col-7"><span class="badge <?= (int) $paymentPlan['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= (int) $paymentPlan['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></dd>
                        <dt class="col-5">Total Received</dt><dd class="col-7 fw-semibold">₹<?= e(number_format($projectInvoice !== false ? (float) $projectInvoice['amount_paid'] : 0, 2)) ?></dd>
                    </dl>
                <?php else: ?>
                    <p class="text-muted mb-0">No payment plan set up for this project yet.</p>
                <?php endif; ?>
                <?php if (!empty($projectIncome)): ?>
                    <hr>
                    <div class="text-muted mb-1">Recent payments</div>
                    <ul class="list-group list-group-flush">
                        <?php foreach (array_slice($projectIncome, 0, 5) as $pmt): ?>
                            <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <span>
                                    <?= e($pmt['payment_date']) ?>
                                    <?php if (!empty($pmt['invoice_number'])): ?>
                                        <span class="text-muted ms-2"><?= e($pmt['invoice_number']) ?></span>
                                    <?php endif; ?>
                                    <span class="fw-semibold text-success ms-2">+₹<?= e(number_format((float) $pmt['amount'], 2)) ?></span>
                                </span>
                                <form method="POST" action="<?= e(url('projects/view.php?id=' . $projectId)) ?>" onsubmit="return confirm('Delete this payment? It will also be removed from Income totals.');" class="mb-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_income">
                                    <input type="hidden" name="income_id" value="<?= e((string) $pmt['id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-link text-danger p-0" aria-label="Delete payment"><i class="bi bi-trash"></i></button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Manually record a payment against this project, separate from
             the automatic monthly/one-time confirm flow. -->
        <div class="modal fade" id="addIncomeModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" action="<?= e(url('projects/view.php?id=' . $projectId)) ?>" novalidate>
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Add Payment</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_income">
                            <div class="row g-3 mb-3">
                                <div class="col-6">
                                    <label class="form-label small">Amount (₹) *</label>
                                    <input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">Payment Date</label>
                                    <input type="date" name="payment_date" class="form-control form-control-sm" value="<?= e(date('Y-m-d')) ?>">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Payment Method</label>
                                <select name="payment_method" class="form-select form-select-sm">
                                    <option value="bank_transfer">Bank Transfer</option>
                                    <option value="cash">Cash</option>
                                    <option value="upi">UPI</option>
                                    <option value="cheque">Cheque</option>
                                    <option value="card">Card</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small">Reference</label>
                                <input type="text" name="reference" class="form-control form-control-sm" placeholder="Transaction ID, cheque no., etc.">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Notes</label>
                                <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary btn-sm">Add Payment</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Team</h2></div>
            <div class="card-body">
                <ul class="list-group list-group-flush small mb-3">
                    <?php if (empty($members)): ?>
                        <li class="list-group-item text-muted px-0">No team members yet.</li>
                    <?php endif; ?>
                    <?php foreach ($members as $member): ?>
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?= e($member['full_name']) ?></strong>
                                <?php if ($member['role_on_project']): ?><span class="text-muted"> — <?= e($member['role_on_project']) ?></span><?php endif; ?>
                            </div>
                            <form method="POST" action="<?= e(url('projects/view.php?id=' . $projectId)) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="remove_member">
                                <input type="hidden" name="user_id" value="<?= e((string) $member['user_id']) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <form method="POST" action="<?= e(url('projects/view.php?id=' . $projectId)) ?>" class="d-flex gap-2" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_member">
                    <select name="user_id" class="form-select form-select-sm" required>
                        <option value="">Select employee…</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= e((string) $emp['id']) ?>"><?= e($emp['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="role_on_project" class="form-control form-control-sm" placeholder="Role" style="max-width:110px;">
                    <button type="submit" class="btn btn-outline-primary btn-sm">Add</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="card mb-4">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Add Note</h2></div>
            <div class="card-body">
                <form method="POST" action="<?= e(url('projects/view.php?id=' . $projectId)) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_note">
                    <div class="mb-2"><textarea name="content" class="form-control form-control-sm" rows="2" placeholder="Log an update..."></textarea></div>
                    <button type="submit" class="btn btn-primary btn-sm">Save Note</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Timeline</h2></div>
            <div class="card-body">
                <?php if (empty($notes)): ?>
                    <p class="text-muted mb-0">No activity yet.</p>
                <?php endif; ?>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($notes as $note): ?>
                        <li class="border-start ps-3 pb-3 border-2">
                            <div class="d-flex justify-content-between">
                                <span class="text-muted small"><?= e($note['user_name'] ?? 'System') ?></span>
                                <span class="text-muted small"><?= e($note['created_at']) ?></span>
                            </div>
                            <p class="mb-0 mt-1"><?= nl2br(e($note['content'])) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
var completionModalEl = document.getElementById('completionSubmitModal');
var completionSubmitForm = document.getElementById('completionSubmitForm');
var currentProjectStatus = <?= json_encode($project['status']) ?>;

document.querySelectorAll('.js-auto-submit').forEach(function (select) {
    select.addEventListener('change', function () {
        // Picking "Pending Approval" (the employee's "Mark Complete")
        // opens the attach-proof modal instead of submitting immediately,
        // so a link/document can be added in the same step.
        if (select.id === 'projectStatusSelect' && select.value === 'pending_approval' && completionModalEl) {
            bootstrap.Modal.getOrCreateInstance(completionModalEl).show();
            return;
        }
        select.form.submit();
    });
});

if (completionModalEl && completionSubmitForm) {
    completionSubmitForm.addEventListener('submit', function () {
        completionSubmitForm.dataset.submitted = '1';
    });
    // Cancelling the modal shouldn't leave the Status select showing
    // "Pending Approval" when nothing was actually submitted.
    completionModalEl.addEventListener('hidden.bs.modal', function () {
        if (!completionSubmitForm.dataset.submitted) {
            var select = document.getElementById('projectStatusSelect');
            if (select) { select.value = currentProjectStatus; }
        }
    });
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
