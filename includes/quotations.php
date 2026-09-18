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

/** Default "Project Scope / Features / Implementation" starting point per website type — prefilled into the form for a new quotation, and also used by quotations/view.php as the printed fallback whenever a quotation's own scope_description is empty (see requirement: the full quotation content should always show, only the project-specific values differ). */
const QUOTATION_DEFAULT_SCOPE_BY_TYPE = [
    'static' => "01. PAGES & DESIGN\n- Number of pages: \n- Responsive design (mobile, tablet, desktop)\n- Contact form\n\n02. SEO & DEPLOYMENT\n- Basic SEO setup\n- Hosting/deployment assistance",
    'dynamic' => "01. ADMIN & CONTENT MANAGEMENT\n- Admin panel\n- Database-driven content\n- Dynamic content management\n\n02. USER MANAGEMENT\n- User management (if applicable)\n\n03. RESPONSIVE DESIGN\n- Responsive design (mobile, tablet, desktop)",
    'ecommerce' => "01. PREMIUM UI & RESPONSIVE DESIGN\nModern and premium storefront UI optimized for:\n- Mobile\n- Tablet\n- Desktop\n\n02. PRODUCT & INVENTORY MANAGEMENT\n- Product listing and management\n- Categories\n- Product variants\n- Pricing\n- Discounts\n- Stock management\n\n03. PAYMENT GATEWAY SETUP\n- Online payment gateway configuration\n- Payment processing flow\n- Payment and order confirmation flow\n\n04. COURIER & SHIPPING SETUP\n- Shipping configuration\n- Fulfillment workflow\n- Order tracking setup\n\n05. ORDER MANAGEMENT\n- Order dashboard\n- Order status management\n- Payment status\n- Fulfillment status\n- Order tracking\n\n06. CUSTOMER / USER MANAGEMENT\n- Customer registration\n- Login\n- Customer profiles\n- Order history\n\n07. BUSINESS WORKFLOW\n- Pricing and discount management\n- Business records\n- Basic quotation / invoice workflow where supported",
    'crm_application' => "01. CORE MODULES\n- Relevant modules (as agreed)\n\n02. USER ROLES & PERMISSIONS\n- User roles and permissions\n\n03. REPORTS & DASHBOARD\n- Reports and dashboard\n\n04. OTHER FUNCTIONALITY\n- Other project-specific functionality",
    'other' => '',
];


const QUOTATION_DEFAULT_TERMS = "Payment gateway fees, third-party subscription/license charges, and domain/hosting renewal charges are excluded unless specifically mentioned in writing.\nThe client must provide the required content, images, and any credentials/access needed for the project.\nThe project will be developed according to the agreed Scope of Work above.\nNew features, additional functionality, or requirements outside the approved Scope of Work will be quoted and charged separately.\nAny additional cost will be communicated and approved before the additional work begins.\nThis quotation is valid until the date mentioned above.";

const QUOTATION_DEFAULT_GST_RATE = 18.00;

/**
 * Fixed clauses that appear, worded the same way, on every quotation the
 * company sends out (mirrors the printed letterhead format in
 * quotations/view.php — see PROJECT OVERVIEW / DEVELOPMENT APPROACH /
 * DEVELOPMENT SUPPORT & FUNCTIONALITY WARRANTY / PAYMENT PROCESS below).
 * Unlike scope_description/payment_terms/terms_conditions these are not
 * stored per quotation — there is nothing project-specific about them —
 * so they live here as constants rather than editable columns.
 */
const QUOTATION_DEVELOPMENT_APPROACH_TEXT = "The website will be developed according to the agreed Scope of Work mentioned in this quotation.\nAny functionality specifically included within the approved Scope of Work will be implemented as agreed.\nThe development team will work on the project according to the requirements finalized before development begins.";

const QUOTATION_WARRANTY_TEXT = "PRE-DEVELOPED FUNCTIONALITIES\nAll pre-developed functionalities included within the agreed Scope of Work will be covered for a period of one (1) year from the date of final deployment.\nIf an error or technical issue occurs in any of the agreed pre-developed functionalities during this support period, we will review and fix the issue for that specific functionality at no additional development charge, provided that the issue is related to the original implemented functionality.\n\nIMPORTANT\nThis support applies only to the originally developed and agreed functionalities included in the project Scope of Work.\nThe support does not include new features, new functionality, major changes, redesigns, third-party changes, or enhancements requested after the original development.";

/** Type-specific "the completed website will undergo" testing checklist — ecommerce gets the payment/order/shipping flow checks called out in the company's e-commerce quotation format; other project types get a shorter, generic list. */
function quotation_testing_checklist(array $quotation): array
{
    if ($quotation['website_type'] === 'ecommerce') {
        return ['Responsive testing', 'Payment-flow testing', 'Order-flow testing', 'Shipping-flow testing', 'Final deployment'];
    }
    if ($quotation['website_type'] === 'crm_application') {
        return ['Functionality testing', 'User role & permission testing', 'Data/reports testing', 'Final deployment'];
    }
    return ['Responsive testing', 'Functionality testing', 'Final deployment'];
}

/** "PAYMENT TERMS & DEPLOYMENT PROCESS" narrative — same wording as the company's printed quotation, kept percentage-neutral since the actual advance/balance split (shown just above this, and under Project Investment) comes from the quotation's own payment_terms field rather than being hardcoded here. */
const QUOTATION_PAYMENT_PROCESS_TEXT = "The initial advance payment is required before project commencement.\nDevelopment & Testing: After receiving the advance payment and required project materials, development will begin according to the agreed Scope of Work. The completed website will be tested before final deployment.\nDomain Connection & Final Payment: After completion of development and testing, the website will be prepared for domain connection and final deployment. The remaining payment must be settled before the website is made live on the client's domain.\nFinal Deployment: Once the remaining payment is received, the website will be connected to the domain and the final live deployment will be completed.";

/** "PROJECT OVERVIEW" paragraph — same wording as the company's printed quotation, with the project type filled in from existing quotation data. */
function quotation_default_overview(array $quotation): string
{
    return sprintf(
        "We will design and develop a professional, responsive, and user-friendly %s based on the agreed requirements and Scope of Work.\nThe complete development will be carried out according to the features and functionalities mentioned in this quotation.\nOur team will handle the website development, configuration, testing, and final deployment to ensure the agreed requirements are properly implemented.",
        quotation_website_type_label($quotation)
    );
}

/**
 * "PROJECT DURATION" — the company's printed format always states this
 * (e.g. "30 Days / 1 Month"). There's no dedicated duration field on the
 * quotation, so when quotation_date/valid_until are both set it's derived
 * from that existing pair; otherwise it falls back to the company's usual
 * default turnaround so this line — like the rest of the printed
 * quotation — is never simply blank.
 */
function quotation_duration_label(array $quotation): string
{
    if (!empty($quotation['valid_until']) && !empty($quotation['quotation_date'])) {
        $start = new DateTime($quotation['quotation_date']);
        $end = new DateTime($quotation['valid_until']);
        $days = (int) $start->diff($end)->days;
        if ($days > 0) {
            $label = $days . ' Day' . ($days === 1 ? '' : 's');
            if ($days % 30 === 0) {
                $months = intdiv($days, 30);
                $label .= ' / ' . $months . ' Month' . ($months === 1 ? '' : 's');
            }
            return $label;
        }
    }
    return '30 Days / 1 Month';
}

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
