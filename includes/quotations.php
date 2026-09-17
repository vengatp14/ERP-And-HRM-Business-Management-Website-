<?php

declare(strict_types=1);

/**
 * includes/quotations.php
 * Quotation business logic. A "common quotation template" in practice
 * means: company info/branding/standard terms are pulled live from
 * includes/company_branding.php (exactly like GST Billing already
 * does for invoices) plus a set of DEFAULT_* constants below for
 * terms/payment-terms text — only the project-specific fields below
 * are actually stored per quotation. Numbering reuses
 * financial_year_for() from includes/billing.php rather than
 * reimplementing it (see next_quotation_number()).
 */

const QUOTATION_WEBSITE_TYPES = [
    'static' => 'Static Website',
    'dynamic' => 'Dynamic Website',
    'ecommerce' => 'E-commerce Website',
    'crm_application' => 'CRM / Business Application',
    'other' => 'Other',
];

/** Default "Project Scope / Features / Implementation" starting point per website type — examples only, fully editable per quotation (req: do not hardcode irrelevant features into every quotation). */
const QUOTATION_DEFAULT_SCOPE_BY_TYPE = [
    'static' => "- Number of pages: \n- Responsive design (mobile, tablet, desktop)\n- Contact form\n- Basic SEO setup\n- Hosting/deployment assistance",
    'dynamic' => "- Admin panel\n- Database-driven content\n- Dynamic content management\n- User management (if applicable)\n- Responsive design",
    'ecommerce' => "- Product listing and management\n- Categories, variants, pricing, discounts\n- Cart and checkout\n- Payment gateway integration\n- Order management\n- Customer registration and login",
    'crm_application' => "- Relevant modules (as agreed)\n- User roles and permissions\n- Reports and dashboard\n- Other project-specific functionality",
    'other' => '',
];

const QUOTATION_DEFAULT_TERMS = "Payment gateway fees, third-party subscription/license charges, and domain/hosting renewal charges are excluded unless specifically mentioned in writing.\nThe client must provide the required content, images, and any credentials/access needed for the project.\nThe project will be developed according to the agreed Scope of Work above.\nNew features, additional functionality, or requirements outside the approved Scope of Work will be quoted and charged separately.\nAny additional cost will be communicated and approved before the additional work begins.\nThis quotation is valid until the date mentioned above.";

const QUOTATION_DEFAULT_GST_RATE = 18.00;

/** All valid website_type values, for form/validation use. */
function quotation_website_type_options(): array
{
    return QUOTATION_WEBSITE_TYPES;
}

function quotation_status_options(): array
{
    return ['draft', 'sent', 'accepted', 'rejected'];
}

