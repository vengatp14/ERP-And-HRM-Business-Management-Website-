<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $fullName = sanitize_string($_POST['full_name'] ?? '');
        $mobile = sanitize_string($_POST['mobile'] ?? '');
        $department = sanitize_string($_POST['department'] ?? '');
        $designation = sanitize_string($_POST['designation'] ?? '');

        $error = validate_required($fullName, 'Full name');

        if ($error !== null) {
            flash_set('error', $error);
        } else {
            $stmt = db()->prepare(
                'UPDATE users SET full_name = :name, mobile = :mobile, department = :dept, designation = :desig WHERE id = :id'
            );
            $stmt->execute([
                'name' => $fullName,
                'mobile' => $mobile !== '' ? $mobile : null,
                'dept' => $department !== '' ? $department : null,
                'desig' => $designation !== '' ? $designation : null,
                'id' => $user['id'],
            ]);
            audit_log((int) $user['id'], 'profile', 'update', 'Profile details updated.');
            flash_set('status', 'Profile updated successfully.');
        }
        redirect('profile.php');
    }

    if ($action === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirmation'] ?? '');

        $error = validate_required($current, 'Current password')
            ?? validate_required($new, 'New password')
            ?? validate_strong_password($new)
            ?? validate_matches($new, $confirm, 'New passwords do not match.');

        if ($error !== null) {
            flash_set('error', $error);
        } elseif (!change_password((int) $user['id'], $current, $new)) {
            flash_set('error', 'Current password is incorrect.');
        } else {
            flash_set('status', 'Password changed successfully.');
        }
        redirect('profile.php');
    }
}

$recentLogins = get_login_history((int) $user['id'], 10);

$pageTitle = 'My Profile';
$activeMenu = 'profile';
$breadcrumbs = [['label' => 'Profile', 'url' => null]];

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/navbar.php';
?>

<div class="row g-4">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Profile Details</h2></div>
            <div class="card-body">
                <form method="POST" action="<?= e(url('profile.php')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_profile">

                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-muted small">(cannot be changed)</span></label>
                        <input type="email" class="form-control" value="<?= e($user['email']) ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label for="full_name" class="form-label">Full name</label>
                        <input type="text" class="form-control" id="full_name" name="full_name" required value="<?= e($user['full_name']) ?>">
                    </div>
                    <div class="mb-3">
                        <label for="mobile" class="form-label">Mobile</label>
                        <input type="tel" class="form-control" id="mobile" name="mobile" value="<?= e($user['mobile'] ?? '') ?>">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label for="department" class="form-label">Department</label>
                            <input type="text" class="form-control" id="department" name="department" value="<?= e($user['department'] ?? '') ?>">
                        </div>
                        <div class="col-6 mb-3">
                            <label for="designation" class="form-label">Designation</label>
                            <input type="text" class="form-control" id="designation" name="designation" value="<?= e($user['designation'] ?? '') ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Change Password</h2></div>
            <div class="card-body">
                <form method="POST" action="<?= e(url('profile.php')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">

                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                    </div>
                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                        <div class="form-text">At least 8 characters, with uppercase, lowercase, a number, and a symbol.</div>
                    </div>
                    <div class="mb-3">
                        <label for="new_password_confirmation" class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" id="new_password_confirmation" name="new_password_confirmation" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Recent Login Activity</h2></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead><tr><th>Date &amp; Time</th><th>IP Address</th><th>Device / Browser</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentLogins as $login): ?>
                                <tr>
                                    <td><?= e($login['logged_in_at']) ?></td>
                                    <td><?= e($login['ip_address']) ?></td>
                                    <td class="text-truncate d-inline-block" style="max-width: 320px;"><?= e($login['user_agent'] ?? 'Unknown') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
