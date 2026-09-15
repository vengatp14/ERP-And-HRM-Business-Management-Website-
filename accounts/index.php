<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('accounts');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify_or_die();
    require_permission('accounts', 'edit');

    $source = $_POST['source'] ?? '';
    $sourceId = (int) ($_POST['source_id'] ?? 0);

    match ($source) {
        'expense' => soft_delete_expense($sourceId),
        'project_income' => soft_delete_project_income($sourceId),
        'invoice_payment' => delete_invoice_payment($sourceId),
        default => null,
    };

    audit_log((int) current_user()['id'], 'accounts', 'delete', "Deleted {$source} ledger entry #{$sourceId}.");
    flash_set('status', 'Entry deleted.');
    redirect('accounts/index.php?start_date=' . urlencode((string) ($_POST['start_date'] ?? '')) . '&end_date=' . urlencode((string) ($_POST['end_date'] ?? '')));
}

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$income = total_income($startDate, $endDate);
$expenses = total_expenses($startDate, $endDate);
$net = $income - $expenses;
$outstanding = total_outstanding_receivables();
$entries = get_ledger_entries($startDate, $endDate);

// Financial overview graph — separate period picker from the ledger's
// own date filter above, since the graph is meant for a quick at-a-
// glance overview rather than the transaction-level ledger view.
$chartPeriodOptions = accounts_chart_period_options();
$chartPeriod = $_GET['chart_period'] ?? 'this_month';
if (!array_key_exists($chartPeriod, $chartPeriodOptions)) {
    $chartPeriod = 'this_month';
}
['start' => $chartStart, 'end' => $chartEnd] = accounts_chart_period_bounds(
    $chartPeriod,
    $_GET['chart_start'] ?? null,
    $_GET['chart_end'] ?? null
);
$chartSeries = accounts_chart_series($chartStart, $chartEnd);
$chartGstTotal = array_sum($chartSeries['gst']);

$pageTitle = 'Accounts';
$activeMenu = 'accounts';
$breadcrumbs = [['label' => 'Accounts', 'url' => null]];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Accounts</h1>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= e(url('accounts/gst_report.php')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> GST Report</a>
        <?php if (is_admin_role(current_user())): ?>
            <a href="<?= e(url('accounts/project_payments.php')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-calendar-check"></i> Monthly Payments</a>
        <?php endif; ?>
        <a href="<?= e(url('accounts/expenses.php')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list-ul"></i> Manage Expenses</a>
        <?php if (is_admin_role(current_user())): ?>
            <a href="<?= e(url('accounts/recurring_expenses.php')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-repeat"></i> Auto Expenses</a>
        <?php endif; ?>
        <?php if (user_can(current_user(), 'accounts', 'add')): ?>
            <a href="<?= e(url('accounts/expense_form.php')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Add Expense</a>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" action="<?= e(url('accounts/index.php')) ?>" class="row g-2">
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="start_date" class="form-control form-control-sm" value="<?= e($startDate) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="end_date" class="form-control form-control-sm" value="<?= e($endDate) ?>">
            </div>
            <div class="col-12 col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                <a href="<?= e(url('accounts/index.php')) ?>" class="btn btn-outline-secondary btn-sm">This Month</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-arrow-down-circle"></i></div>
                <div><div class="text-muted small">Income</div><div class="fs-5 fw-semibold">₹<?= e(number_format($income, 2)) ?></div></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-danger-subtle text-danger"><i class="bi bi-arrow-up-circle"></i></div>
                <div><div class="text-muted small">Expenses</div><div class="fs-5 fw-semibold">₹<?= e(number_format($expenses, 2)) ?></div></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-wallet2"></i></div>
                <div><div class="text-muted small">Net (Period)</div><div class="fs-5 fw-semibold <?= $net < 0 ? 'text-danger' : '' ?>">₹<?= e(number_format($net, 2)) ?></div></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-hourglass-split"></i></div>
                <div><div class="text-muted small">Outstanding Receivables</div><div class="fs-5 fw-semibold">₹<?= e(number_format($outstanding, 2)) ?></div></div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="h6 mb-0">Financial Overview</h2>
        <form method="GET" action="<?= e(url('accounts/index.php')) ?>" class="d-flex flex-wrap align-items-center gap-2" id="chartPeriodForm">
            <input type="hidden" name="start_date" value="<?= e($startDate) ?>">
            <input type="hidden" name="end_date" value="<?= e($endDate) ?>">
            <select name="chart_period" id="chartPeriodSelect" class="form-select form-select-sm" style="width:auto;" onchange="document.getElementById('customPeriodFields').classList.toggle('d-none', this.value !== 'custom'); if (this.value !== 'custom') this.form.submit();">
                <?php foreach ($chartPeriodOptions as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $chartPeriod === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <div id="customPeriodFields" class="d-flex align-items-center gap-2 <?= $chartPeriod === 'custom' ? '' : 'd-none' ?>">
                <input type="date" name="chart_start" class="form-control form-control-sm" value="<?= e($chartStart) ?>">
                <span class="text-muted small">to</span>
                <input type="date" name="chart_end" class="form-control form-control-sm" value="<?= e($chartEnd) ?>">
                <button type="submit" class="btn btn-outline-primary btn-sm">Apply</button>
            </div>
        </form>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-6 col-lg-2">
                <div class="border rounded p-2 text-center h-100">
                    <div class="text-muted small">Income</div>
                    <div class="fw-semibold">₹<?= e(number_format(array_sum($chartSeries['income']), 2)) ?></div>
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <div class="border rounded p-2 text-center h-100">
                    <div class="text-muted small">Expenses</div>
                    <div class="fw-semibold">₹<?= e(number_format(array_sum($chartSeries['expenses']), 2)) ?></div>
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <div class="border rounded p-2 text-center h-100">
                    <div class="text-muted small">Net</div>
                    <div class="fw-semibold <?= array_sum($chartSeries['net']) < 0 ? 'text-danger' : '' ?>">₹<?= e(number_format(array_sum($chartSeries['net']), 2)) ?></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="border rounded p-2 text-center h-100">
                    <div class="text-muted small">Outstanding Receivables <span class="d-block" style="font-size:.7rem;">(current)</span></div>
                    <div class="fw-semibold">₹<?= e(number_format($chartSeries['outstanding_receivables'], 2)) ?></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="border rounded p-2 text-center h-100">
                    <div class="text-muted small">GST</div>
                    <div class="fw-semibold">₹<?= e(number_format($chartGstTotal, 2)) ?></div>
                </div>
            </div>
        </div>
        <div style="position:relative; height: 320px;">
            <canvas id="accountsFinancialChart"></canvas>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    var canvas = document.getElementById('accountsFinancialChart');
    if (!canvas || typeof Chart === 'undefined') {
        return;
    }
    var labels = <?= json_encode($chartSeries['labels']) ?>;
    var income = <?= json_encode($chartSeries['income']) ?>;
    var expenses = <?= json_encode($chartSeries['expenses']) ?>;
    var net = <?= json_encode($chartSeries['net']) ?>;
    var gst = <?= json_encode($chartSeries['gst']) ?>;
    var outstanding = <?= json_encode($chartSeries['outstanding_receivables']) ?>;

    new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                { type: 'bar', label: 'Income', data: income, backgroundColor: '#198754' },
                { type: 'bar', label: 'Expenses', data: expenses, backgroundColor: '#dc3545' },
                { type: 'line', label: 'Net', data: net, borderColor: '#0d6efd', backgroundColor: '#0d6efd', tension: 0.3, fill: false },
                { type: 'line', label: 'GST', data: gst, borderColor: '#fd7e14', backgroundColor: '#fd7e14', borderDash: [4, 3], tension: 0.3, fill: false },
                { type: 'line', label: 'Outstanding Receivables (current)', data: labels.map(function () { return outstanding; }), borderColor: '#6f42c1', borderDash: [2, 2], pointRadius: 0, fill: false },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { ticks: { callback: function (v) { return '₹' + v.toLocaleString('en-IN'); } } } }
        }
    });
})();
</script>

