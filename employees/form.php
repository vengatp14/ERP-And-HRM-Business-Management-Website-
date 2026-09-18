<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_role('super_admin', 'admin');

$employeeId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$employee = $employeeId !== null ? find_user_by_id($employeeId) : null;

if ($employeeId !== null && $employee === false) {
    flash_set('error', 'Employee not found.');
    redirect('employees/index.php');
}

$isEdit = $employee !== null;
$tempPassword = null;
$photoError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();

    $fullName = sanitize_string($_POST['full_name'] ?? '');
    $email = trim((string) ($_POST['email'] ?? ''));
    $mobile = sanitize_string($_POST['mobile'] ?? '') ?: null;
    $alternateMobile = sanitize_string($_POST['alternate_mobile'] ?? '') ?: null;
    $department = sanitize_string($_POST['department'] ?? '') ?: null;
    $designation = sanitize_string($_POST['designation'] ?? '') ?: null;
    $dateOfBirth = $_POST['date_of_birth'] ?? '';
    $dateOfBirth = $dateOfBirth !== '' ? $dateOfBirth : null;
    $bloodGroup = sanitize_string($_POST['blood_group'] ?? '') ?: null;
    $address = sanitize_string($_POST['address'] ?? '') ?: null;
    $joiningDate = $_POST['joining_date'] ?? '';
    $joiningDate = $joiningDate !== '' ? $joiningDate : null;
    $role = $_POST['role'] ?? 'employee';
    $status = $_POST['status'] ?? 'active';
    $monthlySalary = (float) ($_POST['monthly_salary'] ?? 0);

    $error = validate_required($fullName, 'Full name')
        ?? validate_required($email, 'Email')
        ?? validate_email($email);

    if ($error === null && $monthlySalary < 0) {
        $error = 'Monthly salary cannot be negative.';
    }

    if ($error === null && !in_array($role, ['super_admin', 'admin', 'employee'], true)) {
        $error = 'Invalid role.';
    }
    if ($error === null && !in_array($status, ['active', 'inactive', 'suspended'], true)) {
        $error = 'Invalid status.';
    }
    // Only a super_admin may grant/change the super_admin or admin role.
    if ($error === null && in_array($role, ['super_admin', 'admin'], true) && current_user()['role'] !== 'super_admin') {
        $error = 'Only a super admin can assign that role.';
    }

    if ($error === null && !$isEdit) {
        $existing = find_user_by_email($email);
        if ($existing !== false) {
            $error = 'An account with that email already exists.';
        }
    }

    if ($error !== null) {
        flash_set('error', $error);
        redirect('employees/form.php' . ($isEdit ? '?id=' . $employeeId : ''));
    }

    // Permission checkboxes only apply to plain 'employee' accounts —
    // super_admin/admin always have full access regardless of what's
    // (or isn't) selected here.
    $selectedPermissions = array_keys((array) ($_POST['perms'] ?? []));

    // Profile photo is optional on every save; only overwrite the
    // stored filename when a new file was actually chosen.
    $newPhotoFilename = null;
    if (!empty($_FILES['profile_photo']['name'] ?? '')) {
        $uploadResult = handle_upload($_FILES['profile_photo'], 'images');
        if (!$uploadResult['ok']) {
            flash_set('error', $uploadResult['error']);
            redirect('employees/form.php' . ($isEdit ? '?id=' . $employeeId : ''));
        }
        $newPhotoFilename = $uploadResult['stored_filename'];
    }

    if ($isEdit) {
        $stmt = db()->prepare(
            'UPDATE users SET full_name = :full_name, mobile = :mobile, alternate_mobile = :alternate_mobile,
             department = :department, designation = :designation, date_of_birth = :date_of_birth,
             blood_group = :blood_group, address = :address, joining_date = :joining_date,
             role = :role, status = :status, monthly_salary = :monthly_salary,
             profile_photo = COALESCE(:profile_photo, profile_photo), updated_at = :now WHERE id = :id'
        );
        $stmt->execute([
            'full_name' => $fullName,
            'mobile' => $mobile,
            'alternate_mobile' => $alternateMobile,
            'department' => $department,
            'designation' => $designation,
            'date_of_birth' => $dateOfBirth,
            'blood_group' => $bloodGroup,
            'address' => $address,
            'joining_date' => $joiningDate,
            'role' => $role,
            'status' => $status,
            'monthly_salary' => $monthlySalary,
            'profile_photo' => $newPhotoFilename,
            'now' => date('Y-m-d H:i:s'),
            'id' => $employeeId,
        ]);
        if ($role === 'employee') {
            set_user_permission_keys($employeeId, $selectedPermissions);
        }
        audit_log((int) current_user()['id'], 'employees', 'employee_updated', "Updated employee #{$employeeId} ({$fullName}).");
        flash_set('status', 'Employee updated successfully.');
        redirect('employees/index.php');
    }

    $tempPassword = generate_temp_password();
    $newId = create_user([
        'full_name' => $fullName,
        'email' => $email,
        'mobile' => $mobile,
        'alternate_mobile' => $alternateMobile,
        'department' => $department,
        'designation' => $designation,
        'date_of_birth' => $dateOfBirth,
        'blood_group' => $bloodGroup,
        'address' => $address,
        'joining_date' => $joiningDate,
        'role' => $role,
        'status' => $status,
        'monthly_salary' => $monthlySalary,
        'profile_photo' => $newPhotoFilename,
        'password_hash' => password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => 12]),
        'must_change_password' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    if ($role === 'employee') {
        set_user_permission_keys($newId, $selectedPermissions);
    }
    audit_log((int) current_user()['id'], 'employees', 'employee_created', "Created employee #{$newId} ({$fullName}).");
    flash_set('status', "Employee created. Temporary password: {$tempPassword} — share this securely; they'll be required to change it at first login.");
    redirect('employees/index.php');
}

