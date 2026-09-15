<?php

declare(strict_types=1);

/**
 * includes/project_payments.php
 * Project Payment Plans: a project can be set up (from Projects >
 * Add/Edit, or from Accounts > Monthly Payments) with either a
 * one-time expected payment or a recurring monthly payment.
 *
 * Nothing is posted to project_income automatically. Once a due date
 * arrives — scan_project_payments_due(), called once per request like
 * scan_recurring_expenses_due() — a project_payment_plan_runs row is
 * created and every admin gets a notification to confirm the payment
 * (accounts/project_payment_confirm.php). Confirming inserts a
 * project_income row, which Accounts' ledger and income totals read
 * alongside invoice_payments.
 */

// ---------------------------------------------------------------------
// Plan CRUD
// ---------------------------------------------------------------------

function create_project_payment_plan(array $data, int $createdBy): int
{
    $data['uuid'] = generate_uuid_v4();
    $data['created_by'] = $createdBy;
    $data['created_at'] = date('Y-m-d H:i:s');
    $data['updated_at'] = date('Y-m-d H:i:s');

    $columns = array_keys($data);
    $placeholders = array_map(static fn ($c) => ':' . $c, $columns);
    $sql = sprintf('INSERT INTO project_payment_plans (%s) VALUES (%s)', implode(', ', $columns), implode(', ', $placeholders));

    $stmt = db()->prepare($sql);
    $stmt->execute($data);

    return (int) db()->lastInsertId();
}

function update_project_payment_plan(int $id, array $data): bool
{
    $data['updated_at'] = date('Y-m-d H:i:s');
    $assignments = implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", array_keys($data)));
    $stmt = db()->prepare("UPDATE project_payment_plans SET {$assignments} WHERE id = :__id");
    $data['__id'] = $id;
    return $stmt->execute($data);
}

