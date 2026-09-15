<?php

declare(strict_types=1);

/**
 * includes/accounts.php
 * Accounts business logic: expense CRUD, plus a combined ledger that
 * merges expenses with invoice_payments (the income side, owned by
 * the Billing module) so there's a single financial view without a
 * second income table to keep in sync.
 */

function create_expense(array $data, int $createdBy): int
{
    $data['uuid'] = generate_uuid_v4();
    $data['created_by'] = $createdBy;
    $data['created_at'] = date('Y-m-d H:i:s');
    $data['updated_at'] = date('Y-m-d H:i:s');

    $columns = array_keys($data);
    $placeholders = array_map(static fn ($c) => ':' . $c, $columns);
    $sql = sprintf('INSERT INTO expenses (%s) VALUES (%s)', implode(', ', $columns), implode(', ', $placeholders));

    $stmt = db()->prepare($sql);
    $stmt->execute($data);

    return (int) db()->lastInsertId();
}

function update_expense(int $id, array $data): bool
{
    $data['updated_at'] = date('Y-m-d H:i:s');
    $assignments = implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", array_keys($data)));
    $stmt = db()->prepare("UPDATE expenses SET {$assignments} WHERE id = :__id");
    $data['__id'] = $id;
    return $stmt->execute($data);
}

