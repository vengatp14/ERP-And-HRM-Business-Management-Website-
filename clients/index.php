<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('clients');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify_or_die();
    require_permission('clients', 'edit');
    $clientId = (int) ($_POST['client_id'] ?? 0);
    soft_delete_client($clientId);
    audit_log((int) current_user()['id'], 'clients', 'delete', "Deleted client #{$clientId}.");
    flash_set('status', 'Client deleted.');
    redirect('clients/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_contacted') {
    csrf_verify_or_die();
    $clientId = (int) ($_POST['client_id'] ?? 0);
    mark_client_contacted($clientId);
    flash_set('status', 'Client marked contacted.');
    redirect('clients/index.php');
}

// Also run it opportunistically on every list view (cheap query if
// nothing's overdue), same on-demand pattern as process_missed_leads().
process_missed_clients();

$filters = [
    'status' => $_GET['status'] ?? '',
    'search' => trim((string) ($_GET['q'] ?? '')),
];

// Plain 'employee' accounts only see clients tied to a lead assigned to
// them, or to a project they're on — super_admin/admin see everything,
// same pattern as Leads and Projects.
$currentUser = current_user();
if (!is_admin_role($currentUser)) {
    $filters['assigned_user_id'] = (int) $currentUser['id'];
}

$totalClients = count_clients($filters);
$pagination = paginate($totalClients, 15);
$clients = list_clients($filters, $pagination['perPage'], $pagination['offset']);

$calMonth = calendar_resolve_month();
$calendarEvents = get_client_calendar_events(
    $calMonth['start'],
    $calMonth['end'],
    is_admin_role($currentUser) ? null : (int) $currentUser['id']
);

$pageTitle = 'Clients';
$activeMenu = 'clients';
$breadcrumbs = [['label' => 'Clients', 'url' => null]];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Clients <span class="text-muted fs-6">(<?= e((string) $totalClients) ?>)</span></h1>
    <?php if (user_can(current_user(), 'clients', 'add')): ?>
        <a href="<?= e(url('clients/form.php')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Add Client</a>
    <?php endif; ?>
</div>

<?= render_calendar_widget('clientsCalendar', $calendarEvents, url('clients/index.php')) ?>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="<?= e(url('clients/index.php')) ?>" class="row g-2">
            <div class="col-12 col-md-6">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search company, contact, mobile, email, GSTIN"
                       value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-6 col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <?php foreach (client_status_options() as $status): ?>
                        <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill">Filter</button>
                <a href="<?= e(url('clients/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-3">
                <thead>
                    <tr>
                        <th>Company</th>
                        <th>Contact</th>
                        <th>Mobile</th>
                        <th>GSTIN</th>
                        <th>City</th>
                        <th>Assigned To</th>
                        <th>Status</th>
                        <th>Next Contact</th>
                        <th>Quick Contact</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($clients)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No clients found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($clients as $client): ?>
                        <tr>
                            <td><?= e($client['company_name']) ?></td>
                            <td><?= e($client['contact_name']) ?></td>
                            <td><?= e($client['mobile']) ?></td>
                            <td><?= e($client['gstin'] ?? '—') ?></td>
                            <td><?= e($client['city'] ?? '—') ?></td>
                            <td><?= e($client['assigned_to_name'] ?? '—') ?></td>
                            <td><span class="badge <?= e(client_status_badge_class($client['status'])) ?>"><?= e(ucfirst($client['status'])) ?></span></td>
                            <td>
                                <?php if (!empty($client['next_contact_at'])): ?>
                                    <span class="badge <?= e(client_contact_status_badge_class($client['contact_status'])) ?>">
                                        <?= e(date('d M, h:i A', strtotime($client['next_contact_at']))) ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="contact-actions">
                                    <a href="<?= e(tel_link($client['mobile'])) ?>"
                                       class="contact-action-btn call" title="Call <?= e($client['contact_name']) ?>">
                                        <i class="bi bi-telephone-fill"></i>
                                    </a>
                                    <?php $waNumber = $client['whatsapp'] ?: $client['mobile']; ?>
                                    <a href="<?= e(whatsapp_link($waNumber)) ?>" target="_blank" rel="noopener"
                                       class="contact-action-btn whatsapp" title="WhatsApp <?= e($client['contact_name']) ?>">
                                        <i class="bi bi-whatsapp"></i>
                                    </a>
                                </div>
                            </td>
                            <td class="text-end">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary" type="button"
                                            data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false"
                                            aria-label="Actions for <?= e($client['company_name']) ?>">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" href="<?= e(url('clients/view.php?id=' . $client['id'])) ?>">View</a></li>
                                        <?php if (user_can(current_user(), 'clients', 'edit')): ?>
                                            <li><a class="dropdown-item" href="<?= e(url('clients/form.php?id=' . $client['id'])) ?>">Edit</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="POST" action="<?= e(url('clients/index.php')) ?>" class="js-confirm-delete">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="client_id" value="<?= e((string) $client['id']) ?>">
                                                    <button type="submit" class="dropdown-item text-danger">Delete</button>
                                                </form>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
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

<?php require __DIR__ . '/../includes/footer.php'; ?>
