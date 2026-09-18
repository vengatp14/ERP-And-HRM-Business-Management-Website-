<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('billing');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify_or_die();
    require_permission('billing', 'edit');
    $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
    soft_delete_invoice($invoiceId);
    audit_log((int) current_user()['id'], 'billing', 'delete', "Deleted invoice #{$invoiceId}.");
    flash_set('status', 'Invoice deleted.');
    redirect('billing/index.php');
}

process_overdue_invoices();

$filters = [
    'status' => $_GET['status'] ?? '',
    'gst_type' => $_GET['gst_type'] ?? '',
    'search' => trim((string) ($_GET['q'] ?? '')),
];

$perPageOptions = [10, 15, 25, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? 15);
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 15;
}

$totalInvoices = count_invoices($filters);
$pagination = paginate($totalInvoices, $perPage);
$invoices = list_invoices($filters, $pagination['perPage'], $pagination['offset']);

// Temporary diagnostic panel — visit with ?debug=1 to compare, side by
// side: (a) what count_invoices()/list_invoices() returned for the
// current filters, and (b) a raw, filter-free SELECT * FROM invoices,
// so a count/row mismatch can be pinpointed without phpMyAdmin. Also
// catches (rather than silently dying on) any error while rendering
// an individual row, since an uncaught error partway through the
// table loop would explain rows going missing without any visible
// error. Safe to delete later — renders nothing unless ?debug=1 is
// explicitly passed in the URL.
$debugInfo = null;
if (($_GET['debug'] ?? '') === '1') {
    $rawStmt = db()->query('SELECT id, invoice_number, client_id, status, deleted_at, invoice_date, due_date, created_at FROM invoices ORDER BY id');
    $rawRows = $rawStmt->fetchAll();

    $renderErrors = [];
    foreach ($invoices as $idx => $inv) {
        try {
            // Touch every field the table row actually renders, to surface
            // any type error that the real template would hit for this row.
            e($inv['invoice_number'] ?? null);
            e($inv['client_name'] ?? '—');
            e($inv['invoice_date'] ?? null);
            e($inv['due_date'] ?? '—');
            number_format((float) ($inv['total_amount'] ?? 0), 2);
            number_format((float) ($inv['amount_paid'] ?? 0), 2);
            invoice_is_gst($inv);
            invoice_status_badge_class((string) ($inv['status'] ?? ''));
            invoice_open_close_label((string) ($inv['status'] ?? ''));
            invoice_open_close_badge_class((string) ($inv['status'] ?? ''));
        } catch (\Throwable $e) {
            $renderErrors[] = [
                'row_index' => $idx,
                'invoice_id' => $inv['id'] ?? null,
                'error' => $e->getMessage(),
            ];
        }
    }

    $debugInfo = [
        'filters' => $filters,
        'count_invoices_result' => $totalInvoices,
        'list_invoices_row_count' => count($invoices),
        'list_invoices_rows' => array_map(static function (array $inv): array {
            return [
                'id' => $inv['id'] ?? null,
                'invoice_number' => $inv['invoice_number'] ?? null,
                'client_id' => $inv['client_id'] ?? null,
                'client_name' => $inv['client_name'] ?? null,
                'status' => $inv['status'] ?? null,
                'deleted_at' => $inv['deleted_at'] ?? null,
                'invoice_date' => $inv['invoice_date'] ?? null,
            ];
        }, $invoices),
        'raw_invoices_table_row_count' => count($rawRows),
        'raw_invoices_table_rows' => $rawRows,
        'render_errors' => $renderErrors,
    ];
}

$calMonth = calendar_resolve_month();
$calendarEvents = get_billing_calendar_events($calMonth['start'], $calMonth['end']);

$pageTitle = 'GST Billing';
$activeMenu = 'billing';
$breadcrumbs = [['label' => 'GST Billing', 'url' => null]];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">GST Billing <span class="text-muted fs-6">(<?= e((string) $totalInvoices) ?>)</span></h1>
    <?php if (user_can(current_user(), 'billing', 'add')): ?>
        <a href="<?= e(url('billing/form.php')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> New Invoice</a>
    <?php endif; ?>
</div>