function find_project_payment_plan(int $id): array|false
{
    $stmt = db()->prepare(
        'SELECT project_payment_plans.*, projects.title AS project_title, clients.company_name AS client_name
         FROM project_payment_plans
         JOIN projects ON projects.id = project_payment_plans.project_id
         LEFT JOIN clients ON clients.id = projects.client_id
         WHERE project_payment_plans.id = :id AND project_payment_plans.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}

/** The active (non-deleted) payment plan for a project, if any — a project has at most one. */
function find_project_payment_plan_by_project(int $projectId): array|false
{
    $stmt = db()->prepare(
        'SELECT * FROM project_payment_plans WHERE project_id = :pid AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute(['pid' => $projectId]);
    return $stmt->fetch();
}

function list_project_payment_plans(): array
{
    $stmt = db()->query(
        "SELECT project_payment_plans.*, projects.title AS project_title, clients.company_name AS client_name
         FROM project_payment_plans
         JOIN projects ON projects.id = project_payment_plans.project_id
         LEFT JOIN clients ON clients.id = projects.client_id
         WHERE project_payment_plans.deleted_at IS NULL AND project_payment_plans.payment_type = 'monthly'
         ORDER BY project_payment_plans.is_active DESC, projects.title ASC"
    );
    return $stmt->fetchAll();
}

function set_project_payment_plan_active(int $id, bool $active): bool
{
    $stmt = db()->prepare('UPDATE project_payment_plans SET is_active = :active, updated_at = :now WHERE id = :id');
    return $stmt->execute(['active' => $active ? 1 : 0, 'now' => date('Y-m-d H:i:s'), 'id' => $id]);
}

function soft_delete_project_payment_plan(int $id): bool
{
    $stmt = db()->prepare('UPDATE project_payment_plans SET deleted_at = :now WHERE id = :id');
    return $stmt->execute(['now' => date('Y-m-d H:i:s'), 'id' => $id]);
}

/**
 * Creates/updates/removes a project's payment plan from the Add/Edit
 * Project form fields (projects/form.php). $paymentType is '' when
 * Payment was left as "— None —" (or the user isn't an admin) — in
 * that case any existing plan is soft-deleted so it stops generating
 * reminders.
 */
function save_project_payment_plan_from_form(int $projectId, array|false $existingPlan, string $paymentType, float $amount, int $dayOfMonth, ?string $dueDate, int $userId): void
{
    if (!in_array($paymentType, ['one_time', 'monthly'], true)) {
        if ($existingPlan !== false) {
            soft_delete_project_payment_plan((int) $existingPlan['id']);
        }
        return;
    }

    $planData = [
        'project_id' => $projectId,
        'payment_type' => $paymentType,
        'amount' => $amount,
        'day_of_month' => $paymentType === 'monthly' ? $dayOfMonth : null,
        'due_date' => $paymentType === 'one_time' ? $dueDate : null,
        'is_active' => 1,
    ];

    if ($existingPlan !== false) {
        update_project_payment_plan((int) $existingPlan['id'], $planData);
    } else {
        create_project_payment_plan($planData, $userId);
    }
}

// ---------------------------------------------------------------------
// Receipt numbering (project_income.invoice_number)
// ---------------------------------------------------------------------

/**
 * Atomically reserves the next receipt/invoice number for the Monthly
 * Payments ledger, for the given financial year. Mirrors
 * next_invoice_number() in includes/billing.php but uses its own
 * per-financial-year counter (project_income_number_sequences) and
 * prefix (PROJECT_INCOME_NUMBER_PREFIX) so it never collides with, or
 * gets mistaken for, a formal GST invoice number. Must be called
 * inside a transaction that also inserts the project_income row, so a
 * crash between the two never leaves a burned gap.
 */
function next_project_income_number(string $financialYear): string
{
    $stmt = db()->prepare('SELECT last_number FROM project_income_number_sequences WHERE financial_year = :fy FOR UPDATE');
    $stmt->execute(['fy' => $financialYear]);
    $row = $stmt->fetch();

    if ($row === false) {
        db()->prepare('INSERT INTO project_income_number_sequences (financial_year, last_number) VALUES (:fy, 1)')
            ->execute(['fy' => $financialYear]);
        $next = 1;
    } else {
        $next = (int) $row['last_number'] + 1;
        db()->prepare('UPDATE project_income_number_sequences SET last_number = :next WHERE financial_year = :fy')
            ->execute(['next' => $next, 'fy' => $financialYear]);
    }

    return sprintf('%s-%s-%04d', PROJECT_INCOME_NUMBER_PREFIX, $financialYear, $next);
}

// ---------------------------------------------------------------------
// Due-date scanning (monthly recurrence + one-time)
// ---------------------------------------------------------------------

function find_project_payment_plan_run(int $planId, string $monthKey): array|false
{
    $stmt = db()->prepare(
        'SELECT * FROM project_payment_plan_runs WHERE plan_id = :pid AND month_key = :mk LIMIT 1'
    );
    $stmt->execute(['pid' => $planId, 'mk' => $monthKey]);
    return $stmt->fetch();
}

/**
 * Runs once per request (call from dashboard.php). For every active
 * plan whose due date has arrived — monthly plans use day_of_month
 * against the current month, one_time plans use their stored due_date
 * — and hasn't already got a run this month, creates a
 * project_payment_plan_runs row (status='pending') and notifies the
 * admins to confirm the payment.
 */
function scan_project_payments_due(): void
{
    $today = date('Y-m-d');
    $todayDay = (int) date('j');
    $monthKey = current_month_key();

    $stmt = db()->query(
        "SELECT project_payment_plans.*, projects.title AS project_title
         FROM project_payment_plans
         JOIN projects ON projects.id = project_payment_plans.project_id
         WHERE project_payment_plans.deleted_at IS NULL AND project_payment_plans.is_active = 1"
    );

    foreach ($stmt->fetchAll() as $plan) {
        $planId = (int) $plan['id'];
        $dueDate = null;

        if ($plan['payment_type'] === 'monthly') {
            if ($plan['day_of_month'] === null || (int) $plan['day_of_month'] > $todayDay) {
                continue; // this month's due day hasn't arrived yet
            }
            $dueDate = date('Y-m-') . sprintf('%02d', (int) $plan['day_of_month']);
        } else { // one_time
            if ($plan['due_date'] === null || $plan['due_date'] > $today) {
                continue;
            }
            $dueDate = $plan['due_date'];
        }

        if (find_project_payment_plan_run($planId, $monthKey) !== false) {
            continue; // already notified (or resolved) for this plan this month
        }

        $runStmt = db()->prepare(
            'INSERT INTO project_payment_plan_runs (plan_id, month_key, due_date, status, notified_at)
             VALUES (:pid, :mk, :due, :status, :now)'
        );
        $runStmt->execute(['pid' => $planId, 'mk' => $monthKey, 'due' => $dueDate, 'status' => 'pending', 'now' => date('Y-m-d H:i:s')]);

        notify_admins(
            'project_payment',
            ($plan['payment_type'] === 'monthly' ? 'Monthly payment due: ' : 'Payment due: ') . $plan['project_title'] . ' (₹' . number_format((float) $plan['amount'], 2) . ')',
            'Confirm to add this to Income, or skip it for this month.',
            'accounts/project_payment_confirm.php?plan_id=' . $planId . '&month=' . $monthKey
        );
    }
}

/**
 * Confirms a pending run: records the income and links it back to the
 * run. Also mirrors into invoice_payments when the project has a
 * linked GST invoice — same reasoning as record_project_income(), so
 * a payment confirmed through the automatic monthly/one-time flow
 * shows up as Paid on the project header and the GST Billing page
 * just like one added manually via "Add Payment".
 */
function confirm_project_payment_run(int $runId, int $projectId, ?int $planId, array $incomeData, int $userId): int
{
    $db = db();
    $db->beginTransaction();

    try {
        // Serialize confirmation for this scheduled run. The page checks
        // status before calling this function, but two requests can pass
        // that check unless the database check is repeated under lock.
        $runStmt = $db->prepare('SELECT status, income_id FROM project_payment_plan_runs WHERE id = :id FOR UPDATE');
        $runStmt->execute(['id' => $runId]);
        $lockedRun = $runStmt->fetch();
        if ($lockedRun === false) {
            throw new RuntimeException('Payment run not found.');
        }
        if ($lockedRun['status'] !== 'pending') {
            $db->commit();
            return (int) ($lockedRun['income_id'] ?? 0);
        }

        $invoiceNumber = next_project_income_number(financial_year_for($incomeData['payment_date']));

        $stmt = $db->prepare(
            'INSERT INTO project_income (project_id, plan_id, invoice_number, amount, payment_date, payment_method, reference, notes, created_by, created_at)
             VALUES (:project_id, :plan_id, :invoice_number, :amount, :payment_date, :payment_method, :reference, :notes, :created_by, :now)'
        );
        $stmt->execute([
            'project_id' => $projectId,
            'plan_id' => $planId,
            'invoice_number' => $invoiceNumber,
            'amount' => $incomeData['amount'],
            'payment_date' => $incomeData['payment_date'],
            'payment_method' => $incomeData['payment_method'] ?? 'bank_transfer',
            'reference' => $incomeData['reference'] ?? null,
            'notes' => $incomeData['notes'] ?? null,
            'created_by' => $userId,
            'now' => date('Y-m-d H:i:s'),
        ]);
        $incomeId = (int) $db->lastInsertId();

        $db->prepare(
            "UPDATE project_payment_plan_runs
             SET status = 'paid', income_id = :income_id, resolved_at = :now, resolved_by = :uid
             WHERE id = :id"
        )->execute(['income_id' => $incomeId, 'now' => date('Y-m-d H:i:s'), 'uid' => $userId, 'id' => $runId]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $linkedInvoice = find_invoice_by_project($projectId);
    if ($linkedInvoice !== false && !in_array($linkedInvoice['status'], ['cancelled', 'completed'], true)) {
        $invoicePaymentId = add_invoice_payment((int) $linkedInvoice['id'], [
            'amount' => $incomeData['amount'],
            'payment_date' => $incomeData['payment_date'],
            'payment_method' => $incomeData['payment_method'] ?? 'bank_transfer',
            'reference' => $incomeData['reference'] ?? null,
            'notes' => 'Mirrored from a confirmed project payment.',
        ], $userId);

        $db->prepare('UPDATE project_income SET invoice_payment_id = :ipid WHERE id = :id')
            ->execute(['ipid' => $invoicePaymentId, 'id' => $incomeId]);
    }

    return $incomeId;
}

/** Skips a pending run for this month/date — a monthly plan will be picked up again next month; a one-time plan simply stays unpaid. */
function skip_project_payment_run(int $runId, int $userId): bool
{
    $stmt = db()->prepare(
        "UPDATE project_payment_plan_runs
         SET status = 'skipped', resolved_at = :now, resolved_by = :uid
         WHERE id = :id"
    );
    return $stmt->execute(['now' => date('Y-m-d H:i:s'), 'uid' => $userId, 'id' => $runId]);
}

// ---------------------------------------------------------------------
// Income (paid records)
// ---------------------------------------------------------------------

/**
 * Records a payment directly against a project, without going through
 * a plan/run (e.g. an ad-hoc top-up).
 *
 * If the project already has a GST invoice linked to it (e.g. the
 * draft auto-created when the project was marked Completed — see
 * auto_generate_invoice_for_completed_project() in includes/billing.php),
 * the same payment is mirrored into invoice_payments so the invoice's
 * amount_paid/status, the project header's "Paid" figure, and the GST
 * Billing page all stay in sync with whatever gets logged here — no
 * matter which screen the payment was actually entered on. The mirror's
 * id is stored on invoice_payment_id so income totals that already
 * treat invoice_payments and project_income as separate streams
 * (total_income(), revenue_month_to_date(), get_ledger_entries()) can
 * skip this row and avoid double-counting the same rupee.
 */
function record_project_income(int $projectId, ?int $planId, array $data, int $createdBy): int
{
    $db = db();
    $db->beginTransaction();

    try {
        $invoiceNumber = next_project_income_number(financial_year_for($data['payment_date']));

        $stmt = $db->prepare(
            'INSERT INTO project_income (project_id, plan_id, invoice_number, amount, payment_date, payment_method, reference, notes, created_by, created_at)
             VALUES (:project_id, :plan_id, :invoice_number, :amount, :payment_date, :payment_method, :reference, :notes, :created_by, :now)'
        );
        $stmt->execute([
            'project_id' => $projectId,
            'plan_id' => $planId,
            'invoice_number' => $invoiceNumber,
            'amount' => $data['amount'],
            'payment_date' => $data['payment_date'],
            'payment_method' => $data['payment_method'] ?? 'bank_transfer',
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $createdBy,
            'now' => date('Y-m-d H:i:s'),
        ]);
        $incomeId = (int) $db->lastInsertId();

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // Mirrored into invoice_payments (own transaction — add_invoice_payment()
    // manages that itself) so the linked invoice's amount_paid/status, the
    // project header's "Paid" figure, and the GST Billing page all stay in
    // sync with whatever gets logged here, no matter which screen the
    // payment was actually entered on. Kept outside the transaction above
    // so this project_income row is never lost if the mirror step fails —
    // same pattern as soft_delete_project_income() reversing it afterwards.
    $linkedInvoice = find_invoice_by_project($projectId);
    if ($linkedInvoice !== false && !in_array($linkedInvoice['status'], ['cancelled', 'completed'], true)) {
        $invoicePaymentId = add_invoice_payment((int) $linkedInvoice['id'], [
            'amount' => $data['amount'],
            'payment_date' => $data['payment_date'],
            'payment_method' => $data['payment_method'] ?? 'bank_transfer',
            'reference' => $data['reference'] ?? null,
            'notes' => 'Mirrored from a project payment' . (!empty($data['notes']) ? ' — ' . $data['notes'] : '') . '.',
        ], $createdBy);

        $db->prepare('UPDATE project_income SET invoice_payment_id = :ipid WHERE id = :id')
            ->execute(['ipid' => $invoicePaymentId, 'id' => $incomeId]);
    }

    return $incomeId;
}

/**
 * Inserts a project_income row that's already backed by an existing
 * invoice_payments row (invoice_payment_id passed in, not created
 * here) — used when a payment is recorded from the GST Billing side
 * (billing/view.php) on an invoice linked to a project, so it also
 * shows up in that project's Payments card. This is the mirror-image
 * of record_project_income()'s own invoice_payments mirroring; kept
 * as a separate function (rather than a flag on record_project_income())
 * so the two entry points can never call each other and loop.
 */
function record_project_income_mirror_only(int $projectId, int $invoicePaymentId, array $data, int $createdBy): int
{
    $invoiceNumber = next_project_income_number(financial_year_for($data['payment_date']));

    $stmt = db()->prepare(
        'INSERT INTO project_income (project_id, plan_id, invoice_payment_id, invoice_number, amount, payment_date, payment_method, reference, notes, created_by, created_at)
         VALUES (:project_id, NULL, :invoice_payment_id, :invoice_number, :amount, :payment_date, :payment_method, :reference, :notes, :created_by, :now)'
    );
    $stmt->execute([
        'project_id' => $projectId,
        'invoice_payment_id' => $invoicePaymentId,
        'invoice_number' => $invoiceNumber,
        'amount' => $data['amount'],
        'payment_date' => $data['payment_date'],
        'payment_method' => $data['payment_method'] ?? 'bank_transfer',
        'reference' => $data['reference'] ?? null,
        'notes' => $data['notes'] ?? null,
        'created_by' => $createdBy,
        'now' => date('Y-m-d H:i:s'),
    ]);

    return (int) db()->lastInsertId();
}

function get_project_income(int $projectId): array
{
    $stmt = db()->prepare(
        'SELECT pi.*, u.full_name AS created_by_name
         FROM project_income pi
         LEFT JOIN users u ON u.id = pi.created_by
         WHERE pi.project_id = :pid AND pi.deleted_at IS NULL
         ORDER BY pi.payment_date DESC, pi.id DESC'
    );
    $stmt->execute(['pid' => $projectId]);
    return $stmt->fetchAll();
}

function find_project_income(int $id): array|false
{
    $stmt = db()->prepare('SELECT * FROM project_income WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}

/**
 * Soft-deletes an income entry, whether it was added manually via
 * record_project_income() or accepted through the automatic monthly/
 * one-time confirm flow (confirm_project_payment_run()). If it came
 * from a confirmed run, that run is put back to 'pending' so the
 * payment becomes payable again instead of silently staying marked
 * 'paid' against a now-deleted income row. If the payment was mirrored
 * into a linked invoice's invoice_payments (see record_project_income()),
 * that mirror row is deleted too so the invoice's amount_paid/status
 * and the GST Billing page don't keep showing money that was just
 * removed here.
 */
function soft_delete_project_income(int $id): bool
{
    $db = db();
    $db->beginTransaction();

    try {
        $stmt = $db->prepare('SELECT invoice_payment_id FROM project_income WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $invoicePaymentId = $stmt->fetch()['invoice_payment_id'] ?? null;

        $db->prepare('UPDATE project_income SET deleted_at = :now WHERE id = :id')
            ->execute(['now' => date('Y-m-d H:i:s'), 'id' => $id]);

        $db->prepare(
            "UPDATE project_payment_plan_runs
             SET status = 'pending', income_id = NULL, resolved_at = NULL, resolved_by = NULL
             WHERE income_id = :id"
        )->execute(['id' => $id]);

        $db->commit();

        if ($invoicePaymentId !== null) {
            delete_invoice_payment((int) $invoicePaymentId);
        }

        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/** Total ever collected for one project — shown on projects/view.php. */
function total_project_income(int $projectId): float
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(amount), 0) AS total FROM project_income WHERE project_id = :pid AND deleted_at IS NULL');
    $stmt->execute(['pid' => $projectId]);
    return (float) $stmt->fetch()['total'];
}

// ---------------------------------------------------------------------
// Display helpers
// ---------------------------------------------------------------------

/**
 * Builds calendar events for the shared calendar widget on
 * accounts/project_payments.php — one event per active plan's due date
 * that falls in the given month (monthly plans project their
 * day_of_month into every month; one_time plans show once on their
 * due_date). Colour reflects that month's run status when one exists,
 * otherwise 'upcoming'/'missed' based on today's date.
 */
function get_project_payment_calendar_events(string $monthStart, string $monthEnd): array
{
    $stmt = db()->query(
        "SELECT project_payment_plans.*, projects.title AS project_title, clients.company_name AS client_name, clients.mobile AS client_mobile
         FROM project_payment_plans
         JOIN projects ON projects.id = project_payment_plans.project_id
         LEFT JOIN clients ON clients.id = projects.client_id
         WHERE project_payment_plans.deleted_at IS NULL AND project_payment_plans.is_active = 1"
    );

    $monthYear = (int) date('Y', strtotime($monthStart));
    $monthNum = (int) date('n', strtotime($monthStart));
    $daysInMonth = (int) date('t', strtotime($monthStart));
    $today = date('Y-m-d');
    $monthKey = date('Y-m', strtotime($monthStart));

    $events = [];
    foreach ($stmt->fetchAll() as $plan) {
        if ($plan['payment_type'] === 'monthly') {
            if ($plan['day_of_month'] === null) {
                continue;
            }
            $day = min((int) $plan['day_of_month'], $daysInMonth);
            $dueDate = sprintf('%04d-%02d-%02d', $monthYear, $monthNum, $day);
        } else {
            if ($plan['due_date'] === null || $plan['due_date'] < $monthStart || $plan['due_date'] > $monthEnd) {
                continue;
            }
            $dueDate = $plan['due_date'];
        }

        $run = find_project_payment_plan_run((int) $plan['id'], $monthKey);
        if ($run !== false && $run['status'] === 'paid') {
            $status = 'done';
        } elseif ($run !== false && $run['status'] === 'skipped') {
            $status = 'cancelled';
        } elseif ($dueDate < $today) {
            $status = 'missed';
        } else {
            $status = 'upcoming';
        }

        $events[] = [
            'date' => $dueDate,
            'initials' => mb_substr($plan['client_name'] ?? $plan['project_title'], 0, 2),
            'title' => $plan['project_title'] . ($plan['client_name'] ? ' — ' . $plan['client_name'] : ''),
            'subtitle' => ($plan['payment_type'] === 'monthly' ? 'Monthly · ' : 'One-time · ') . '₹' . number_format((float) $plan['amount'], 2),
            'phone' => $plan['client_mobile'],
            'status' => $status,
            'url' => $run !== false && $run['status'] === 'pending'
                ? url('accounts/project_payment_confirm.php?plan_id=' . $plan['id'] . '&month=' . $monthKey)
                : url('projects/view.php?id=' . $plan['project_id']),
        ];
    }

    return $events;
}

function project_payment_type_label(string $type): string
{
    return $type === 'monthly' ? 'Monthly Payment' : 'One-Time Payment';
}
