<?php

declare(strict_types=1);

/**
 * includes/billing.php
 * GST Billing business logic: gap-free per-financial-year invoice
 * numbering, CGST/SGST vs. IGST computation, invoice + line item CRUD,
 * and payment recording. See database/migration_005_billing.sql for
 * why tax treatment is decided once at creation and never silently
 * recalculated later.
 */

// ---------------------------------------------------------------------
// Financial year + invoice numbering
// ---------------------------------------------------------------------

/** India's financial year runs Apr 1 – Mar 31, e.g. "2025-26". */
function financial_year_for(string $date): string
{
    $ts = strtotime($date);
    $year = (int) date('Y', $ts);
    $month = (int) date('n', $ts);
    $startYear = $month >= 4 ? $year : $year - 1;
    return $startYear . '-' . substr((string) ($startYear + 1), 2, 2);
}

/**
 * Atomically reserves the next invoice number for the given financial
 * year. Must be called inside a transaction that also inserts the
 * invoice row, so a crash between the two never leaves a burned gap.
 */
function next_invoice_number(string $financialYear): string
{
    $stmt = db()->prepare('SELECT last_number FROM invoice_number_sequences WHERE financial_year = :fy FOR UPDATE');
    $stmt->execute(['fy' => $financialYear]);
    $row = $stmt->fetch();

    if ($row === false) {
        db()->prepare('INSERT INTO invoice_number_sequences (financial_year, last_number) VALUES (:fy, 1)')
            ->execute(['fy' => $financialYear]);
        $next = 1;
    } else {
        $next = (int) $row['last_number'] + 1;
        db()->prepare('UPDATE invoice_number_sequences SET last_number = :next WHERE financial_year = :fy')
            ->execute(['next' => $next, 'fy' => $financialYear]);
    }

    return sprintf('%s-%s-%04d', INVOICE_NUMBER_PREFIX, $financialYear, $next);
}

// ---------------------------------------------------------------------
// Tax computation
//
// Default rule ('auto'): if the client has a GSTIN on file, GST (18%,
// split evenly into CGST 9% + SGST 9%) is added to the invoice. If they
// don't have a GSTIN, no GST is added (0%). This applies uniformly to
// the whole invoice — GST rate is not set per line item.
//
// This can be overridden per invoice via gst_override ('gst' forces
// the 18% rate regardless of the client's GSTIN, 'non_gst' forces 0%
// regardless of it) — e.g. for a GST-registered client being billed
// under a non-taxable arrangement, or an unregistered client who's
// still meant to be charged GST.
// ---------------------------------------------------------------------

define('DEFAULT_GST_RATE_PERCENT', 18.0);

function invoice_gst_rate_for_client(?string $clientGstin, string $gstOverride = 'auto'): float
{
    return match ($gstOverride) {
        'gst' => DEFAULT_GST_RATE_PERCENT,
        'non_gst' => 0.0,
        default => (trim((string) $clientGstin) !== '') ? DEFAULT_GST_RATE_PERCENT : 0.0,
    };
}

/** The full list of valid gst_override values, for form/validation use. */
function invoice_gst_override_options(): array
{
    return [
        'auto' => 'Auto (based on client GSTIN)',
        'gst' => 'Force GST (18%)',
        'non_gst' => 'Force Non-GST (0%)',
    ];
}

/** Whether GST was actually applied to this invoice — from its stored tax amounts, not the override setting alone (so it stays correct even for invoices created before this column existed). */
function invoice_is_gst(array $invoice): bool
{
    return ((float) ($invoice['cgst_amount'] ?? 0) + (float) ($invoice['sgst_amount'] ?? 0) + (float) ($invoice['igst_amount'] ?? 0)) > 0;
}

/**
 * @param array<int, array{description:string,hsn_sac?:string,quantity:float,unit_price:float}> $items
 * @return array{items:array,subtotal:float,taxable_amount:float,cgst_amount:float,sgst_amount:float,igst_amount:float,total_amount:float}
 */
function compute_invoice_totals(array $items, float $discountAmount, float $gstRate): array
{
    $subtotal = 0.0;
    $computedItems = [];

    foreach ($items as $item) {
        $lineSubtotal = round($item['quantity'] * $item['unit_price'], 2);
        $subtotal += $lineSubtotal;
        $computedItems[] = $item + ['line_subtotal' => $lineSubtotal];
    }

    $discountAmount = min($discountAmount, $subtotal);
    $taxableBase = $subtotal - $discountAmount;
    // Discount is spread across lines proportionally so each line's tax
    // is computed on its own post-discount share, not the full price.
    $discountRatio = $subtotal > 0 ? $taxableBase / $subtotal : 1.0;

    $cgst = 0.0;
    $sgst = 0.0;

    foreach ($computedItems as $i => $item) {
        $lineTaxable = round($item['line_subtotal'] * $discountRatio, 2);
        $lineTax = round($lineTaxable * ($gstRate / 100), 2);
        $lineCgst = round($lineTax / 2, 2);
        $lineSgst = $lineTax - $lineCgst;
        $cgst += $lineCgst;
        $sgst += $lineSgst;

        $computedItems[$i]['gst_rate'] = $gstRate;
        $computedItems[$i]['line_tax'] = $lineTax;
        $computedItems[$i]['line_total'] = round($lineTaxable + $lineTax, 2);
    }

    return [
        'items' => $computedItems,
        'subtotal' => round($subtotal, 2),
        'taxable_amount' => round($taxableBase, 2),
        'cgst_amount' => round($cgst, 2),
        'sgst_amount' => round($sgst, 2),
        'igst_amount' => 0.0,
        'total_amount' => round($taxableBase + $cgst + $sgst, 2),
    ];
}