$grantedPermissions = $isEdit ? get_user_permission_keys($employeeId) : [];

// Group modules the way they're presented in the form: CRM / Finance / HR / Reports.
$permissionGroups = [];
foreach (MODULE_PERMISSIONS as $moduleKey => $moduleDef) {
    $permissionGroups[$moduleDef['group']][$moduleKey] = $moduleDef;
}

$pageTitle = $isEdit ? 'Edit Employee' : 'Add Employee';
$activeMenu = 'employees';
$breadcrumbs = [
    ['label' => 'Employees', 'url' => url('employees/index.php')],
    ['label' => $isEdit ? 'Edit' : 'Add', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="card">
    <div class="card-header bg-white"><h2 class="h6 mb-0"><?= e($pageTitle) ?></h2></div>
    <div class="card-body">
        <form method="POST" action="<?= e(url('employees/form.php' . ($isEdit ? '?id=' . $employeeId : ''))) ?>" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Profile Photo</label>
                    <div class="d-flex align-items-center gap-3">
                        <?php if (!empty($employee['profile_photo'])): ?>
                            <img src="<?= e(url('uploads/images/' . $employee['profile_photo'])) ?>" alt="Profile photo"
                                 class="rounded-circle" style="width:64px;height:64px;object-fit:cover;">
                        <?php else: ?>
                            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center text-muted"
                                 style="width:64px;height:64px;"><i class="bi bi-person fs-3"></i></div>
                        <?php endif; ?>
                        <input type="file" name="profile_photo" accept=".jpg,.jpeg,.png,.webp" class="form-control">
                    </div>
                    <div class="form-text">JPG, PNG or WEBP, up to 10MB. Leave empty to keep the current photo.</div>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="full_name" class="form-control" required
                           value="<?= e($employee['full_name'] ?? '') ?>">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label">Email *</label>
                    <input type="email" name="email" class="form-control" required
                           value="<?= e($employee['email'] ?? '') ?>" <?= $isEdit ? 'readonly' : '' ?>>
                    <?php if ($isEdit): ?><div class="form-text">Email can't be changed here.</div><?php endif; ?>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">Mobile</label>
                    <input type="tel" name="mobile" class="form-control" value="<?= e($employee['mobile'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Alternate Mobile</label>
                    <input type="tel" name="alternate_mobile" class="form-control" value="<?= e($employee['alternate_mobile'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Department</label>
                    <input type="text" name="department" class="form-control" value="<?= e($employee['department'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Designation</label>
                    <input type="text" name="designation" list="designationSuggestions" class="form-control" value="<?= e($employee['designation'] ?? '') ?>">
                    <datalist id="designationSuggestions">
                        <option value="Software Developer">
                        <option value="Graphic Designer">
                        <option value="Project Manager">
                        <option value="HR Executive">
                        <option value="Sales Executive">
                    </datalist>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="date_of_birth" class="form-control" value="<?= e($employee['date_of_birth'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Blood Group</label>
                    <select name="blood_group" class="form-select">
                        <option value="">Not selected</option>
                        <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bg): ?>
                            <option value="<?= e($bg) ?>" <?= ($employee['blood_group'] ?? '') === $bg ? 'selected' : '' ?>><?= e($bg) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Date of Joining</label>
                    <input type="date" name="joining_date" class="form-control" value="<?= e($employee['joining_date'] ?? '') ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (['active', 'inactive', 'suspended'] as $s): ?>
                            <option value="<?= e($s) ?>" <?= ($employee['status'] ?? 'active') === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2"><?= e($employee['address'] ?? '') ?></textarea>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">Monthly Salary (₹)</label>
                    <input type="number" step="0.01" min="0" name="monthly_salary" class="form-control"
                           value="<?= e((string) ($employee['monthly_salary'] ?? '0')) ?>">
                    <div class="form-text">Full pay at 100% attendance for the month.</div>
                </div>

                <div class="col-6 col-md-4">
                    <label class="form-label">Role</label>
                    <select name="role" class="form-select" <?= current_user()['role'] !== 'super_admin' ? 'disabled' : '' ?>>
                        <?php foreach (['employee', 'admin', 'super_admin'] as $r): ?>
                            <option value="<?= e($r) ?>" <?= ($employee['role'] ?? 'employee') === $r ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $r))) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (current_user()['role'] !== 'super_admin'): ?>
                        <input type="hidden" name="role" value="<?= e($employee['role'] ?? 'employee') ?>">
                        <div class="form-text">Only a super admin can change roles.</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$isEdit): ?>
                <div class="alert alert-info mt-3 mb-0 small">
                    A temporary password will be generated automatically and shown once after you submit — the
                    employee will be required to change it on first login.
                </div>
            <?php endif; ?>

            <hr class="my-4">

            <div id="menuAccessSection">
                <h3 class="h6 mb-1">Menu Access</h3>
                <p class="text-muted small mb-3">
                    Tick View to let this person see a menu, then Add / Edit for what they can do inside it.
                    Doesn't apply to Admin / Super Admin — those always have full access.
                </p>
                <?php foreach ($permissionGroups as $groupLabel => $groupModules): ?>
                    <div class="mb-3">
                        <div class="text-uppercase text-muted small fw-semibold mb-2" style="letter-spacing:.04em;"><?= e($groupLabel) ?></div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0" style="max-width: 480px;">
                                <?php foreach ($groupModules as $moduleKey => $moduleDef): ?>
                                    <tr>
                                        <td class="ps-0 border-0"><?= e($moduleDef['label']) ?></td>
                                        <?php foreach ($moduleDef['actions'] as $actionKey => $actionLabel): ?>
                                            <?php $permKey = $moduleKey . '.' . $actionKey; ?>
                                            <td class="border-0">
                                                <div class="form-check mb-0">
                                                    <input class="form-check-input" type="checkbox" name="perms[<?= e($permKey) ?>]" value="1"
                                                           id="perm_<?= e($permKey) ?>" <?= in_array($permKey, $grantedPermissions, true) ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="perm_<?= e($permKey) ?>"><?= e($actionLabel) ?></label>
                                                </div>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div id="menuAccessFullNotice" class="alert alert-secondary small d-none">
                This role already has full access to every menu — nothing to select.
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Employee' ?></button>
                <a href="<?= e(url('employees/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    var roleSelect = document.querySelector('select[name="role"]');
    var section = document.getElementById('menuAccessSection');
    var notice = document.getElementById('menuAccessFullNotice');

    function syncMenuAccessVisibility() {
        var role = roleSelect ? roleSelect.value : 'employee';
        var isEmployee = role === 'employee';
        section.classList.toggle('d-none', !isEmployee);
        notice.classList.toggle('d-none', isEmployee);
    }

    if (roleSelect) {
        roleSelect.addEventListener('change', syncMenuAccessVisibility);
    }
    syncMenuAccessVisibility();
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