function find_expense(int $id): array|false
{
    $stmt = db()->prepare(
        'SELECT expenses.*, projects.title AS project_title
         FROM expenses
         LEFT JOIN projects ON projects.id = expenses.project_id
         WHERE expenses.id = :id AND expenses.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}

function soft_delete_expense(int $id): bool
{
    $stmt = db()->prepare('UPDATE expenses SET deleted_at = :now WHERE id = :id');
    return $stmt->execute(['now' => date('Y-m-d H:i:s'), 'id' => $id]);
}

/**
 * @param array{category?:string,start_date?:string,end_date?:string,search?:string} $filters
 * @return array{sql_where:string, params:array}
 */
function build_expense_filters(array $filters): array
{
    $where = ['expenses.deleted_at IS NULL'];
    $params = [];

    if (!empty($filters['category'])) {
        $where[] = 'expenses.category = :category';
        $params['category'] = $filters['category'];
    }
    if (!empty($filters['start_date'])) {
        $where[] = 'expenses.expense_date >= :start_date';
        $params['start_date'] = $filters['start_date'];
    }
    if (!empty($filters['end_date'])) {
        $where[] = 'expenses.expense_date <= :end_date';
        $params['end_date'] = $filters['end_date'];
    }
    if (!empty($filters['search'])) {
        $where[] = '(expenses.description LIKE :search1 OR expenses.vendor LIKE :search2)';
        $searchTerm = '%' . $filters['search'] . '%';
        $params['search1'] = $searchTerm;
        $params['search2'] = $searchTerm;
    }

    return ['sql_where' => implode(' AND ', $where), 'params' => $params];
}

function count_expenses(array $filters): int
{
    ['sql_where' => $where, 'params' => $params] = build_expense_filters($filters);
    $stmt = db()->prepare("SELECT COUNT(*) AS cnt FROM expenses WHERE {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetch()['cnt'];
}

function list_expenses(array $filters, int $limit, int $offset): array
{
    ['sql_where' => $where, 'params' => $params] = build_expense_filters($filters);
    $stmt = db()->prepare(
        "SELECT expenses.*, projects.title AS project_title
         FROM expenses
         LEFT JOIN projects ON projects.id = expenses.project_id
         WHERE {$where}
         ORDER BY expenses.expense_date DESC, expenses.id DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function list_expense_categories(): array
{
    $stmt = db()->query("SELECT DISTINCT category FROM expenses WHERE deleted_at IS NULL ORDER BY category ASC");
    $existing = array_column($stmt->fetchAll(), 'category');
    $defaults = ['Rent', 'Salaries', 'Software & Subscriptions', 'Utilities', 'Travel', 'Marketing', 'Office Supplies', 'Professional Fees', 'Other'];
    return array_values(array_unique(array_merge($defaults, $existing)));
}

// ---------------------------------------------------------------------
// Combined income/expense ledger
// ---------------------------------------------------------------------

/**
 * Merges invoice_payments (income), project_income, and expenses within
 * a date range into one chronological ledger. Each entry carries the
 * GST invoice it belongs to (if any) as invoice_id/invoice_number, so
 * the Ledger table can link straight to the bill instead of showing a
 * free-text reference. project_income rows also carry their own
 * receipt_number (project_income.invoice_number — see
 * includes/project_payments.php: next_project_income_number()), shown
 * as plain text since it has no linked bill page. Sorted by
 * payment/expense date, and — since several transactions commonly
 * share the same date — by created_at as a tiebreaker so same-day rows
 * land in a stable, most-recent-first order instead of whichever order
 * the three source tables happened to be merged in.
 *
 * @return array<int, array{source_id:int,source:string,date:string,type:string,description:string,invoice_id:?int,invoice_number:?string,receipt_number:?string,amount:float,created_at:string}>
 */
function get_ledger_entries(string $startDate, string $endDate): array
{
    $incomeStmt = db()->prepare(
        "SELECT ip.id AS source_id, 'invoice_payment' AS source, ip.payment_date AS date,
                CASE WHEN ip.amount < 0 THEN 'correction' ELSE 'income' END AS type,
                CASE WHEN ip.amount < 0
                     THEN CONCAT('Correction — ', c.company_name, ' (', i.invoice_number, ')')
                     ELSE CONCAT('Payment — ', c.company_name, ' (', i.invoice_number, ')')
                END AS description,
                i.id AS invoice_id, i.invoice_number AS invoice_number, NULL AS receipt_number, ip.amount AS amount, ip.created_at AS created_at
         FROM invoice_payments ip
         JOIN invoices i ON i.id = ip.invoice_id
         LEFT JOIN clients c ON c.id = i.client_id
         WHERE i.deleted_at IS NULL AND ip.payment_date BETWEEN :start1 AND :end1"
    );
    $incomeStmt->execute(['start1' => $startDate, 'end1' => $endDate]);
    $income = $incomeStmt->fetchAll();

    // A project's GST invoice (if any) is auto-drafted when it's marked
    // Completed — same "first by id" pick as find_invoice_by_project().
    $projectIncomeStmt = db()->prepare(
        "SELECT pi.id AS source_id, 'project_income' AS source, pi.payment_date AS date, 'income' AS type,
                CONCAT('Payment — ', projects.title, CASE WHEN clients.company_name IS NOT NULL THEN CONCAT(' (', clients.company_name, ')') ELSE '' END) AS description,
                inv.id AS invoice_id, inv.invoice_number AS invoice_number, pi.invoice_number AS receipt_number, pi.amount AS amount, pi.created_at AS created_at
         FROM project_income pi
         JOIN projects ON projects.id = pi.project_id
         LEFT JOIN clients ON clients.id = projects.client_id
         LEFT JOIN invoices inv ON inv.id = (
             SELECT id FROM invoices WHERE project_id = pi.project_id AND deleted_at IS NULL ORDER BY id LIMIT 1
         )
         WHERE pi.deleted_at IS NULL AND pi.invoice_payment_id IS NULL AND pi.payment_date BETWEEN :start3 AND :end3"
    );
    $projectIncomeStmt->execute(['start3' => $startDate, 'end3' => $endDate]);
    $projectIncome = $projectIncomeStmt->fetchAll();

    $expenseStmt = db()->prepare(
        "SELECT id AS source_id, 'expense' AS source, expense_date AS date, 'expense' AS type,
                CONCAT(category, CASE WHEN description IS NOT NULL AND description != '' THEN CONCAT(' — ', description) ELSE '' END) AS description,
                NULL AS invoice_id, NULL AS invoice_number, NULL AS receipt_number, amount AS amount, created_at AS created_at
         FROM expenses
         WHERE deleted_at IS NULL AND expense_date BETWEEN :start2 AND :end2"
    );
    $expenseStmt->execute(['start2' => $startDate, 'end2' => $endDate]);
    $expense = $expenseStmt->fetchAll();

    $entries = array_merge($income, $projectIncome, $expense);
    usort($entries, static function ($a, $b) {
        $dateCmp = strcmp($b['date'], $a['date']);
        return $dateCmp !== 0 ? $dateCmp : strcmp((string) $b['created_at'], (string) $a['created_at']);
    });

    return $entries;
}

function total_income(string $startDate, string $endDate): float
{
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(ip.amount), 0) AS total FROM invoice_payments ip
         JOIN invoices i ON i.id = ip.invoice_id
         WHERE i.deleted_at IS NULL AND ip.payment_date BETWEEN :start AND :end'
    );
    $stmt->execute(['start' => $startDate, 'end' => $endDate]);
    $invoiceIncome = (float) $stmt->fetch()['total'];

    $projectStmt = db()->prepare(
        'SELECT COALESCE(SUM(amount), 0) AS total FROM project_income WHERE deleted_at IS NULL AND invoice_payment_id IS NULL AND payment_date BETWEEN :start AND :end'
    );
    $projectStmt->execute(['start' => $startDate, 'end' => $endDate]);
    $projectIncome = (float) $projectStmt->fetch()['total'];

    return $invoiceIncome + $projectIncome;
}

function total_expenses(string $startDate, string $endDate): float
{
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(amount), 0) AS total FROM expenses WHERE deleted_at IS NULL AND expense_date BETWEEN :start AND :end'
    );
    $stmt->execute(['start' => $startDate, 'end' => $endDate]);
    return (float) $stmt->fetch()['total'];
}