// ---------------------------------------------------------------------
// Invoice CRUD
// ---------------------------------------------------------------------

/**
 * @param array $data Invoice header fields: client_id, project_id, invoice_date, due_date,
 *                     place_of_supply, discount_amount, notes, terms, status
 * @param array $items Line items — see compute_invoice_totals()
 */
function create_invoice(array $data, array $items, int $createdBy): int
{
    $db = db();
    $db->beginTransaction();

    try {
        $client = find_client((int) $data['client_id']);
        $gstOverride = in_array($data['gst_override'] ?? 'auto', ['auto', 'gst', 'non_gst'], true) ? $data['gst_override'] : 'auto';
        $gstRate = invoice_gst_rate_for_client($client['gstin'] ?? null, $gstOverride);
        $totals = compute_invoice_totals($items, (float) ($data['discount_amount'] ?? 0), $gstRate);
        $financialYear = financial_year_for($data['invoice_date']);
        $invoiceNumber = next_invoice_number($financialYear);

        $stmt = $db->prepare(
            'INSERT INTO invoices (
                uuid, invoice_number, client_id, project_id, invoice_date, due_date, status,
                place_of_supply, is_interstate, gst_override, subtotal, discount_amount, taxable_amount,
                cgst_amount, sgst_amount, igst_amount, total_amount, amount_paid, notes, terms,
                created_by, created_at, updated_at
             ) VALUES (
                :uuid, :invoice_number, :client_id, :project_id, :invoice_date, :due_date, :status,
                :place_of_supply, 0, :gst_override, :subtotal, :discount_amount, :taxable_amount,
                :cgst_amount, :sgst_amount, :igst_amount, :total_amount, 0, :notes, :terms,
                :created_by, :created_at, :updated_at
             )'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
    'uuid' => generate_uuid_v4(),
    'invoice_number' => $invoiceNumber,
    'client_id' => $data['client_id'],
    'project_id' => $data['project_id'],
    'invoice_date' => $data['invoice_date'],
    'due_date' => $data['due_date'],
    'status' => $data['status'],
    'place_of_supply' => $data['place_of_supply'] ?? ($client['state'] ?? null),
    'gst_override' => $gstOverride,

    'subtotal' => $totals['subtotal'],
    'discount_amount' => $data['discount_amount'] ?? 0,
    'taxable_amount' => $totals['taxable_amount'],
    'cgst_amount' => $totals['cgst_amount'],
    'sgst_amount' => $totals['sgst_amount'],
    'igst_amount' => $totals['igst_amount'],
    'total_amount' => $totals['total_amount'],

    'notes' => $data['notes'],
    'terms' => $data['terms'],

    'created_by' => $createdBy,
    'created_at' => $now,
    'updated_at' => $now,
]);
        $invoiceId = (int) $db->lastInsertId();

        insert_invoice_items($invoiceId, $totals['items']);

        $db->commit();
        return $invoiceId;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function update_invoice(int $id, array $data, array $items, int $createdBy): bool
{
    $db = db();
    $db->beginTransaction();

    try {
        $client = find_client((int) $data['client_id']);
        $gstOverride = in_array($data['gst_override'] ?? 'auto', ['auto', 'gst', 'non_gst'], true) ? $data['gst_override'] : 'auto';
        $gstRate = invoice_gst_rate_for_client($client['gstin'] ?? null, $gstOverride);
        $totals = compute_invoice_totals($items, (float) ($data['discount_amount'] ?? 0), $gstRate);

        // Serialize invoice edits with payment recording/recalculation so a
        // second request cannot calculate its delta from an obsolete total.
        $previousPaidStmt = $db->prepare('SELECT amount_paid FROM invoices WHERE id = :id FOR UPDATE');
        $previousPaidStmt->execute(['id' => $id]);
        $previousPaid = (float) ($previousPaidStmt->fetch()['amount_paid'] ?? 0);

        $paymentEditToken = trim((string) ($data['amount_paid_edit_token'] ?? ''));
        $paymentEditToken = preg_match('/^[a-f0-9]{32}$/', $paymentEditToken) === 1 ? $paymentEditToken : null;
        $paymentDeltaAlreadyRecorded = false;
        if ($paymentEditToken !== null) {
            $tokenStmt = $db->prepare(
                'SELECT id FROM invoice_payments
                 WHERE invoice_id = :invoice_id AND notes = :notes
                 LIMIT 1'
            );
            $tokenStmt->execute([
                'invoice_id' => $id,
                'notes' => 'Auto-recorded from Amount Paid edit on invoice form. Token: ' . $paymentEditToken,
            ]);
            $paymentDeltaAlreadyRecorded = $tokenStmt->fetch() !== false;
        }

        $paidDelta = 0.0;
        if (!$paymentDeltaAlreadyRecorded && $paymentEditToken !== null && array_key_exists('payment_amount', $data)) {
            $remaining = max(0.0, (float) $totals['total_amount'] - $previousPaid);
            $paidDelta = max(0.0, min(round((float) $data['payment_amount'], 2), $remaining));
        }
        $amountPaid = $previousPaid + $paidDelta;

        $status = in_array($data['status'] ?? 'draft', invoice_status_options(), true) ? ($data['status'] ?? 'draft') : 'draft';

        // Completed = fully paid, full stop. Whether requested via this
        // dropdown or implied by an Amount Paid edit, the status can't
        // land on 'completed' while a balance is still outstanding — it
        // falls back to whatever status the current paid amount implies.
        if ($status === 'completed' && $amountPaid < (float) $totals['total_amount']) {
            $status = $amountPaid > 0 ? 'partially_paid'
                : ((!empty($data['due_date']) && $data['due_date'] < date('Y-m-d')) ? 'overdue' : 'sent');
        } elseif ($status === 'draft' && $amountPaid > 0) {
            // A Draft invoice that has a paid amount against it isn't
            // really a draft anymore — it must reflect as Partially
            // Paid/Paid so it shows correctly on the Accounts ledger,
            // same as the logic below for non-draft statuses.
            $status = ($amountPaid >= (float) $totals['total_amount'] && (float) $totals['total_amount'] > 0)
                ? 'paid' : 'partially_paid';
        } elseif (!in_array($status, ['cancelled', 'draft', 'completed'], true)) {
            // Otherwise keep status in sync with the paid amount the same
            // way recalculate_invoice_payment_status() does it — so a
            // manual Amount Paid edit and a recorded payment both drive
            // status the same way.
            if ($amountPaid >= (float) $totals['total_amount'] && (float) $totals['total_amount'] > 0) {
                $status = 'paid';
            } elseif ($amountPaid > 0) {
                $status = 'partially_paid';
            } elseif (!empty($data['due_date']) && $data['due_date'] < date('Y-m-d')) {
                $status = 'overdue';
            } else {
                $status = 'sent';
            }
        }

        $stmt = $db->prepare(
            'UPDATE invoices SET
                client_id = :client_id, project_id = :project_id, invoice_date = :invoice_date,
                due_date = :due_date, status = :status, place_of_supply = :place_of_supply,
                gst_override = :gst_override,
                subtotal = :subtotal, discount_amount = :discount_amount,
                taxable_amount = :taxable_amount, cgst_amount = :cgst_amount, sgst_amount = :sgst_amount,
                igst_amount = :igst_amount, total_amount = :total_amount, amount_paid = :amount_paid,
                notes = :notes, terms = :terms,
                updated_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            'client_id' => $data['client_id'],
            'project_id' => $data['project_id'] ?: null,
            'invoice_date' => $data['invoice_date'],
            'due_date' => $data['due_date'] ?: null,
            'status' => $status,
            'place_of_supply' => $data['place_of_supply'] ?? ($client['state'] ?? null),
            'gst_override' => $gstOverride,
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $data['discount_amount'] ?? 0,
            'taxable_amount' => $totals['taxable_amount'],
            'cgst_amount' => $totals['cgst_amount'],
            'sgst_amount' => $totals['sgst_amount'],
            'igst_amount' => $totals['igst_amount'],
            'total_amount' => $totals['total_amount'],
            'amount_paid' => $amountPaid,
            'notes' => $data['notes'] ?? null,
            'terms' => $data['terms'] ?? null,
            'now' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);

        $db->prepare('DELETE FROM invoice_items WHERE invoice_id = :id')->execute(['id' => $id]);
        insert_invoice_items($id, $totals['items']);

        // total_income() / the GST Report's "Income Collected" figure is
        // summed from invoice_payments only — so an Amount Paid edit here
        // auto-records the paid delta as an invoice_payments row
        // (payment_method 'manual_edit'), otherwise a paid amount typed
        // here would update the invoice's own numbers but never show up
        // as Income.
        if ($paidDelta !== 0.0) {
            $db->prepare(
                'INSERT INTO invoice_payments (invoice_id, amount, payment_date, payment_method, reference, notes, created_by, created_at)
                 VALUES (:invoice_id, :amount, :payment_date, :method, :reference, :notes, :created_by, :now)'
            )->execute([
                'invoice_id' => $id,
                'amount' => $paidDelta,
                'payment_date' => date('Y-m-d'),
                'method' => 'other',
                'reference' => null,
                'notes' => 'Auto-recorded from Amount Paid edit on invoice form. Token: ' . $paymentEditToken,
                'created_by' => $createdBy,
                'now' => date('Y-m-d H:i:s'),
            ]);
        }

        // Keep the denormalized paid total and status derived from the final
        // payment rows after any legitimate edit delta has been inserted.
        if ($paidDelta !== 0.0) {
            recalculate_invoice_payment_status($id);
        }

        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function insert_invoice_items(int $invoiceId, array $items): void
{
    $stmt = db()->prepare(
        'INSERT INTO invoice_items (invoice_id, description, hsn_sac, quantity, unit_price, gst_rate, line_subtotal, line_tax, line_total, sort_order)
         VALUES (:invoice_id, :description, :hsn_sac, :quantity, :unit_price, :gst_rate, :line_subtotal, :line_tax, :line_total, :sort_order)'
    );
    foreach ($items as $i => $item) {
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'description' => $item['description'],
            'hsn_sac' => $item['hsn_sac'] ?? null,
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'gst_rate' => $item['gst_rate'],
            'line_subtotal' => $item['line_subtotal'],
            'line_tax' => $item['line_tax'],
            'line_total' => $item['line_total'],
            'sort_order' => $i,
        ]);
    }
}