<?php if ($debugInfo !== null): ?>
    <div class="card mb-3 border-danger">
        <div class="card-body">
            <h2 class="h6 text-danger mb-2">Debug: count vs list mismatch check</h2>
            <pre class="small mb-0" style="white-space: pre-wrap;"><?= e(json_encode($debugInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
    </div>
<?php endif; ?>

<?= render_calendar_widget('billingCalendar', $calendarEvents, url('billing/index.php'), [], true) ?>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="<?= e(url('billing/index.php')) ?>" class="row g-2">
            <div class="col-12 col-md-6">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search invoice number or client"
                       value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-6 col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <?php foreach (invoice_status_options() as $status): ?>
                        <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $status))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <select name="gst_type" class="form-select form-select-sm">
                    <option value="">GST + Non-GST</option>
                    <option value="gst" <?= $filters['gst_type'] === 'gst' ? 'selected' : '' ?>>GST Invoices</option>
                    <option value="non_gst" <?= $filters['gst_type'] === 'non_gst' ? 'selected' : '' ?>>Non-GST Invoices</option>
                </select>
            </div>
            <div class="col-6 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill">Filter</button>
                <a href="<?= e(url('billing/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div class="text-muted small">
                <?php if ($totalInvoices > 0): ?>
                    Showing <?= e((string) ($pagination['offset'] + 1)) ?>–<?= e((string) min($pagination['offset'] + $pagination['perPage'], $totalInvoices)) ?> of <?= e((string) $totalInvoices) ?> invoices
                <?php else: ?>
                    No invoices found.
                <?php endif; ?>
            </div>
            <div class="d-flex align-items-center gap-2">
                <label for="invoicePerPage" class="form-label small mb-0 text-nowrap">Rows per page</label>
                <select id="invoicePerPage" class="form-select form-select-sm" style="width: auto;">
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
                        <th>Invoice #</th>
                        <th>Client</th>
                        <th>Date</th>
                        <th>Due</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Paid</th>
                        <th>GST</th>
                        <th>Status</th>
                        <th>Open/Close</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No invoices found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td><a href="<?= e(url('billing/view.php?id=' . $invoice['id'])) ?>" class="fw-semibold text-decoration-none"><?= e($invoice['invoice_number']) ?></a></td>
                            <td><?= field_or($invoice['client_name'] ?? null) ?></td>
                            <td><?= e($invoice['invoice_date']) ?></td>
                            <td><?= field_or($invoice['due_date'] ?? null, 'Not set') ?></td>
                            <td class="text-end">₹<?= e(number_format((float) $invoice['total_amount'], 2)) ?></td>
                            <td class="text-end">₹<?= e(number_format((float) $invoice['amount_paid'], 2)) ?></td>
                            <td>
                                <?php if (invoice_is_gst($invoice)): ?>
                                    <span class="badge text-bg-primary">GST</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Non-GST</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?= e(invoice_status_badge_class($invoice['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', $invoice['status']))) ?></span></td>
                            <td><span class="badge <?= e(invoice_open_close_badge_class($invoice['status'])) ?>"><?= e(invoice_open_close_label($invoice['status'])) ?></span></td>
                            <td class="text-end">
                                <div class="d-inline-flex align-items-center gap-1">
                                    <?php if (!in_array($invoice['status'], ['paid', 'completed', 'cancelled'], true) && user_can(current_user(), 'billing', 'edit')): ?>
                                        <a href="<?= e(url('billing/form.php?id=' . $invoice['id'])) ?>"
                                           class="btn btn-sm btn-outline-secondary" title="Edit invoice <?= e($invoice['invoice_number']) ?>">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    <?php endif; ?>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary" type="button"
                                                data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false"
                                                aria-label="Actions for invoice <?= e((string) $invoice['id']) ?>">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item" href="<?= e(url('billing/view.php?id=' . $invoice['id'])) ?>">View</a></li>
                                            <?php if (!in_array($invoice['status'], ['paid', 'completed', 'cancelled'], true) && user_can(current_user(), 'billing', 'edit')): ?>
                                                <li><a class="dropdown-item" href="<?= e(url('billing/form.php?id=' . $invoice['id'])) ?>">Edit</a></li>
                                            <?php endif; ?>
                                            <?php if (user_can(current_user(), 'billing', 'edit')): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <form method="POST" action="<?= e(url('billing/index.php')) ?>" class="js-confirm-delete">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="invoice_id" value="<?= e((string) $invoice['id']) ?>">
                                                        <button type="submit" class="dropdown-item text-danger">Delete</button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
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
document.getElementById('invoicePerPage')?.addEventListener('change', function () {
    var params = new URLSearchParams(window.location.search);
    params.set('per_page', this.value);
    params.delete('page'); // jump back to page 1 whenever the page size changes
    window.location.search = params.toString();
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>