function quotation_status_badge_class(string $status): string
{
    return match ($status) {
        'draft' => 'text-bg-secondary',
        'sent' => 'text-bg-info',
        'accepted' => 'text-bg-success',
        'rejected' => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
}

/**
 * Default payment terms text with the actual computed amounts filled
 * in — a starting point only; stored (and editable) per quotation
 * exactly like the invoice terms field, so it never changes
 * retroactively if this default wording changes later.
 */
function quotation_default_payment_terms(float $totalAmount, float $advancePercent = 50.0): string
{
    $advanceAmount = round($totalAmount * $advancePercent / 100, 2);
    $balanceAmount = round($totalAmount - $advanceAmount, 2);
    $balancePercent = 100 - $advancePercent;

    return sprintf(
        "%s%% Advance Payment: ₹%s\nRemaining %s%% Before Final Deployment: ₹%s",
        rtrim(rtrim(number_format($advancePercent, 2), '0'), '.'),
        number_format($advanceAmount, 2),
        rtrim(rtrim(number_format($balancePercent, 2), '0'), '.'),
        number_format($balanceAmount, 2)
    );
}

/**
 * Atomically reserves the next quotation number for the given
 * financial year — same row-locked pattern as next_invoice_number()
 * in includes/billing.php, but its own sequence table/prefix so a
 * quotation number is never mistaken for a formal GST invoice number.
 */
function next_quotation_number(string $financialYear): string
{
    $stmt = db()->prepare('SELECT last_number FROM quotation_number_sequences WHERE financial_year = :fy FOR UPDATE');
    $stmt->execute(['fy' => $financialYear]);
    $row = $stmt->fetch();

    if ($row === false) {
        db()->prepare('INSERT INTO quotation_number_sequences (financial_year, last_number) VALUES (:fy, 1)')
            ->execute(['fy' => $financialYear]);
        $next = 1;
    } else {
        $next = (int) $row['last_number'] + 1;
        db()->prepare('UPDATE quotation_number_sequences SET last_number = :next WHERE financial_year = :fy')
            ->execute(['next' => $next, 'fy' => $financialYear]);
    }

    return sprintf('%s-%s-%04d', QUOTATION_NUMBER_PREFIX, $financialYear, $next);
}

/** @return array{gst_amount:float,total_amount:float} */
function compute_quotation_totals(float $amount, bool $gstApplicable, float $gstRate): array
{
    $gstAmount = $gstApplicable ? round($amount * $gstRate / 100, 2) : 0.0;
    return [
        'gst_amount' => $gstAmount,
        'total_amount' => round($amount + $gstAmount, 2),
    ];
}

/**
 * @param array $data client_id, lead_id, quotation_date, valid_until, project_title,
 *                     website_type, website_type_other, scope_description, amount,
 *                     gst_applicable, gst_rate, payment_terms, terms_conditions, notes, status
 */
function create_quotation(array $data, int $createdBy): int
{
    $db = db();
    $db->beginTransaction();

    try {
        $gstApplicable = !empty($data['gst_applicable']);
        $gstRate = $gstApplicable ? (float) ($data['gst_rate'] ?? QUOTATION_DEFAULT_GST_RATE) : 0.0;
        $totals = compute_quotation_totals((float) $data['amount'], $gstApplicable, $gstRate);

        $financialYear = financial_year_for($data['quotation_date']);
        $quotationNumber = next_quotation_number($financialYear);
        $now = date('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'INSERT INTO quotations (
                uuid, quotation_number, client_id, lead_id, quotation_date, valid_until,
                project_title, website_type, website_type_other, scope_description,
                amount, gst_applicable, gst_rate, gst_amount, total_amount,
                payment_terms, terms_conditions, notes, status,
                created_by, created_at, updated_at
             ) VALUES (
                :uuid, :quotation_number, :client_id, :lead_id, :quotation_date, :valid_until,
                :project_title, :website_type, :website_type_other, :scope_description,
                :amount, :gst_applicable, :gst_rate, :gst_amount, :total_amount,
                :payment_terms, :terms_conditions, :notes, :status,
                :created_by, :created_at, :updated_at
             )'
        );
        $stmt->execute([
            'uuid' => generate_uuid_v4(),
            'quotation_number' => $quotationNumber,
            'client_id' => $data['client_id'],
            'lead_id' => $data['lead_id'] ?? null,
            'quotation_date' => $data['quotation_date'],
            'valid_until' => $data['valid_until'] ?? null,
            'project_title' => $data['project_title'],
            'website_type' => $data['website_type'],
            'website_type_other' => $data['website_type_other'] ?? null,
            'scope_description' => $data['scope_description'] ?? null,
            'amount' => $data['amount'],
            'gst_applicable' => $gstApplicable ? 1 : 0,
            'gst_rate' => $gstRate,
            'gst_amount' => $totals['gst_amount'],
            'total_amount' => $totals['total_amount'],
            'payment_terms' => $data['payment_terms'] ?? null,
            'terms_conditions' => $data['terms_conditions'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $db->lastInsertId();
        $db->commit();
        return $id;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/** Quotation number is never changed on edit — only the project-specific content updates, exactly like an invoice keeps its invoice_number across edits. */
function update_quotation(int $id, array $data): bool
{
    $gstApplicable = !empty($data['gst_applicable']);
    $gstRate = $gstApplicable ? (float) ($data['gst_rate'] ?? QUOTATION_DEFAULT_GST_RATE) : 0.0;
    $totals = compute_quotation_totals((float) $data['amount'], $gstApplicable, $gstRate);

    $stmt = db()->prepare(
        'UPDATE quotations SET
            client_id = :client_id,
            quotation_date = :quotation_date,
            valid_until = :valid_until,
            project_title = :project_title,
            website_type = :website_type,
            website_type_other = :website_type_other,
            scope_description = :scope_description,
            amount = :amount,
            gst_applicable = :gst_applicable,
            gst_rate = :gst_rate,
            gst_amount = :gst_amount,
            total_amount = :total_amount,
            payment_terms = :payment_terms,
            terms_conditions = :terms_conditions,
            notes = :notes,
            status = :status,
            updated_at = :now
         WHERE id = :id'
    );
    return $stmt->execute([
        'client_id' => $data['client_id'],
        'quotation_date' => $data['quotation_date'],
        'valid_until' => $data['valid_until'] ?? null,
        'project_title' => $data['project_title'],
        'website_type' => $data['website_type'],
        'website_type_other' => $data['website_type_other'] ?? null,
        'scope_description' => $data['scope_description'] ?? null,
        'amount' => $data['amount'],
        'gst_applicable' => $gstApplicable ? 1 : 0,
        'gst_rate' => $gstRate,
        'gst_amount' => $totals['gst_amount'],
        'total_amount' => $totals['total_amount'],
        'payment_terms' => $data['payment_terms'] ?? null,
        'terms_conditions' => $data['terms_conditions'] ?? null,
        'notes' => $data['notes'] ?? null,
        'status' => $data['status'] ?? 'draft',
        'now' => date('Y-m-d H:i:s'),
        'id' => $id,
    ]);
}

function find_quotation(int $id): array|false
{
    $stmt = db()->prepare(
        'SELECT quotations.*, clients.company_name AS client_name, clients.contact_name AS client_contact_name,
                clients.gstin AS client_gstin, clients.billing_address AS client_address,
                clients.email AS client_email, clients.mobile AS client_mobile
         FROM quotations
         LEFT JOIN clients ON clients.id = quotations.client_id
         WHERE quotations.id = :id AND quotations.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}

function soft_delete_quotation(int $id): bool
{
    return db()->prepare('UPDATE quotations SET deleted_at = :now WHERE id = :id')
        ->execute(['now' => date('Y-m-d H:i:s'), 'id' => $id]);
}

/** Quotations already created for a given client — used by the optional Lead -> Client -> Create Quotation prompt to show "View Quotation" instead of "Create Quotation" once one exists. */
function get_quotations_for_client(int $clientId): array
{
    $stmt = db()->prepare(
        'SELECT id, quotation_number, project_title, total_amount, status, quotation_date
         FROM quotations
         WHERE client_id = :client_id AND deleted_at IS NULL
         ORDER BY id DESC'
    );
    $stmt->execute(['client_id' => $clientId]);
    return $stmt->fetchAll();
}

/**
 * @param array{status?:string,search?:string} $filters
 * @return array{sql_where:string, params:array}
 */
function build_quotation_filters(array $filters): array
{
    $where = ['quotations.deleted_at IS NULL'];
    $params = [];

    if (!empty($filters['status'])) {
        $where[] = 'quotations.status = :status';
        $params['status'] = $filters['status'];
    }
    if (!empty($filters['search'])) {
        $where[] = '(quotations.quotation_number LIKE :search1 OR quotations.project_title LIKE :search2 OR clients.company_name LIKE :search3)';
        $searchTerm = '%' . $filters['search'] . '%';
        $params['search1'] = $searchTerm;
        $params['search2'] = $searchTerm;
        $params['search3'] = $searchTerm;
    }

    return ['sql_where' => implode(' AND ', $where), 'params' => $params];
}

function count_quotations(array $filters): int
{
    $built = build_quotation_filters($filters);
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM quotations LEFT JOIN clients ON clients.id = quotations.client_id WHERE {$built['sql_where']}"
    );
    $stmt->execute($built['params']);
    return (int) $stmt->fetchColumn();
}

function list_quotations(array $filters, int $limit, int $offset): array
{
    $built = build_quotation_filters($filters);
    $stmt = db()->prepare(
        "SELECT quotations.*, clients.company_name AS client_name
         FROM quotations
         LEFT JOIN clients ON clients.id = quotations.client_id
         WHERE {$built['sql_where']}
         ORDER BY quotations.id DESC
         LIMIT {$limit} OFFSET {$offset}"
    );
    $stmt->execute($built['params']);
    return $stmt->fetchAll();
}

/** Display label for a quotation's website type, including the free-text label when it's "Other". */
function quotation_website_type_label(array $quotation): string
{
    if ($quotation['website_type'] === 'other' && !empty($quotation['website_type_other'])) {
        return $quotation['website_type_other'];
    }
    return QUOTATION_WEBSITE_TYPES[$quotation['website_type']] ?? ucfirst((string) $quotation['website_type']);
}