function find_invoice(int $id): array|false
{
    $stmt = db()->prepare(
        'SELECT invoices.*, clients.company_name AS client_name, clients.gstin AS client_gstin,
                clients.billing_address AS client_address, clients.email AS client_email,
                clients.mobile AS client_mobile, projects.title AS project_title,
                clients.logo_stored_filename AS client_logo_stored_filename,
                clients.seal_stored_filename AS client_seal_stored_filename,
                clients.signature_stored_filename AS client_signature_stored_filename
         FROM invoices
         LEFT JOIN clients ON clients.id = invoices.client_id
         LEFT JOIN projects ON projects.id = invoices.project_id
         WHERE invoices.id = :id AND invoices.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}

/** First non-deleted invoice already linked to this project, if any — used to avoid double-billing a project that's marked Completed more than once. */
function find_invoice_by_project(int $projectId): array|false
{
    $stmt = db()->prepare('SELECT * FROM invoices WHERE project_id = :pid AND deleted_at IS NULL ORDER BY id LIMIT 1');
    $stmt->execute(['pid' => $projectId]);
    return $stmt->fetch();
}/**
 * Auto-creates a draft GST invoice when a project is marked Completed, so
 * the project's budget doesn't have to be re-typed by hand into Billing.
 * Same "safe to call more than once" pattern as
 * convert_won_lead_to_client() — it checks find_invoice_by_project()
 * first and skips if this project already has one. Left as a 'draft' (not
 * sent/paid) since the amount/line items still need review before it
 * actually goes out. Returns null (does nothing) if the project has no
 * budget set, since there'd be nothing to bill.
 */
function auto_generate_invoice_for_completed_project(int $projectId, int $createdBy): ?int
{
    if (find_invoice_by_project($projectId) !== false) {
        return null;
    }

    $project = find_project($projectId);
    if ($project === false) {
        return null;
    }

    $budget = (float) ($project['budget'] ?? 0);
    if ($budget <= 0) {
        return null;
    }

    $invoiceId = create_invoice([
        'client_id' => $project['client_id'],
        'project_id' => $projectId,
        'invoice_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d', strtotime('+15 days')),
        'status' => 'draft',
        'place_of_supply' => null,
        'gst_override' => 'auto',
        'discount_amount' => 0,
        'notes' => 'Auto-created from completed project #' . $projectId . ' (' . $project['title'] . ').',
        'terms' => null,
    ], [
        [
            'description' => $project['title'] . ' — project completion',
            'hsn_sac' => null,
            'quantity' => 1,
            'unit_price' => $budget,
        ],
    ], $createdBy);

    if ($project['manager_id'] !== null) {
        create_notification(
            (int) $project['manager_id'],
            'general',
            "Project completed — invoice drafted: {$project['title']}",
            'This project was marked Completed and a draft GST invoice was automatically created from its budget. Review it before sending.',
            'billing/view.php?id=' . $invoiceId
        );
    }

    return $invoiceId;
}

