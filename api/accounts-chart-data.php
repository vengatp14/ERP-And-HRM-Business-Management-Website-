<?php

declare(strict_types=1);

/**
 * api/accounts-chart-data.php
 * JSON endpoint backing the Accounts > Financial Overview graph
 * (accounts/index.php). The period dropdown (This Week / This Month /
 * Previous Month / Last 3 Months / Last 6 Months / Custom Period) and
 * the metric cards (Income / Expenses / Net / Outstanding Receivables
 * / GST) both read from here so switching either one updates the
 * chart immediately, without a full page reload.
 *
 * GET chart_period, chart_start, chart_end (only used when
 * chart_period=custom)
 * -> {"success": true, "period_label": "...", "series": {...}}
 *
 * Reuses accounts_chart_period_bounds()/accounts_chart_series() from
 * includes/accounts.php — the exact same functions accounts/index.php
 * itself uses for the initial server-rendered chart — so the AJAX
 * update and the first paint can never disagree.
 */

ob_start();

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('accounts');

header('Content-Type: application/json; charset=utf-8');

function accounts_chart_json_response(int $httpCode, array $body): never
{
    ob_end_clean();
    http_response_code($httpCode);
    echo json_encode($body);
    exit;
}

try {
    $periodOptions = accounts_chart_period_options();
    $period = $_GET['chart_period'] ?? 'this_month';
    if (!array_key_exists($period, $periodOptions)) {
        $period = 'this_month';
    }

    ['start' => $start, 'end' => $end] = accounts_chart_period_bounds(
        $period,
        $_GET['chart_start'] ?? null,
        $_GET['chart_end'] ?? null
    );

    $series = accounts_chart_series($start, $end);

    accounts_chart_json_response(200, [
        'success' => true,
        'period' => $period,
        'period_label' => $periodOptions[$period],
        'start' => $start,
        'end' => $end,
        'series' => $series,
    ]);
} catch (\Throwable $e) {
    error_log('[api/accounts-chart-data.php] ' . $e->getMessage());
    accounts_chart_json_response(500, ['success' => false, 'error' => 'Could not load chart data.']);
}
