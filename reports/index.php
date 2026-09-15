<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
// Reports is admin-only, same hard role gate as Project Board /
// Company Branding — intentionally not on the granular per-module
// permission system, so a manager who was merely granted reports.view
// (from before this was locked down) can't reach it anymore.
require_role('super_admin', 'admin');

$leadsByStatus = leads_by_status();
$conversionRate = leads_conversion_rate();
$leadsMonthly = leads_by_month(6);
$revenueExpenseMonthly = revenue_vs_expense_by_month(6);
$projectsByStatus = projects_by_status();
$topClients = top_clients_by_revenue(5);
$cancelledImpact = cancelled_projects_income_impact();

$pageTitle = 'Reports';
$activeMenu = 'reports';
$breadcrumbs = [['label' => 'Reports', 'url' => null]];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<h1 class="h4 mb-3">Reports</h1>

<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="text-muted small">Lead Conversion Rate</div>
                <div class="fs-4 fw-semibold"><?= e((string) $conversionRate) ?>%</div>
                <div class="text-muted small">of leads reaching a final outcome</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="text-muted small">Total Leads</div>
                <div class="fs-4 fw-semibold"><?= e((string) array_sum($leadsByStatus)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="text-muted small">Active Projects</div>
                <div class="fs-4 fw-semibold"><?= e((string) count_active_projects()) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body">
                <div class="text-muted small">Outstanding Receivables</div>
                <div class="fs-4 fw-semibold">₹<?= e(number_format(total_outstanding_receivables(), 2)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card stat-card h-100 border-danger-subtle">
            <div class="card-body">
                <div class="text-muted small">Rejected Projects</div>
                <div class="fs-4 fw-semibold text-danger"><?= e((string) $cancelledImpact['project_count']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card stat-card h-100 border-danger-subtle">
            <div class="card-body">
                <div class="text-muted small">Income Reduced (Rejected Projects)</div>
                <div class="fs-4 fw-semibold text-danger">₹<?= e(number_format($cancelledImpact['income_amount'], 2)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Income vs. Expenses (Last 6 Months)</h2></div>
            <div class="card-body"><canvas id="revenueChart" height="110"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Leads by Status</h2></div>
            <div class="card-body"><canvas id="leadsStatusChart" height="110"></canvas></div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header bg-white"><h2 class="h6 mb-0">New Leads (Last 6 Months)</h2></div>
            <div class="card-body"><canvas id="leadsMonthlyChart" height="110"></canvas></div>
        </div>
    </div>
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Projects by Status</h2></div>
            <div class="card-body"><canvas id="projectsStatusChart" height="110"></canvas></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Top Clients by Revenue</h2></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead><tr><th>Client</th><th class="text-end">Total Billed</th><th class="text-end">Total Collected</th></tr></thead>
                <tbody>
                    <?php if (empty($topClients)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">No invoiced revenue yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($topClients as $client): ?>
                        <tr>
                            <td><?= e($client['company_name']) ?></td>
                            <td class="text-end">₹<?= e(number_format((float) $client['total_billed'], 2)) ?></td>
                            <td class="text-end">₹<?= e(number_format((float) $client['total_paid'], 2)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script nonce="<?= e(csp_nonce()) ?>">
const revenueData = <?= json_encode($revenueExpenseMonthly, JSON_THROW_ON_ERROR) ?>;
const leadsStatusData = <?= json_encode($leadsByStatus, JSON_THROW_ON_ERROR) ?>;
const leadsMonthlyData = <?= json_encode($leadsMonthly, JSON_THROW_ON_ERROR) ?>;
const projectsStatusData = <?= json_encode($projectsByStatus, JSON_THROW_ON_ERROR) ?>;

new Chart(document.getElementById('revenueChart'), {
    type: 'bar',
    data: {
        labels: revenueData.map(r => r.month),
        datasets: [
            { label: 'Income', data: revenueData.map(r => r.income), backgroundColor: '#198754' },
            { label: 'Expenses', data: revenueData.map(r => r.expenses), backgroundColor: '#dc3545' }
        ]
    },
    options: { responsive: true, scales: { y: { beginAtZero: true } } }
});

new Chart(document.getElementById('leadsStatusChart'), {
    type: 'doughnut',
    data: {
        labels: Object.keys(leadsStatusData).map(s => s.charAt(0).toUpperCase() + s.slice(1)),
        datasets: [{ data: Object.values(leadsStatusData), backgroundColor: ['#0d6efd','#6f42c1','#fd7e14','#20c997','#198754','#dc3545','#ffc107','#6c757d'] }]
    },
    options: { responsive: true }
});

new Chart(document.getElementById('leadsMonthlyChart'), {
    type: 'line',
    data: {
        labels: leadsMonthlyData.map(r => r.month),
        datasets: [{ label: 'New Leads', data: leadsMonthlyData.map(r => r.count), borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.15)', fill: true, tension: 0.3 }]
    },
    options: { responsive: true, scales: { y: { beginAtZero: true } } }
});

new Chart(document.getElementById('projectsStatusChart'), {
    type: 'doughnut',
    data: {
        labels: Object.keys(projectsStatusData).map(s => s.replace('_',' ').replace(/\b\w/g, c => c.toUpperCase())),
        datasets: [{ data: Object.values(projectsStatusData), backgroundColor: ['#0dcaf0','#0d6efd','#ffc107','#198754','#6c757d'] }]
    },
    options: { responsive: true }
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