function get_invoice_items(int $invoiceId): array
{
    $stmt = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id ORDER BY sort_order ASC');
    $stmt->execute(['id' => $invoiceId]);
    return $stmt->fetchAll();
}

function soft_delete_invoice(int $id): bool
{
    $stmt = db()->prepare('UPDATE invoices SET deleted_at = :now WHERE id = :id');
    return $stmt->execute(['now' => date('Y-m-d H:i:s'), 'id' => $id]);
}

/**
 * @param array{status?:string,client_id?:int,search?:string} $filters
 * @return array{sql_where:string, params:array}
 */
function build_invoice_filters(array $filters): array
{
    $where = ['invoices.deleted_at IS NULL'];
    $params = [];

    if (!empty($filters['status'])) {
        $where[] = 'invoices.status = :status';
        $params['status'] = $filters['status'];
    }
    if (!empty($filters['client_id'])) {
        $where[] = 'invoices.client_id = :client_id';
        $params['client_id'] = $filters['client_id'];
    }
    if (!empty($filters['gst_type']) && $filters['gst_type'] === 'gst') {
        $where[] = '(invoices.cgst_amount + invoices.sgst_amount + invoices.igst_amount) > 0';
    } elseif (!empty($filters['gst_type']) && $filters['gst_type'] === 'non_gst') {
        $where[] = '(invoices.cgst_amount + invoices.sgst_amount + invoices.igst_amount) = 0';
    }
    if (!empty($filters['search'])) {
        $where[] = '(invoices.invoice_number LIKE :search1 OR clients.company_name LIKE :search2)';
        $searchTerm = '%' . $filters['search'] . '%';
        $params['search1'] = $searchTerm;
        $params['search2'] = $searchTerm;
    }

    return ['sql_where' => implode(' AND ', $where), 'params' => $params];
}