/**
 * Expense totals grouped by category within a date range — the
 * category-wise breakdown table on the Accounts > GST Report export.
 *
 * @return array<int, array{category:string,total:float}>
 */
function expenses_by_category(string $startDate, string $endDate): array
{
    $stmt = db()->prepare(
        "SELECT category, COALESCE(SUM(amount), 0) AS total
         FROM expenses
         WHERE deleted_at IS NULL AND expense_date BETWEEN :start AND :end
         GROUP BY category
         ORDER BY total DESC"
    );
    $stmt->execute(['start' => $startDate, 'end' => $endDate]);
    return array_map(
        static fn ($row) => ['category' => $row['category'], 'total' => (float) $row['total']],
        $stmt->fetchAll()
    );
}

/**
 * Outstanding receivables — total of unpaid/partially-paid invoice
 * balances. Includes 'draft' invoices too (e.g. the one auto-created
 * when a project is marked Completed — auto_generate_invoice_for_completed_project(),
 * includes/billing.php): the money is owed to the business the moment
 * the project's done, whether or not the invoice has formally been
 * marked "sent" to the client yet. 'cancelled'/'completed' invoices
 * are excluded since those balances are no longer collectible/pending.
 */
function total_outstanding_receivables(): float
{
    $stmt = db()->query(
        "SELECT COALESCE(SUM(total_amount - amount_paid), 0) AS total
         FROM invoices
         WHERE deleted_at IS NULL AND status IN ('draft','sent','partially_paid','overdue')"
    );
    return (float) $stmt->fetch()['total'];
}

// ---------------------------------------------------------------------
// Accounts dashboard — financial graph (Income/Expenses/Net/Outstanding
// Receivables/GST) with a period picker, see accounts/index.php.
// ---------------------------------------------------------------------

/** The period options offered on the Accounts dashboard's graph filter. */
function accounts_chart_period_options(): array
{
    return [
        'this_week' => 'This Week',
        'this_month' => 'This Month',
        'previous_month' => 'Previous Month',
        'last_3_months' => 'Last 3 Months',
        'last_6_months' => 'Last 6 Months',
        'custom' => 'Custom Period',
    ];
}

