<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('leads');

// Manual trigger for the missed/cancelled automation (see includes/leads.php
// for why this is on-demand rather than cron-driven in this environment).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_missed') {
    csrf_verify_or_die();
    $result = process_missed_leads();
    flash_set('status', "Checked {$result['checked']} overdue lead(s): {$result['missed']} marked missed, {$result['cancelled']} auto-cancelled.");
    redirect('leads/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify_or_die();
    require_permission('leads', 'edit');
    $leadId = (int) ($_POST['lead_id'] ?? 0);
    soft_delete_lead($leadId);
    audit_log((int) current_user()['id'], 'leads', 'delete', "Deleted lead #{$leadId}.");
    flash_set('status', 'Lead deleted.');
    redirect('leads/index.php');
}

// Also run it opportunistically on every list view (cheap query if
// nothing's overdue), so statuses stay accurate without needing cron.
process_missed_leads();

$filters = [
    'status' => $_GET['status'] ?? '',
    'priority' => $_GET['priority'] ?? '',
    'source' => $_GET['source'] ?? '',
    'search' => trim((string) ($_GET['q'] ?? '')),
];

// All logged-in users with menu access to Leads see every lead,
// regardless of role — leads are not scoped by assignment.
$currentUser = current_user();

$totalLeads = count_leads($filters);
$pagination = paginate($totalLeads, 15);
$leads = list_leads($filters, $pagination['perPage'], $pagination['offset']);

$calMonth = calendar_resolve_month();
$calendarEvents = get_lead_calendar_events($calMonth['start'], $calMonth['end'], null);

$pageTitle = 'Leads';
$activeMenu = 'leads';
$breadcrumbs = [['label' => 'Leads', 'url' => null]];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Leads <span class="text-muted fs-6">(<?= e((string) $totalLeads) ?>)</span></h1>
    <div class="d-flex gap-2">
        <?php if (user_can(current_user(), 'leads', 'add')): ?>
            <a href="<?= e(url('leads/form.php')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Add Lead</a>
        <?php endif; ?>
    </div>
</div>

<?= render_calendar_widget('leadsCalendar', $calendarEvents, url('leads/index.php')) ?>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="<?= e(url('leads/index.php')) ?>" class="row g-2">
            <div class="col-12 col-md-4">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name, company, mobile, email"
                       value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <?php foreach (['new','contacted','qualified','proposal','negotiation','won','lost','missed','cancelled'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="priority" class="form-select form-select-sm">
                    <option value="">All Priorities</option>
                    <?php foreach (['low','medium','high'] as $priority): ?>
                        <option value="<?= e($priority) ?>" <?= $filters['priority'] === $priority ? 'selected' : '' ?>><?= e(ucfirst($priority)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="source" class="form-select form-select-sm">
                    <option value="">All Sources</option>
                    <?php foreach (['website','referral','social_media','cold_call','walk_in','advertisement','other'] as $source): ?>
                        <option value="<?= e($source) ?>" <?= $filters['source'] === $source ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $source))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill">Filter</button>
                <a href="<?= e(url('leads/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-3 leads-table">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Mobile</th>
                        <th class="leads-priority-status-col">Priority / Status</th>
                        <th class="leads-deadline-col">Follow-up</th>
                        <th>Contact</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leads)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No leads found.</td></tr>
                    <?php endif; ?>
                    <?php
                    $terminalStatuses = ['won', 'lost', 'cancelled'];
                    $now = date('Y-m-d H:i:s');
                    $today = date('Y-m-d');
                    ?>
                    <?php foreach ($leads as $lead): ?>
                        <?php
                        $followUp = $lead['next_follow_up_at'];
                        $isTerminal = in_array($lead['status'], $terminalStatuses, true);
                        $isOverdue = $followUp !== null && !$isTerminal && $followUp < $now;
                        $isDueToday = $followUp !== null && !$isTerminal && !$isOverdue && substr($followUp, 0, 10) === $today;
                        $followUpClass = $isOverdue ? 'followup-overdue' : ($isDueToday ? 'followup-today' : '');
                        $rowClass = $lead['status'] === 'missed' ? 'lead-row-missed' : '';
                        ?>
                        <tr class="<?= e($rowClass) ?>">
                            <td>
                                <?= e($lead['client_name']) ?>
                                <?php if (!empty($lead['company'])): ?>
                                    <span class="d-block text-muted small"><?= e($lead['company']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($lead['mobile']) ?></td>
                            <td class="leads-priority-status-col">
                                <span class="badge <?= e(lead_priority_badge_class($lead['priority'])) ?>"><?= e(ucfirst($lead['priority'])) ?></span>
                                <span class="badge <?= e(lead_status_badge_class($lead['status'])) ?>"><?= e(ucfirst($lead['status'])) ?></span>
                            </td>
                            <td class="leads-deadline-col">
                                <span class="d-block small <?= e($followUpClass) ?>">
                                    <?php if ($followUp !== null): ?>
                                        <?php if ($isOverdue): ?>
                                            <i class="bi bi-exclamation-triangle-fill me-1" title="Overdue"></i>
                                        <?php elseif ($isDueToday): ?>
                                            <i class="bi bi-bell-fill me-1" title="Due today"></i>
                                        <?php endif; ?>
                                        <?= e(date('d M Y, h:i A', strtotime($followUp))) ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <div class="contact-actions">
                                    <a href="<?= e(tel_link($lead['mobile'])) ?>"
                                       class="contact-action-btn call" title="Call <?= e($lead['client_name']) ?>">
                                        <i class="bi bi-telephone-fill"></i>
                                    </a>
                                    <?php $waNumber = $lead['whatsapp'] ?: $lead['mobile']; ?>
                                    <a href="<?= e(whatsapp_link($waNumber)) ?>" target="_blank" rel="noopener"
                                       class="contact-action-btn whatsapp" title="WhatsApp <?= e($lead['client_name']) ?>">
                                        <i class="bi bi-whatsapp"></i>
                                    </a>
                                </div>
                            </td>
                            <td class="text-end">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary" type="button"
                                            data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false"
                                            aria-label="Actions for <?= e($lead['client_name']) ?>">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" href="<?= e(url('leads/view.php?id=' . $lead['id'])) ?>">View</a></li>
                                        <?php if (user_can(current_user(), 'leads', 'edit')): ?>
                                            <li><a class="dropdown-item" href="<?= e(url('leads/form.php?id=' . $lead['id'])) ?>">Edit</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="POST" action="<?= e(url('leads/index.php')) ?>" class="js-confirm-delete">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="lead_id" value="<?= e((string) $lead['id']) ?>">
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