function count_invoices(array $filters): int
{
    ['sql_where' => $where, 'params' => $params] = build_invoice_filters($filters);
    $stmt = db()->prepare("SELECT COUNT(*) AS cnt FROM invoices LEFT JOIN clients ON clients.id = invoices.client_id WHERE {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetch()['cnt'];
}

function list_invoices(array $filters, int $limit, int $offset): array
{
    ['sql_where' => $where, 'params' => $params] = build_invoice_filters($filters);
    $stmt = db()->prepare(
        "SELECT invoices.*, clients.company_name AS client_name
         FROM invoices
         LEFT JOIN clients ON clients.id = invoices.client_id
         WHERE {$where}
         ORDER BY (invoices.due_date IS NULL) ASC, invoices.due_date ASC, invoices.invoice_date DESC, invoices.id DESC
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

/**
 * Invoice-wise GST breakdown for a date range (by invoice_date), used
 * by the Accounts > GST Report export. Excludes deleted and draft
 * invoices — a draft was never actually issued, so it has no GST
 * liability yet. Cancelled invoices are excluded too.
 *
 * @return array<int, array{invoice_number:string,invoice_date:string,client_name:string,taxable_amount:float,cgst_amount:float,sgst_amount:float,igst_amount:float,total_amount:float}>
 */
function gst_invoices_for_period(string $startDate, string $endDate): array
{
    $stmt = db()->prepare(
        "SELECT invoices.invoice_number, invoices.invoice_date, clients.company_name AS client_name,
                clients.gstin AS client_gstin,
                invoices.taxable_amount, invoices.cgst_amount, invoices.sgst_amount,
                invoices.igst_amount, invoices.total_amount
         FROM invoices
         LEFT JOIN clients ON clients.id = invoices.client_id
         WHERE invoices.deleted_at IS NULL AND invoices.status NOT IN ('draft', 'cancelled')
               AND invoices.invoice_date BETWEEN :start AND :end
         ORDER BY invoices.invoice_date ASC, invoices.invoice_number ASC"
    );
    $stmt->execute(['start' => $startDate, 'end' => $endDate]);
    return $stmt->fetchAll();
}

/**
 * Totals rolled up from gst_invoices_for_period() — taxable amount,
 * CGST/SGST/IGST collected, and grand total, for the GST Report's
 * summary cards.
 *
 * @return array{invoice_count:int,taxable_amount:float,cgst_amount:float,sgst_amount:float,igst_amount:float,total_amount:float}
 */
function gst_totals_for_period(string $startDate, string $endDate): array
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS invoice_count,
                COALESCE(SUM(taxable_amount), 0) AS taxable_amount,
                COALESCE(SUM(cgst_amount), 0) AS cgst_amount,
                COALESCE(SUM(sgst_amount), 0) AS sgst_amount,
                COALESCE(SUM(igst_amount), 0) AS igst_amount,
                COALESCE(SUM(total_amount), 0) AS total_amount
         FROM invoices
         WHERE deleted_at IS NULL AND status NOT IN ('draft', 'cancelled')
               AND invoice_date BETWEEN :start AND :end"
    );
    $stmt->execute(['start' => $startDate, 'end' => $endDate]);
    $row = $stmt->fetch();
    return [
        'invoice_count' => (int) $row['invoice_count'],
        'taxable_amount' => (float) $row['taxable_amount'],
        'cgst_amount' => (float) $row['cgst_amount'],
        'sgst_amount' => (float) $row['sgst_amount'],
        'igst_amount' => (float) $row['igst_amount'],
        'total_amount' => (float) $row['total_amount'],
    ];
}

/**
 * Splits the invoice list from gst_invoices_for_period() into "GST
 * entered" and "Non-GST" groups — used by the GST Report (on-screen,
 * print/PDF, and Excel export) so auditors never see a non-GST invoice
 * padded out with confusing 0 CGST / 0 SGST / 0 IGST columns. Uses the
 * same invoice_is_gst() check the rest of Billing already relies on.
 *
 * @param array<int, array> $invoices
 * @return array{gst: array<int, array>, non_gst: array<int, array>}
 */
function split_gst_invoices(array $invoices): array
{
    $gst = [];
    $nonGst = [];

    foreach ($invoices as $invoice) {
        if (invoice_is_gst($invoice)) {
            $gst[] = $invoice;
        } else {
            $nonGst[] = $invoice;
        }
    }

    return ['gst' => $gst, 'non_gst' => $nonGst];
}

/**
 * Totals for a single group of invoices (either the "GST entered" or
 * "Non-GST" split from split_gst_invoices()) — same shape as
 * gst_totals_for_period() but computed in PHP from an already-fetched
 * list, so the GST Report doesn't need a second query per group.
 *
 * @param array<int, array> $invoices
 * @return array{invoice_count:int,taxable_amount:float,cgst_amount:float,sgst_amount:float,igst_amount:float,total_amount:float}
 */
function totals_for_invoice_group(array $invoices): array
{
    $totals = ['invoice_count' => count($invoices), 'taxable_amount' => 0.0, 'cgst_amount' => 0.0, 'sgst_amount' => 0.0, 'igst_amount' => 0.0, 'total_amount' => 0.0];

    foreach ($invoices as $invoice) {
        $totals['taxable_amount'] += (float) $invoice['taxable_amount'];
        $totals['cgst_amount'] += (float) $invoice['cgst_amount'];
        $totals['sgst_amount'] += (float) $invoice['sgst_amount'];
        $totals['igst_amount'] += (float) $invoice['igst_amount'];
        $totals['total_amount'] += (float) $invoice['total_amount'];
    }

    return $totals;
}

/** Start/end dates (Y-m-d) for a financial year string like "2025-26". */
function financial_year_bounds(string $financialYear): array
{
    $startYear = (int) substr($financialYear, 0, 4);
    return [
        'start' => sprintf('%04d-04-01', $startYear),
        'end' => sprintf('%04d-03-31', $startYear + 1),
    ];
}

/** Financial years with at least one invoice or expense, plus the current one, newest first — for the GST Report's year picker. */
function financial_years_with_data(): array
{
    $years = [financial_year_for(date('Y-m-d'))];

    $stmt = db()->query('SELECT MIN(invoice_date) AS d FROM invoices WHERE deleted_at IS NULL');
    $earliestInvoice = $stmt->fetch()['d'] ?? null;
    $stmt = db()->query('SELECT MIN(expense_date) AS d FROM expenses WHERE deleted_at IS NULL');
    $earliestExpense = $stmt->fetch()['d'] ?? null;

    $earliest = array_filter([$earliestInvoice, $earliestExpense]);
    if (!empty($earliest)) {
        $years[] = financial_year_for(min($earliest));
    }

    $oldestStartYear = (int) substr(min($years), 0, 4);
    $newestStartYear = (int) substr(financial_year_for(date('Y-m-d')), 0, 4);

    $all = [];
    for ($y = $newestStartYear; $y >= $oldestStartYear; $y--) {
        $all[] = $y . '-' . substr((string) ($y + 1), 2, 2);
    }

    return $all;
}

/** Revenue collected (sum of payments) within the current calendar month — the dashboard's "Revenue (MTD)" figure. */
function revenue_month_to_date(): float
{
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(ip.amount), 0) AS total FROM invoice_payments ip
         JOIN invoices i ON i.id = ip.invoice_id
         WHERE i.deleted_at IS NULL AND ip.payment_date >= :start AND ip.payment_date <= :end"
    );
    $stmt->execute(['start' => date('Y-m-01'), 'end' => date('Y-m-d')]);
    $invoiceRevenue = (float) $stmt->fetch()['total'];

    $projectStmt = db()->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS total FROM project_income
         WHERE deleted_at IS NULL AND invoice_payment_id IS NULL AND payment_date >= :start AND payment_date <= :end"
    );
    $projectStmt->execute(['start' => date('Y-m-01'), 'end' => date('Y-m-d')]);
    $projectRevenue = (float) $projectStmt->fetch()['total'];

    return $invoiceRevenue + $projectRevenue;
}

