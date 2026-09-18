<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('quotations');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify_or_die();
    require_permission('quotations', 'edit');
    $quotationId = (int) ($_POST['quotation_id'] ?? 0);
    soft_delete_quotation($quotationId);
    audit_log((int) current_user()['id'], 'quotations', 'delete', "Deleted quotation #{$quotationId}.");
    flash_set('status', 'Quotation deleted.');
    redirect('quotations/index.php');
}

$filters = [
    'status' => $_GET['status'] ?? '',
    'search' => trim((string) ($_GET['q'] ?? '')),
];

$perPageOptions = [10, 15, 25, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? 15);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 15;
}

$totalQuotations = count_quotations($filters);
$pagination = paginate($totalQuotations, $perPage);
$quotations = list_quotations($filters, $pagination['perPage'], $pagination['offset']);

$pageTitle = 'Quotations';
$activeMenu = 'quotations';
$breadcrumbs = [['label' => 'Quotations', 'url' => null]];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Quotations <span class="text-muted fs-6">(<?= e((string) $totalQuotations) ?>)</span></h1>
    <?php if (user_can(current_user(), 'quotations', 'add')): ?>
        <a href="<?= e(url('quotations/form.php')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> New Quotation</a>
    <?php endif; ?>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="<?= e(url('quotations/index.php')) ?>" class="row g-2">
            <div class="col-12 col-md-6">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search quotation number, project, or client"
                       value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-6 col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <?php foreach (quotation_status_options() as $status): ?>
                        <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e(ucwords($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill">Filter</button>
                <a href="<?= e(url('quotations/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div class="text-muted small">
                <?php if ($totalQuotations > 0): ?>
                    Showing <?= e((string) ($pagination['offset'] + 1)) ?>–<?= e((string) min($pagination['offset'] + $pagination['perPage'], $totalQuotations)) ?> of <?= e((string) $totalQuotations) ?> quotations
                <?php else: ?>
                    No quotations found.
                <?php endif; ?>
            </div>
            <div class="d-flex align-items-center gap-2">
                <label for="quotationPerPage" class="form-label small mb-0 text-nowrap">Rows per page</label>
                <select id="quotationPerPage" class="form-select form-select-sm" style="width: auto;">
                    <?php foreach ($perPageOptions as $opt): ?>
                        <option value="<?= e((string) $opt) ?>" <?= $opt === $pagination['perPage'] ? 'selected' : '' ?>><?= e((string) $opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-3">
                <thead>
                    <tr>
                        <th>Quotation #</th>
                        <th>Client</th>
                        <th>Project</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th class="text-end">Total</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($quotations)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No quotations found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($quotations as $quotation): ?>
                        <tr>
                            <td><a href="<?= e(url('quotations/view.php?id=' . $quotation['id'])) ?>" class="fw-semibold text-decoration-none"><?= e($quotation['quotation_number']) ?></a></td>
                            <td><?= field_or($quotation['client_name'] ?? null) ?></td>
                            <td><?= e($quotation['project_title']) ?></td>
                            <td><?= e(quotation_website_type_label($quotation)) ?></td>
                            <td><?= e($quotation['quotation_date']) ?></td>
                            <td class="text-end">₹<?= e(number_format((float) $quotation['total_amount'], 2)) ?></td>
                            <td><span class="badge <?= e(quotation_status_badge_class($quotation['status'])) ?>"><?= e(ucwords($quotation['status'])) ?></span></td>
                            <td class="text-end">
                                <div class="d-inline-flex align-items-center gap-1">
                                    <a href="<?= e(url('quotations/view.php?id=' . $quotation['id'])) ?>" class="btn btn-sm btn-outline-secondary" title="View"><i class="bi bi-eye"></i></a>
                                    <?php if (user_can(current_user(), 'quotations', 'edit')): ?>
                                        <a href="<?= e(url('quotations/form.php?id=' . $quotation['id'])) ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                                        <form method="POST" action="<?= e(url('quotations/index.php')) ?>" class="js-confirm-delete">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="quotation_id" value="<?= e((string) $quotation['id']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= render_pagination($pagination['page'], $pagination['totalPages']) ?>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('quotationPerPage')?.addEventListener('change', function () {
    var params = new URLSearchParams(window.location.search);
    params.set('per_page', this.value);
    params.delete('page');
    window.location.search = params.toString();
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