/**
 * Resolves a period key (see accounts_chart_period_options()) — plus
 * optional custom start/end when $period is 'custom' — into concrete
 * start/end dates (Y-m-d).
 */
function accounts_chart_period_bounds(string $period, ?string $customStart = null, ?string $customEnd = null): array
{
    $today = date('Y-m-d');

    return match ($period) {
        'this_week' => ['start' => date('Y-m-d', strtotime('monday this week')), 'end' => $today],
        'previous_month' => ['start' => date('Y-m-01', strtotime('first day of last month')), 'end' => date('Y-m-t', strtotime('last day of last month'))],
        'last_3_months' => ['start' => date('Y-m-d', strtotime('-3 months')), 'end' => $today],
        'last_6_months' => ['start' => date('Y-m-d', strtotime('-6 months')), 'end' => $today],
        'custom' => [
            'start' => $customStart && $customStart !== '' ? $customStart : date('Y-m-01'),
            'end' => $customEnd && $customEnd !== '' ? $customEnd : $today,
        ],
        default => ['start' => date('Y-m-01'), 'end' => $today], // this_month
    };
}

/**
 * Daily Income/Expenses/Net/GST series for the selected period, for the
 * Accounts dashboard's Chart.js graph. Buckets by day when the period is
 * 62 days or shorter, otherwise by month, so a 6-month view stays
 * readable instead of plotting 180 individual points. Outstanding
 * Receivables isn't a per-day flow (it's a running balance of unpaid
 * invoices), so it's returned as a single current snapshot alongside
 * the series rather than one point per bucket.
 *
 * @return array{labels: array<int,string>, income: array<int,float>, expenses: array<int,float>, net: array<int,float>, gst: array<int,float>, outstanding_receivables: float}
 */
function accounts_chart_series(string $startDate, string $endDate): array
{
    $days = (strtotime($endDate) - strtotime($startDate)) / 86400;
    $monthly = $days > 62;
    $dateFormat = $monthly ? '%Y-%m' : '%Y-%m-%d';
    $labelFormat = $monthly ? 'M Y' : 'd M';

    $incomeStmt = db()->prepare(
        "SELECT DATE_FORMAT(ip.payment_date, :fmt) AS bucket, COALESCE(SUM(ip.amount), 0) AS total
         FROM invoice_payments ip
         JOIN invoices i ON i.id = ip.invoice_id
         WHERE i.deleted_at IS NULL AND ip.payment_date BETWEEN :start AND :end
         GROUP BY bucket"
    );
    $incomeStmt->execute(['fmt' => $dateFormat, 'start' => $startDate, 'end' => $endDate]);
    $incomeByBucket = array_column($incomeStmt->fetchAll(), 'total', 'bucket');

    $projIncomeStmt = db()->prepare(
        "SELECT DATE_FORMAT(payment_date, :fmt) AS bucket, COALESCE(SUM(amount), 0) AS total
         FROM project_income
         WHERE deleted_at IS NULL AND invoice_payment_id IS NULL AND payment_date BETWEEN :start AND :end
         GROUP BY bucket"
    );
    $projIncomeStmt->execute(['fmt' => $dateFormat, 'start' => $startDate, 'end' => $endDate]);
    foreach ($projIncomeStmt->fetchAll() as $row) {
        $incomeByBucket[$row['bucket']] = (float) ($incomeByBucket[$row['bucket']] ?? 0) + (float) $row['total'];
    }

    $expenseStmt = db()->prepare(
        "SELECT DATE_FORMAT(expense_date, :fmt) AS bucket, COALESCE(SUM(amount), 0) AS total
         FROM expenses
         WHERE deleted_at IS NULL AND expense_date BETWEEN :start AND :end
         GROUP BY bucket"
    );
    $expenseStmt->execute(['fmt' => $dateFormat, 'start' => $startDate, 'end' => $endDate]);
    $expensesByBucket = array_column($expenseStmt->fetchAll(), 'total', 'bucket');

    $gstStmt = db()->prepare(
        "SELECT DATE_FORMAT(invoice_date, :fmt) AS bucket,
                COALESCE(SUM(cgst_amount + sgst_amount + igst_amount), 0) AS total
         FROM invoices
         WHERE deleted_at IS NULL AND status NOT IN ('draft', 'cancelled') AND invoice_date BETWEEN :start AND :end
         GROUP BY bucket"
    );
    $gstStmt->execute(['fmt' => $dateFormat, 'start' => $startDate, 'end' => $endDate]);
    $gstByBucket = array_column($gstStmt->fetchAll(), 'total', 'bucket');

    // Build the full, contiguous list of buckets so gaps (days/months
    // with no transactions) show as zero instead of being skipped.
    $buckets = [];
    $cursor = strtotime($startDate);
    $endTs = strtotime($endDate);
    while ($cursor <= $endTs) {
        $key = date($monthly ? 'Y-m' : 'Y-m-d', $cursor);
        $buckets[$key] = date($labelFormat, $cursor);
        $cursor = strtotime($monthly ? '+1 month' : '+1 day', $cursor);
    }

    $labels = [];
    $income = [];
    $expensesSeries = [];
    $net = [];
    $gst = [];
    foreach ($buckets as $key => $label) {
        $labels[] = $label;
        $inc = (float) ($incomeByBucket[$key] ?? 0);
        $exp = (float) ($expensesByBucket[$key] ?? 0);
        $income[] = $inc;
        $expensesSeries[] = $exp;
        $net[] = $inc - $exp;
        $gst[] = (float) ($gstByBucket[$key] ?? 0);
    }

    return [
        'labels' => $labels,
        'income' => $income,
        'expenses' => $expensesSeries,
        'net' => $net,
        'gst' => $gst,
        'outstanding_receivables' => total_outstanding_receivables(),
    ];
}

