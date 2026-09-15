<?php

declare(strict_types=1);

/**
 * includes/reports.php
 * Reporting/analytics business logic. Pure read-side aggregation over
 * leads, projects, clients, invoices, and expenses — no new tables of
 * its own.
 */

/** @return array<string,int> status => count */
function leads_by_status(): array
{
    $stmt = db()->query("SELECT status, COUNT(*) AS cnt FROM leads WHERE deleted_at IS NULL GROUP BY status");
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[$row['status']] = (int) $row['cnt'];
    }
    return $result;
}

/** Won leads as a percentage of all leads that reached a final state (won/lost/cancelled). */
function leads_conversion_rate(): float
{
    $stmt = db()->query(
        "SELECT
            SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) AS won,
            SUM(CASE WHEN status IN ('won','lost','cancelled') THEN 1 ELSE 0 END) AS decided
         FROM leads WHERE deleted_at IS NULL"
    );
    $row = $stmt->fetch();
    $decided = (int) $row['decided'];
    return $decided > 0 ? round(((int) $row['won'] / $decided) * 100, 1) : 0.0;
}

/**
 * Leads created per month for the last $months months (oldest first).
 * @return array<int, array{month:string,count:int}>
 */
function leads_by_month(int $months = 6): array
{
    $stmt = db()->prepare(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt
         FROM leads
         WHERE deleted_at IS NULL AND created_at >= :since
         GROUP BY ym ORDER BY ym ASC"
    );
    $stmt->execute(['since' => date('Y-m-01', strtotime("-" . ($months - 1) . " months"))]);
    return fill_month_series($stmt->fetchAll(), $months);
}

/**
 * Income (from invoice_payments) and expenses per month for the last
 * $months months (oldest first).
 * @return array<int, array{month:string,income:float,expenses:float}>
 */
function revenue_vs_expense_by_month(int $months = 6): array
{
    $since = date('Y-m-01', strtotime("-" . ($months - 1) . " months"));

    $incomeStmt = db()->prepare(
        "SELECT DATE_FORMAT(ip.payment_date, '%Y-%m') AS ym, SUM(ip.amount) AS total
         FROM invoice_payments ip
         JOIN invoices i ON i.id = ip.invoice_id
         WHERE i.deleted_at IS NULL AND ip.payment_date >= :since GROUP BY ym"
    );
    $incomeStmt->execute(['since' => $since]);
    $incomeByMonth = array_column($incomeStmt->fetchAll(), 'total', 'ym');

    $expenseStmt = db()->prepare(
        "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS ym, SUM(amount) AS total
         FROM expenses WHERE deleted_at IS NULL AND expense_date >= :since GROUP BY ym"
    );
    $expenseStmt->execute(['since' => $since]);
    $expenseByMonth = array_column($expenseStmt->fetchAll(), 'total', 'ym');

    $series = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("-{$i} months"));
        $series[] = [
            'month' => date('M Y', strtotime($ym . '-01')),
            'income' => (float) ($incomeByMonth[$ym] ?? 0),
            'expenses' => (float) ($expenseByMonth[$ym] ?? 0),
        ];
    }
    return $series;
}

/** @return array<string,int> status => count */
function projects_by_status(): array
{
    $stmt = db()->query("SELECT status, COUNT(*) AS cnt FROM projects WHERE deleted_at IS NULL GROUP BY status");
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[$row['status']] = (int) $row['cnt'];
    }
    return $result;
}

/**
 * Impact of cancelled ("rejected") projects on income: how many
 * cancelled projects have invoices against them, and the total
 * invoiced amount tied to those projects — i.e. revenue that would
 * have been recognized had the project not fallen through.
 * @return array{project_count:int,income_amount:float}
 */
function cancelled_projects_income_impact(): array
{
    $stmt = db()->query(
        "SELECT COUNT(DISTINCT p.id) AS project_count, COALESCE(SUM(i.total_amount), 0) AS income_amount
         FROM projects p
         JOIN invoices i ON i.project_id = p.id AND i.deleted_at IS NULL
         WHERE p.status = 'cancelled' AND p.deleted_at IS NULL"
    );
    $row = $stmt->fetch();
    return [
        'project_count' => (int) $row['project_count'],
        'income_amount' => (float) $row['income_amount'],
    ];
}

/**
 * Clients ranked by total invoiced revenue (billed, not just collected).
 * @return array<int, array{company_name:string,total_billed:float,total_paid:float}>
 */
function top_clients_by_revenue(int $limit = 5): array
{
    $stmt = db()->prepare(
        "SELECT c.company_name, SUM(i.total_amount) AS total_billed, SUM(i.amount_paid) AS total_paid
         FROM invoices i
         JOIN clients c ON c.id = i.client_id
         WHERE i.deleted_at IS NULL
         GROUP BY c.id, c.company_name
         ORDER BY total_billed DESC
         LIMIT :lim"
    );
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Fills in zero-count months so a chart series never has gaps for months with no activity. */
function fill_month_series(array $rows, int $months): array
{
    $byMonth = array_column($rows, 'cnt', 'ym');
    $series = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("-{$i} months"));
        $series[] = ['month' => date('M Y', strtotime($ym . '-01')), 'count' => (int) ($byMonth[$ym] ?? 0)];
    }
    return $series;
}