<div class="card">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Ledger</h2></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Bill</th><th class="text-end">Amount</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No transactions in this period.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($entries as $entry): ?>
                        <?php
                            $amt = (float) $entry['amount'];
                            $isExpense = $entry['type'] === 'expense';
                            $isCorrection = $entry['type'] === 'correction';
                            $badgeClass = $isExpense ? 'text-bg-danger' : ($isCorrection ? 'text-bg-secondary' : 'text-bg-success');
                            $badgeLabel = $isExpense ? 'Expense' : ($isCorrection ? 'Correction' : 'Income');
                            // Expenses are stored as positive magnitudes (expenses.amount has
                            // no sign column), so the ledger's signed representation of a row
                            // must be derived from its type, not from the raw amount's sign —
                            // otherwise every expense displays as a positive "+" even though
                            // it's money going out. Income/correction rows already carry their
                            // true sign in ip.amount, so only expenses need the flip here.
                            $signedAmt = $isExpense ? -abs($amt) : $amt;
                            $amountClass = $signedAmt < 0 ? 'text-danger' : 'text-success';
                            $sign = $signedAmt < 0 ? '−' : '+';
                        ?>
                        <tr>
                            <td><?= e($entry['date']) ?></td>
                            <td><span class="badge <?= e($badgeClass) ?>"><?= e($badgeLabel) ?></span></td>
                            <td><?= e($entry['description']) ?></td>
                            <td>
                                <?php if (!empty($entry['invoice_id'])): ?>
                                    <a href="<?= e(url('billing/view.php?id=' . $entry['invoice_id'])) ?>"><?= e($entry['invoice_number']) ?></a>
                                <?php elseif (!empty($entry['receipt_number'])): ?>
                                    <?= e($entry['receipt_number']) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="text-end <?= e($amountClass) ?>">
                                <?= e($sign) ?>₹<?= e(number_format(abs($amt), 2)) ?>
                            </td>
                            <td class="text-end">
                                <?php if (user_can(current_user(), 'accounts', 'edit')): ?>
                                    <form method="POST" action="<?= e(url('accounts/index.php')) ?>" class="js-confirm-delete d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="source" value="<?= e($entry['source']) ?>">
                                        <input type="hidden" name="source_id" value="<?= e((string) $entry['source_id']) ?>">
                                        <input type="hidden" name="start_date" value="<?= e($startDate) ?>">
                                        <input type="hidden" name="end_date" value="<?= e($endDate) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