// ---------------------------------------------------------------------
// Payments
// ---------------------------------------------------------------------

function add_invoice_payment(int $invoiceId, array $data, int $createdBy): int
{
    $db = db();
    $db->beginTransaction();

    try {
        $lockStmt = $db->prepare('SELECT id FROM invoices WHERE id = :id FOR UPDATE');
        $lockStmt->execute(['id' => $invoiceId]);
        if ($lockStmt->fetch() === false) {
            throw new RuntimeException('Invoice not found.');
        }

        $stmt = $db->prepare(
            'INSERT INTO invoice_payments (invoice_id, amount, payment_date, payment_method, reference, notes, created_by, created_at)
             VALUES (:invoice_id, :amount, :payment_date, :method, :reference, :notes, :created_by, :now)'
        );
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'amount' => $data['amount'],
            'payment_date' => $data['payment_date'],
            'method' => $data['payment_method'] ?? 'bank_transfer',
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $createdBy,
            'now' => date('Y-m-d H:i:s'),
        ]);
        $paymentId = (int) $db->lastInsertId();

        recalculate_invoice_payment_status($invoiceId);

        $db->commit();
        return $paymentId;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Removes a recorded payment (or correction row — same table, a
 * negative-amount invoice_payments entry) and recalculates the
 * invoice's amount_paid/status from what's left, so deleting a
 * payment from the Accounts ledger never leaves an invoice showing
 * money that's no longer backed by a payment row. invoice_payments
 * has no deleted_at column (unlike expenses/project_income), so this
 * is a hard delete — consistent with the rest of this table's design.
 *
 * If a project_income row was mirroring this payment (see
 * record_project_income_mirror_only(), includes/project_payments.php —
 * used when a payment is recorded on the GST Billing side of a
 * project-linked invoice), that mirror is soft-deleted too, so the
 * project's Payments card doesn't keep showing money that was just
 * removed here.
 */