// ---------------------------------------------------------------------
// Recurring / Auto Expenses
// ---------------------------------------------------------------------
// A recurring_expenses row is only a template (category, default
// amount, day of month, etc). Nothing is posted to `expenses`
// automatically — scan_recurring_expenses_due() (called once per
// request from dashboard.php, same pattern as scan_followups_due_today())
// raises one notification per rule per calendar month once its
// day_of_month has arrived, and a real expense row is only created
// once a user confirms it on accounts/auto_expense_confirm.php. The
// recurring_expense_runs table is what makes the "once a month" dedupe
// and the confirm/skip audit trail work.

function create_recurring_expense(array $data, int $createdBy): int
{
    $data['uuid'] = generate_uuid_v4();
    $data['created_by'] = $createdBy;
    $data['created_at'] = date('Y-m-d H:i:s');
    $data['updated_at'] = date('Y-m-d H:i:s');

    $columns = array_keys($data);
    $placeholders = array_map(static fn ($c) => ':' . $c, $columns);
    $sql = sprintf('INSERT INTO recurring_expenses (%s) VALUES (%s)', implode(', ', $columns), implode(', ', $placeholders));

    $stmt = db()->prepare($sql);
    $stmt->execute($data);

    return (int) db()->lastInsertId();
}

function update_recurring_expense(int $id, array $data): bool
{
    $data['updated_at'] = date('Y-m-d H:i:s');
    $assignments = implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", array_keys($data)));
    $stmt = db()->prepare("UPDATE recurring_expenses SET {$assignments} WHERE id = :__id");
    $data['__id'] = $id;
    return $stmt->execute($data);
}

