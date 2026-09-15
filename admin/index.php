<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_role('super_admin');

$search = trim((string) ($_GET['q'] ?? ''));

if ($search !== '') {
    $countStmt = db()->prepare('SELECT COUNT(*) AS cnt FROM users WHERE full_name LIKE :q1 OR email LIKE :q2');
    $countStmt->execute(['q1' => '%' . $search . '%', 'q2' => '%' . $search . '%']);
} else {
    $countStmt = db()->query('SELECT COUNT(*) AS cnt FROM users');
}
$totalUsers = (int) $countStmt->fetch()['cnt'];

$pagination = paginate($totalUsers, 10);

if ($search !== '') {
    $stmt = db()->prepare(
        'SELECT id, full_name, email, role, status, last_login_at, created_at FROM users
         WHERE full_name LIKE :q1 OR email LIKE :q2
         ORDER BY created_at DESC LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':q1', '%' . $search . '%');
    $stmt->bindValue(':q2', '%' . $search . '%');
} else {
    $stmt = db()->prepare(
        'SELECT id, full_name, email, role, status, last_login_at, created_at FROM users
         ORDER BY created_at DESC LIMIT :limit OFFSET :offset'
    );
}
$stmt->bindValue(':limit', $pagination['perPage'], PDO::PARAM_INT);
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

// Handle status toggle (suspend/reactivate) — a real, working admin action.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    csrf_verify_or_die();
    $targetId = (int) ($_POST['user_id'] ?? 0);
    $currentUserId = (int) current_user()['id'];

    if ($targetId === $currentUserId) {
        flash_set('error', "You can't change your own account status.");
    } else {
        $target = find_user_by_id($targetId);
        if ($target) {
            $newStatus = $target['status'] === 'active' ? 'suspended' : 'active';
            db()->prepare('UPDATE users SET status = :status WHERE id = :id')->execute(['status' => $newStatus, 'id' => $targetId]);
            audit_log($currentUserId, 'admin', 'user_status_change', "Set user #{$targetId} status to {$newStatus}.");
            flash_set('status', 'User status updated.');
        }
    }
    redirect('admin/index.php' . ($search !== '' ? '?q=' . urlencode($search) : ''));
}

$pageTitle = 'User Management';
$activeMenu = 'admin';
$breadcrumbs = [['label' => 'Admin', 'url' => null], ['label' => 'Users', 'url' => null]];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="card">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h6 mb-0">All Users (<?= e((string) $totalUsers) ?>)</h2>
        <form method="GET" action="<?= e(url('admin/index.php')) ?>" class="d-flex gap-2">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name or email"
                   value="<?= e($search) ?>">
            <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
        </form>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-3">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No users found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($users as $row): ?>
                        <tr>
                            <td><?= e($row['full_name']) ?></td>
                            <td><?= e($row['email']) ?></td>
                            <td><span class="badge text-bg-secondary"><?= e($row['role']) ?></span></td>
                            <td>
                                <span class="badge <?= $row['status'] === 'active' ? 'text-bg-success' : 'text-bg-danger' ?>">
                                    <?= e($row['status']) ?>
                                </span>
                            </td>
                            <td><?= e($row['last_login_at'] ?? 'Never') ?></td>
                            <td class="text-end">
                                <form method="POST" action="<?= e(url('admin/index.php')) ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?= e((string) $row['id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary"
                                            <?= (int) $row['id'] === (int) current_user()['id'] ? 'disabled' : '' ?>>
                                        <?= $row['status'] === 'active' ? 'Suspend' : 'Reactivate' ?>
                                    </button>
                                </form>
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
