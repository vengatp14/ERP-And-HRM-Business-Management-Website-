<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_role('super_admin', 'admin');

$certificateId = (int) ($_GET['id'] ?? 0);
$certificate = $certificateId > 0 ? find_certificate($certificateId) : false;

if (!$certificate) {
    flash_set('error', 'Certificate not found.');
    redirect('hr/certificates.php');
}

$pageTitle = (CERTIFICATE_TYPES[$certificate['type']] ?? 'Certificate') . ' — ' . $certificate['full_name'];
$activeMenu = 'employees';
$breadcrumbs = [
    ['label' => 'Employees', 'url' => url('employees/index.php')],
    ['label' => 'Certificates', 'url' => url('hr/certificates.php')],
    ['label' => CERTIFICATE_TYPES[$certificate['type']] ?? 'Certificate', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3 no-print">
    <h1 class="h4 mb-0"><?= e($pageTitle) ?></h1>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary btn-sm" id="printBtn"><i class="bi bi-printer"></i> Print / Save as PDF</button>
        <a href="<?= e(url('hr/certificates.php')) ?>" class="btn btn-outline-secondary btn-sm">Back</a>
    </div>
</div>

<div class="card" id="printArea">
    <div class="card-body p-4 p-md-5">
        <?= render_certificate_body($certificate) ?>
    </div>
</div>

<style>
@media print {
    .no-print, .app-sidebar, .app-navbar, nav, .btn { display: none !important; }
    .app-content { margin: 0 !important; padding: 0 !important; }
}
</style>

<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('printBtn')?.addEventListener('click', function () { window.print(); });
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