function find_recurring_expense(int $id): array|false
{
    $stmt = db()->prepare(
        'SELECT recurring_expenses.*, projects.title AS project_title
         FROM recurring_expenses
         LEFT JOIN projects ON projects.id = recurring_expenses.project_id
         WHERE recurring_expenses.id = :id AND recurring_expenses.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}

function list_recurring_expenses(): array
{
    $stmt = db()->query(
        "SELECT recurring_expenses.*, projects.title AS project_title
         FROM recurring_expenses
         LEFT JOIN projects ON projects.id = recurring_expenses.project_id
         WHERE recurring_expenses.deleted_at IS NULL
         ORDER BY recurring_expenses.is_active DESC, recurring_expenses.day_of_month ASC"
    );
    return $stmt->fetchAll();
}

function set_recurring_expense_active(int $id, bool $active): bool
{
    $stmt = db()->prepare('UPDATE recurring_expenses SET is_active = :active, updated_at = :now WHERE id = :id');
    return $stmt->execute(['active' => $active ? 1 : 0, 'now' => date('Y-m-d H:i:s'), 'id' => $id]);
}

function soft_delete_recurring_expense(int $id): bool
{
    $stmt = db()->prepare('UPDATE recurring_expenses SET deleted_at = :now WHERE id = :id');
    return $stmt->execute(['now' => date('Y-m-d H:i:s'), 'id' => $id]);
}

/** The current calendar-month key used to dedupe runs, e.g. '2026-08'. */
function current_month_key(): string
{
    return date('Y-m');
}

function find_recurring_expense_run(int $ruleId, string $monthKey): array|false
{
    $stmt = db()->prepare(
        'SELECT * FROM recurring_expense_runs WHERE recurring_expense_id = :rid AND month_key = :mk LIMIT 1'
    );
    $stmt->execute(['rid' => $ruleId, 'mk' => $monthKey]);
    return $stmt->fetch();
}

/**
 * Runs once per request (call from dashboard.php, like the other
 * scanners). For every active rule whose day_of_month has arrived this
 * month and that hasn't been notified about yet this month, creates a
 * recurring_expense_runs row (status='pending') and a notification
 * asking the admins to confirm whether to post the expense.
 */
function scan_recurring_expenses_due(): void
{
    $today = (int) date('j');
    $monthKey = current_month_key();

    $stmt = db()->query(
        'SELECT * FROM recurring_expenses WHERE deleted_at IS NULL AND is_active = 1 AND day_of_month <= ' . $today
    );

    foreach ($stmt->fetchAll() as $rule) {
        $ruleId = (int) $rule['id'];

        if (find_recurring_expense_run($ruleId, $monthKey) !== false) {
            continue; // already notified (or resolved) for this rule this month
        }

        $runStmt = db()->prepare(
            'INSERT INTO recurring_expense_runs (recurring_expense_id, month_key, status, notified_at)
             VALUES (:rid, :mk, :status, :now)'
        );
        $runStmt->execute(['rid' => $ruleId, 'mk' => $monthKey, 'status' => 'pending', 'now' => date('Y-m-d H:i:s')]);

        notify_admins(
            'auto_expense',
            "Monthly expense due: {$rule['category']} (₹" . number_format((float) $rule['amount'], 2) . ')',
            'Confirm to add this to Expenses, or skip it for this month.',
            'accounts/auto_expense_confirm.php?rule_id=' . $ruleId . '&month=' . $monthKey
        );
    }
}

/** Confirms a pending run: posts a real expense using the given (possibly edited) data and links it back to the run. */
function confirm_recurring_expense_run(int $runId, array $expenseData, int $userId): int
{
    $expenseId = create_expense($expenseData, $userId);

    $stmt = db()->prepare(
        "UPDATE recurring_expense_runs
         SET status = 'added', expense_id = :expense_id, resolved_at = :now, resolved_by = :uid
         WHERE id = :id"
    );
    $stmt->execute(['expense_id' => $expenseId, 'now' => date('Y-m-d H:i:s'), 'uid' => $userId, 'id' => $runId]);

    return $expenseId;
}

/** Skips a pending run for this month — the rule will be picked up again next month. */
function skip_recurring_expense_run(int $runId, int $userId): bool
{
    $stmt = db()->prepare(
        "UPDATE recurring_expense_runs
         SET status = 'skipped', resolved_at = :now, resolved_by = :uid
         WHERE id = :id"
    );
    return $stmt->execute(['now' => date('Y-m-d H:i:s'), 'uid' => $userId, 'id' => $runId]);
}
