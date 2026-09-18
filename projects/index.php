<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('projects');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify_or_die();
    require_permission('projects', 'edit');
    $projectId = (int) ($_POST['project_id'] ?? 0);
    soft_delete_project($projectId);
    audit_log((int) current_user()['id'], 'projects', 'delete', "Deleted project #{$projectId}.");
    flash_set('status', 'Project deleted.');
    redirect('projects/index.php');
}

$filters = [
    'status' => $_GET['status'] ?? '',
    'priority' => $_GET['priority'] ?? '',
    'search' => trim((string) ($_GET['q'] ?? '')),
];

// Plain 'employee' accounts only see the projects/properties they've
// been assigned to (as manager or team member) — super_admin/admin see
// everything, same as everywhere else permission-related.
$currentUser = current_user();
if (!is_admin_role($currentUser)) {
    $filters['assigned_user_id'] = (int) $currentUser['id'];
}

$totalProjects = count_projects($filters);
$pagination = paginate($totalProjects, 15);
$projects = list_projects($filters, $pagination['perPage'], $pagination['offset']);

$calMonth = calendar_resolve_month();
$calendarEvents = get_project_calendar_events(
    $calMonth['start'],
    $calMonth['end'],
    is_admin_role($currentUser) ? null : (int) $currentUser['id']
);

$pageTitle = 'Projects';
$activeMenu = 'projects';
$breadcrumbs = [['label' => 'Projects', 'url' => null]];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Projects <span class="text-muted fs-6">(<?= e((string) $totalProjects) ?>)</span></h1>
    <?php if (user_can(current_user(), 'projects', 'add')): ?>
        <a href="<?= e(url('projects/form.php')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Add Project</a>
    <?php endif; ?>
</div>

<?= render_calendar_widget('projectsCalendar', $calendarEvents, url('projects/index.php')) ?>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="<?= e(url('projects/index.php')) ?>" class="row g-2">
            <div class="col-12 col-md-5">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search title or client"
                       value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-6 col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <?php foreach (['planning','in_progress','on_hold','pending_approval','completed','cancelled'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $status))) ?></option>
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
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill">Filter</button>
                <a href="<?= e(url('projects/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Reset</a>
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
                        <th>Title</th>
                        <th>Client</th>
                        <th>Manager</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Deadline</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($projects)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No projects found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($projects as $project): ?>
                        <tr>
                            <td><?= e($project['title']) ?></td>
                            <td><?= field_or($project['client_name'] ?? null) ?></td>
                            <td><?= e($project['manager_name'] ?? 'Unassigned') ?></td>
                            <td><span class="badge <?= e(lead_priority_badge_class($project['priority'])) ?>"><?= e(ucfirst($project['priority'])) ?></span></td>
                            <td><span class="badge <?= e(project_status_badge_class($project['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', $project['status']))) ?></span></td>
                            <td><?= field_or($project['deadline'] ?? null, 'Not set') ?></td>
                            <td class="text-end">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary" type="button"
                                            data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false"
                                            aria-label="Actions for <?= e($project['title']) ?>">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" href="<?= e(url('projects/view.php?id=' . $project['id'])) ?>">View</a></li>
                                        <?php if (user_can(current_user(), 'projects', 'edit')): ?>
                                            <li><a class="dropdown-item" href="<?= e(url('projects/form.php?id=' . $project['id'])) ?>">Edit</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="POST" action="<?= e(url('projects/index.php')) ?>" class="js-confirm-delete">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="project_id" value="<?= e((string) $project['id']) ?>">
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
