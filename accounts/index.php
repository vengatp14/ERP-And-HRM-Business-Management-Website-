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
        <form class="d-flex flex-wrap align-items-center gap-2" id="chartPeriodForm">
            <select name="chart_period" id="chartPeriodSelect" class="form-select form-select-sm" style="width:auto;">
                <?php foreach ($chartPeriodOptions as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $chartPeriod === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <div id="customPeriodFields" class="d-flex align-items-center gap-2 <?= $chartPeriod === 'custom' ? '' : 'd-none' ?>">
                <label class="visually-hidden" for="chartStartInput">From date</label>
                <input type="date" id="chartStartInput" name="chart_start" class="form-control form-control-sm" value="<?= e($chartStart) ?>">
                <span class="text-muted small">to</span>
                <label class="visually-hidden" for="chartEndInput">To date</label>
                <input type="date" id="chartEndInput" name="chart_end" class="form-control form-control-sm" value="<?= e($chartEnd) ?>">
                <button type="submit" class="btn btn-outline-primary btn-sm" id="chartCustomApplyBtn">Apply</button>
            </div>
        </form>
    </div>
    <div class="card-body">
        <!--
            Metric cards are real controls (buttons), not decoration —
            clicking one filters the chart below to show that metric
            prominently; "All" (selected by default) shows the combined
            view. See the script at the bottom of this block.
        -->
        <div class="row g-3 mb-3" id="chartMetricCards" role="group" aria-label="Chart metric filter">
            <div class="col-6 col-lg">
                <button type="button" class="btn chart-metric-card w-100 h-100 text-center active" data-metric="all">
                    <div class="text-muted small">All</div>
                    <div class="fw-semibold">Combined</div>
                </button>
            </div>
            <div class="col-6 col-lg">
                <button type="button" class="btn chart-metric-card w-100 h-100 text-center" data-metric="income">
                    <div class="text-muted small">Income</div>
                    <div class="fw-semibold chart-metric-value" data-value-for="income">₹<?= e(number_format(array_sum($chartSeries['income']), 2)) ?></div>
                </button>
            </div>
            <div class="col-6 col-lg">
                <button type="button" class="btn chart-metric-card w-100 h-100 text-center" data-metric="expenses">
                    <div class="text-muted small">Expenses</div>
                    <div class="fw-semibold chart-metric-value" data-value-for="expenses">₹<?= e(number_format(array_sum($chartSeries['expenses']), 2)) ?></div>
                </button>
            </div>
            <div class="col-6 col-lg">
                <button type="button" class="btn chart-metric-card w-100 h-100 text-center" data-metric="net">
                    <div class="text-muted small">Net</div>
                    <div class="fw-semibold chart-metric-value" data-value-for="net"><?= array_sum($chartSeries['net']) < 0 ? '−' : '' ?>₹<?= e(number_format(abs(array_sum($chartSeries['net'])), 2)) ?></div>
                </button>
            </div>
            <div class="col-6 col-lg">
                <button type="button" class="btn chart-metric-card w-100 h-100 text-center" data-metric="outstanding_receivables">
                    <div class="text-muted small">Outstanding <span class="d-none d-xl-inline">Receivables</span></div>
                    <div class="fw-semibold chart-metric-value" data-value-for="outstanding_receivables">₹<?= e(number_format($chartSeries['outstanding_receivables'], 2)) ?></div>
                </button>
            </div>
            <div class="col-6 col-lg">
                <button type="button" class="btn chart-metric-card w-100 h-100 text-center" data-metric="gst">
                    <div class="text-muted small">GST</div>
                    <div class="fw-semibold chart-metric-value" data-value-for="gst">₹<?= e(number_format($chartGstTotal, 2)) ?></div>
                </button>
            </div>
        </div>
        <div style="position:relative; height: 320px;">
            <canvas id="accountsFinancialChart" aria-label="Financial overview chart" role="img"></canvas>
            <div id="chartNoData" class="d-none position-absolute top-50 start-50 translate-middle text-center text-muted">
                <i class="bi bi-bar-chart fs-1 d-block mb-2"></i>
                No Data Available
            </div>
            <div id="chartLoading" class="d-none position-absolute top-50 start-50 translate-middle text-center text-muted">
                <div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>
                Loading…
            </div>
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

    var METRIC_META = {
        income: { label: 'Income', type: 'bar', color: '#198754' },
        expenses: { label: 'Expenses', type: 'bar', color: '#dc3545' },
        net: { label: 'Net', type: 'line', color: '#0d6efd' },
        gst: { label: 'GST', type: 'line', color: '#fd7e14' },
        outstanding_receivables: { label: 'Outstanding Receivables (current)', type: 'line', color: '#6f42c1' }
    };

    var chart = null;
    var activeMetric = 'all';
    var noDataEl = document.getElementById('chartNoData');
    var loadingEl = document.getElementById('chartLoading');
    var metricCards = document.querySelectorAll('.chart-metric-card');

    function isDarkTheme() {
        return document.documentElement.getAttribute('data-bs-theme') === 'dark';
    }

    function themeTextColor() {
        return isDarkTheme() ? '#cbd5e1' : '#495057';
    }

    function themeGridColor() {
        return isDarkTheme() ? 'rgba(148, 163, 184, 0.15)' : 'rgba(0, 0, 0, 0.08)';
    }

    function currency(v) {
        return '₹' + Number(v).toLocaleString('en-IN', { maximumFractionDigits: 0 });
    }

    function buildDatasets(series, metric) {
        var keys = metric === 'all' ? Object.keys(METRIC_META) : [metric];
        return keys.map(function (key) {
            var meta = METRIC_META[key];
            var data = key === 'outstanding_receivables'
                ? series.labels.map(function () { return series.outstanding_receivables; })
                : series[key];
            return {
                type: meta.type,
                label: meta.label,
                data: data,
                backgroundColor: meta.color,
                borderColor: meta.color,
                tension: 0.3,
                borderDash: key === 'gst' ? [4, 3] : (key === 'outstanding_receivables' ? [2, 2] : undefined),
                pointRadius: key === 'outstanding_receivables' ? 0 : undefined,
                fill: false
            };
        });
    }

    function seriesHasData(series, metric) {
        if (!series.labels || series.labels.length === 0) {
            return false;
        }
        if (metric === 'outstanding_receivables') {
            return Number(series.outstanding_receivables) > 0;
        }
        if (metric === 'all') {
            return ['income', 'expenses', 'net', 'gst'].some(function (key) {
                return (series[key] || []).some(function (v) { return Number(v) !== 0; });
            }) || Number(series.outstanding_receivables) > 0;
        }
        return (series[metric] || []).some(function (v) { return Number(v) !== 0; });
    }

    function renderChart(series, metric) {
        var hasData = seriesHasData(series, metric);
        noDataEl.classList.toggle('d-none', hasData);
        canvas.classList.toggle('d-none', !hasData);
        if (chart) {
            chart.destroy();
            chart = null;
        }
        if (!hasData) {
            return;
        }
        chart = new Chart(canvas.getContext('2d'), {
            data: {
                labels: series.labels,
                datasets: buildDatasets(series, metric)
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { color: themeTextColor() } }
                },
                scales: {
                    x: { ticks: { color: themeTextColor() }, grid: { color: themeGridColor() } },
                    y: { ticks: { color: themeTextColor(), callback: currency }, grid: { color: themeGridColor() } }
                }
            }
        });
    }

    function updateMetricCardValues(series) {
        document.querySelectorAll('.chart-metric-value').forEach(function (el) {
            var key = el.getAttribute('data-value-for');
            if (key === 'outstanding_receivables') {
                el.textContent = currency(series.outstanding_receivables);
                return;
            }
            var total = (series[key] || []).reduce(function (a, b) { return a + Number(b); }, 0);
            el.textContent = (total < 0 ? '−' : '') + currency(Math.abs(total));
        });
    }

    function setActiveCard(metric) {
        activeMetric = metric;
        metricCards.forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-metric') === metric);
        });
    }

    // Series currently on screen — metric-card clicks re-render this
    // client-side (instant, no request); only a period change re-fetches.
    var lastSeries = <?= json_encode($chartSeries) ?>;

    function fetchAndRenderChart() {
        var period = document.getElementById('chartPeriodSelect').value;
        var params = new URLSearchParams({ chart_period: period });
        if (period === 'custom') {
            params.set('chart_start', document.getElementById('chartStartInput').value);
            params.set('chart_end', document.getElementById('chartEndInput').value);
        }
        loadingEl.classList.remove('d-none');
        noDataEl.classList.add('d-none');
        fetch('<?= e(url('api/accounts-chart-data.php')) ?>?' + params.toString())
            .then(function (res) { return res.json(); })
            .then(function (json) {
                loadingEl.classList.add('d-none');
                if (!json.success) {
                    noDataEl.classList.remove('d-none');
                    return;
                }
                lastSeries = json.series;
                updateMetricCardValues(lastSeries);
                renderChart(lastSeries, activeMetric);
                // Keep the URL in sync so refreshing the page (or sharing
                // the link) preserves the chosen period.
                var url = new URL(window.location.href);
                url.searchParams.set('chart_period', period);
                if (period === 'custom') {
                    url.searchParams.set('chart_start', params.get('chart_start'));
                    url.searchParams.set('chart_end', params.get('chart_end'));
                } else {
                    url.searchParams.delete('chart_start');
                    url.searchParams.delete('chart_end');
                }
                window.history.replaceState({}, '', url.toString());
            })
            .catch(function () {
                loadingEl.classList.add('d-none');
                noDataEl.classList.remove('d-none');
            });
    }

    // Metric card clicks — purely a client-side re-render of the already-
    // fetched series, so switching metrics is instant.
    metricCards.forEach(function (btn) {
        btn.addEventListener('click', function () {
            setActiveCard(btn.getAttribute('data-metric'));
            renderChart(lastSeries, activeMetric);
        });
    });

    // Period dropdown — This Week/Month/etc. apply immediately; Custom
    // Period reveals the date fields instead of submitting right away.
    document.getElementById('chartPeriodSelect').addEventListener('change', function () {
        var isCustom = this.value === 'custom';
        document.getElementById('customPeriodFields').classList.toggle('d-none', !isCustom);
        if (!isCustom) {
            fetchAndRenderChart();
        }
    });

    document.getElementById('chartPeriodForm').addEventListener('submit', function (e) {
        e.preventDefault();
        fetchAndRenderChart();
    });

    // Re-render on theme change too, purely to repaint the chart's own
    // grid/legend/tick colors — the data itself doesn't change.
    document.addEventListener('crmerp:theme-changed', function () {
        renderChart(lastSeries, activeMetric);
    });

    updateMetricCardValues(lastSeries);
    renderChart(lastSeries, activeMetric);
})();
</script>

<style>
/* Metric cards double as filter controls (see the script above) — give
   them a clear, non-decorative active state so the selected metric is
   obvious at a glance. */
.chart-metric-card {
    border: 1px solid var(--bs-border-color);
    border-radius: 0.5rem;
    background-color: var(--bs-body-bg);
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.chart-metric-card:hover {
    border-color: #86b7fe;
}
.chart-metric-card.active {
    border-color: #0d6efd;
    background-color: rgba(13, 110, 253, 0.08);
    box-shadow: inset 0 0 0 1px #0d6efd;
}
[data-bs-theme="dark"] .chart-metric-card.active {
    background-color: rgba(13, 110, 253, 0.18);
}
#chartLoading, #chartNoData {
    pointer-events: none;
}
</style>

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