function delete_invoice_payment(int $id): bool
{
    $db = db();
    $db->beginTransaction();

    try {
        $stmt = $db->prepare('SELECT invoice_id FROM invoice_payments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $payment = $stmt->fetch();
        if ($payment === false) {
            $db->rollBack();
            return false;
        }

        $lockStmt = $db->prepare('SELECT id FROM invoices WHERE id = :id FOR UPDATE');
        $lockStmt->execute(['id' => (int) $payment['invoice_id']]);
        if ($lockStmt->fetch() === false) {
            $db->rollBack();
            return false;
        }

        $mirrorStmt = $db->prepare('SELECT id FROM project_income WHERE invoice_payment_id = :id AND deleted_at IS NULL');
        $mirrorStmt->execute(['id' => $id]);
        $mirroredIncomeIds = array_column($mirrorStmt->fetchAll(), 'id');

        $db->prepare('DELETE FROM invoice_payments WHERE id = :id')->execute(['id' => $id]);
        recalculate_invoice_payment_status((int) $payment['invoice_id']);

        foreach ($mirroredIncomeIds as $incomeId) {
            $db->prepare('UPDATE project_income SET deleted_at = :now WHERE id = :id')
                ->execute(['now' => date('Y-m-d H:i:s'), 'id' => $incomeId]);
        }

        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/** Recomputes amount_paid and status (paid/partially_paid/overdue) from recorded payments — never trusts a manually-set status to stay in sync. */
function recalculate_invoice_payment_status(int $invoiceId): void
{
    $db = db();
    // Lock the invoice before reading its payment rows so concurrent payment
    // inserts/deletes cannot recalculate amount_paid from a partial snapshot.
    $invoiceStmt = $db->prepare('SELECT total_amount, status, due_date FROM invoices WHERE id = :id FOR UPDATE');
    $invoiceStmt->execute(['id' => $invoiceId]);
    $invoice = $invoiceStmt->fetch();
    if ($invoice === false) {
        return;
    }

    $totalPaidStmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) AS total FROM invoice_payments WHERE invoice_id = :id');
    $totalPaidStmt->execute(['id' => $invoiceId]);
    $totalPaid = (float) $totalPaidStmt->fetch()['total'];

    if ($invoice['status'] === 'cancelled' || $invoice['status'] === 'completed') {
        $newStatus = $invoice['status'];
    } elseif ($invoice['status'] === 'draft' && $totalPaid <= 0) {
        // Draft with no payments recorded against it stays Draft.
        $newStatus = 'draft';
    } elseif ($totalPaid >= (float) $invoice['total_amount'] && (float) $invoice['total_amount'] > 0) {
        $newStatus = 'paid';
    } elseif ($totalPaid > 0) {
        $newStatus = 'partially_paid';
    } elseif (!empty($invoice['due_date']) && $invoice['due_date'] < date('Y-m-d')) {
        $newStatus = 'overdue';
    } else {
        $newStatus = 'sent';
    }

    $db->prepare('UPDATE invoices SET amount_paid = :paid, status = :status, updated_at = :now WHERE id = :id')
        ->execute(['paid' => $totalPaid, 'status' => $newStatus, 'now' => date('Y-m-d H:i:s'), 'id' => $invoiceId]);
}

/**
 * Opportunistically flips sent/partially_paid invoices whose due date
 * has passed to 'overdue' — same on-demand pattern as
 * process_missed_leads() (no cron in this environment; called from
 * the invoice list on every view).
 */
function process_overdue_invoices(): int
{
    $stalePaidStmt = db()->prepare(
        "UPDATE invoices
            SET status = CASE
                WHEN due_date IS NOT NULL AND due_date < :today THEN 'overdue'
                ELSE 'sent'
            END,
            updated_at = :now
          WHERE deleted_at IS NULL
            AND status = 'paid'
            AND amount_paid <= 0"
    );
    $stalePaidStmt->execute([
        'today' => date('Y-m-d'),
        'now' => date('Y-m-d H:i:s'),
    ]);

    $stmt = db()->prepare(
        "SELECT id, invoice_number, created_by FROM invoices
         WHERE deleted_at IS NULL AND status IN ('sent','partially_paid') AND due_date IS NOT NULL AND due_date < :today"
    );
    $stmt->execute(['today' => date('Y-m-d')]);
    $overdue = $stmt->fetchAll();

    if (empty($overdue)) {
        return 0;
    }

    $updateStmt = db()->prepare(
        "UPDATE invoices SET status = 'overdue', updated_at = :now WHERE id = :id"
    );
    foreach ($overdue as $invoice) {
        $updateStmt->execute(['now' => date('Y-m-d H:i:s'), 'id' => $invoice['id']]);
        notify_overdue_invoice((int) $invoice['id'], $invoice['invoice_number'], (int) $invoice['created_by']);
    }

    return count($overdue);
}

function get_invoice_payments(int $invoiceId): array
{
    $stmt = db()->prepare(
        'SELECT ip.*, u.full_name AS created_by_name
         FROM invoice_payments ip
         LEFT JOIN users u ON u.id = ip.created_by
         WHERE ip.invoice_id = :id
         ORDER BY ip.payment_date DESC, ip.id DESC'
    );
    $stmt->execute(['id' => $invoiceId]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------
// Display helpers
// ---------------------------------------------------------------------

/**
 * Builds calendar events (one per invoice with a due_date in the given
 * month) for the shared calendar widget on billing/index.php.
 */
function get_billing_calendar_events(string $monthStart, string $monthEnd): array
{
    $stmt = db()->prepare(
        "SELECT invoices.id, invoices.invoice_number, invoices.status, invoices.due_date,
                invoices.total_amount, invoices.amount_paid, clients.company_name AS client_name, clients.mobile AS client_mobile
           FROM invoices
           LEFT JOIN clients ON clients.id = invoices.client_id
          WHERE invoices.deleted_at IS NULL
            AND invoices.due_date IS NOT NULL
            AND invoices.due_date BETWEEN :start AND :end"
    );
    $stmt->execute(['start' => $monthStart, 'end' => $monthEnd]);

    $events = [];
    foreach ($stmt->fetchAll() as $row) {
        $status = match ($row['status']) {
            'paid', 'completed' => 'done',
            'overdue' => 'missed',
            'cancelled' => 'cancelled',
            default => 'upcoming',
        };

        $balance = (float) $row['total_amount'] - (float) $row['amount_paid'];

        $events[] = [
            'date' => $row['due_date'],
            'initials' => mb_substr($row['client_name'] ?? '??', 0, 2),
            'title' => $row['invoice_number'] . ($row['client_name'] ? ' — ' . $row['client_name'] : ''),
            'subtitle' => 'Due · ₹' . number_format((float) $row['total_amount'], 2) . ' · ' . ucwords(str_replace('_', ' ', $row['status'])),
            'phone' => $row['client_mobile'],
            'status' => $status,
            'url' => url('billing/view.php?id=' . $row['id']),
            'paid' => '₹' . number_format((float) $row['amount_paid'], 2),
            'balance' => '₹' . number_format($balance, 2),
        ];
    }

    return $events;
}

function invoice_status_badge_class(string $status): string
{
    return match ($status) {
        'draft' => 'text-bg-secondary',
        'sent' => 'text-bg-info',
        'partially_paid' => 'text-bg-warning',
        'paid' => 'text-bg-success',
        'overdue' => 'text-bg-danger',
        'completed' => 'text-bg-primary',
        'cancelled' => 'text-bg-dark',
        default => 'text-bg-secondary',
    };
}

/** The full list of valid invoice statuses, for form <select>/filter dropdowns — one place to keep it in sync. */
function invoice_status_options(): array
{
    return ['draft', 'sent', 'partially_paid', 'paid', 'overdue', 'completed', 'cancelled'];
}

/** Simplified Open/Close label for the GST Billing table — bill paid (or completed) is Close, everything else is Open. */
function invoice_open_close_label(string $status): string
{
    return in_array($status, ['paid', 'completed'], true) ? 'Close' : 'Open';
}

/** Badge class to match invoice_open_close_label(). */
function invoice_open_close_badge_class(string $status): string
{
    return in_array($status, ['paid', 'completed'], true) ? 'text-bg-success' : 'text-bg-warning';
}
