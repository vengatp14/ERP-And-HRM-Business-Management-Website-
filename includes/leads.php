<?php

declare(strict_types=1);

/**
 * includes/leads.php
 * Lead Management business logic: CRUD, timeline/activity logging, and
 * the automatic missed/cancelled lead transition described in the spec
 * ("if follow-up isn't updated, move to Missed; after N missed
 * attempts, move to Cancelled").
 */

function create_lead(array $data, int $createdBy): int
{
    $data['uuid'] = generate_uuid_v4();
    $data['created_by'] = $createdBy;
    $data['created_at'] = date('Y-m-d H:i:s');
    $data['updated_at'] = date('Y-m-d H:i:s');

    $columns = array_keys($data);
    $placeholders = array_map(static fn ($c) => ':' . $c, $columns);
    $sql = sprintf('INSERT INTO leads (%s) VALUES (%s)', implode(', ', $columns), implode(', ', $placeholders));

    $stmt = db()->prepare($sql);
    $stmt->execute($data);

    return (int) db()->lastInsertId();
}

function update_lead(int $id, array $data): bool
{
    // Capture status before the update so we can detect a fresh
    // new->won transition below (a lead that's already "won" being
    // saved again shouldn't create a second client).
    $before = find_lead($id);

    $data['updated_at'] = date('Y-m-d H:i:s');
    $assignments = implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", array_keys($data)));
    $stmt = db()->prepare("UPDATE leads SET {$assignments} WHERE id = :__id");
    $data['__id'] = $id;
    $result = $stmt->execute($data);

    if ($result && $before !== false && ($data['status'] ?? null) === 'won' && $before['status'] !== 'won') {
        convert_won_lead_to_client($id);
    }

    return $result;
}

/**
 * Auto-converts a freshly-won lead into a Client record, so a sales
 * rep doesn't have to manually re-type the same details into Clients
 * after closing the deal. Safe to call more than once — it checks for
 * an existing client linked via source_lead_id first (see
 * clients.source_lead_id in database/migration_003_clients.sql) and
 * skips if one's already there.
 */
function convert_won_lead_to_client(int $leadId): ?int
{
    if (find_client_by_source_lead($leadId) !== false) {
        return null;
    }

    $lead = find_lead($leadId);
    if ($lead === false) {
        return null;
    }

    $clientId = create_client([
        'company_name'    => $lead['company'] ?: $lead['client_name'],
        'contact_name'    => $lead['client_name'],
        'email'           => $lead['email'],
        'mobile'          => $lead['mobile'],
        'whatsapp'        => $lead['whatsapp'],
        'gstin'           => $lead['gst_number'],
        'pan_number'      => $lead['pan_number'],
        'billing_address' => $lead['address'],
        'source_lead_id'  => $leadId,
        'status'          => 'active',
        'notes'           => 'Auto-created from won lead #' . $leadId . ' (' . $lead['client_name'] . ').',
    ], (int) $lead['created_by']);

    log_lead_activity(
        $leadId,
        null,
        'status_change',
        'Lead marked Won — automatically added to Clients.'
    );

    if ($lead['assigned_to'] !== null) {
        create_notification(
            (int) $lead['assigned_to'],
            'general',
            "Lead converted to client: {$lead['client_name']}",
            'This lead was marked Won and automatically added to Clients.',
            'clients/view.php?id=' . $clientId
        );
    }

    return $clientId;
}

function find_lead(int $id): array|false
{
    $stmt = db()->prepare('SELECT * FROM leads WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}

function soft_delete_lead(int $id): bool
{
    $stmt = db()->prepare('UPDATE leads SET deleted_at = :now WHERE id = :id');
    return $stmt->execute(['now' => date('Y-m-d H:i:s'), 'id' => $id]);
}

/**
 * @param array{status?:string,priority?:string,source?:string,search?:string,assigned_to?:int} $filters
 * @return array{sql_where:string, params:array}
 */
function build_lead_filters(array $filters): array
{
    $where = ['leads.deleted_at IS NULL'];
    $params = [];

    if (!empty($filters['status'])) {
        $where[] = 'leads.status = :status';
        $params['status'] = $filters['status'];
    }
    if (!empty($filters['priority'])) {
        $where[] = 'leads.priority = :priority';
        $params['priority'] = $filters['priority'];
    }
    if (!empty($filters['source'])) {
        $where[] = 'leads.source = :source';
        $params['source'] = $filters['source'];
    }
    if (!empty($filters['assigned_to'])) {
        $where[] = 'leads.assigned_to = :assigned_to';
        $params['assigned_to'] = $filters['assigned_to'];
    }
    if (!empty($filters['search'])) {
        $where[] = '(leads.client_name LIKE :search1 OR leads.company LIKE :search2 OR leads.mobile LIKE :search3 OR leads.email LIKE :search4)';
        $searchTerm = '%' . $filters['search'] . '%';
        $params['search1'] = $searchTerm;
        $params['search2'] = $searchTerm;
        $params['search3'] = $searchTerm;
        $params['search4'] = $searchTerm;
    }

    return ['sql_where' => implode(' AND ', $where), 'params' => $params];
}

function count_leads(array $filters): int
{
    ['sql_where' => $where, 'params' => $params] = build_lead_filters($filters);
    $stmt = db()->prepare("SELECT COUNT(*) AS cnt FROM leads WHERE {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetch()['cnt'];
}

function list_leads(array $filters, int $limit, int $offset): array
{
    ['sql_where' => $where, 'params' => $params] = build_lead_filters($filters);
    $stmt = db()->prepare(
        "SELECT leads.*, u.full_name AS assigned_to_name
         FROM leads
         LEFT JOIN users u ON u.id = leads.assigned_to
         WHERE {$where}
         ORDER BY leads.created_at DESC
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

// ---------------------------------------------------------------------
// Timeline / activities
// ---------------------------------------------------------------------

function log_lead_activity(
    int $leadId,
    ?int $userId,
    string $type,
    ?string $content = null,
    ?string $clientResponse = null,
    ?string $nextFollowUpAt = null
): int {
    $stmt = db()->prepare(
        'INSERT INTO lead_activities (lead_id, user_id, type, content, client_response, next_follow_up_at, created_at)
         VALUES (:lead_id, :user_id, :type, :content, :response, :next_followup, :now)'
    );
    $stmt->execute([
        'lead_id' => $leadId,
        'user_id' => $userId,
        'type' => $type,
        'content' => $content,
        'response' => $clientResponse,
        'next_followup' => $nextFollowUpAt,
        'now' => date('Y-m-d H:i:s'),
    ]);

    if ($nextFollowUpAt !== null) {
        update_lead($leadId, ['next_follow_up_at' => $nextFollowUpAt]);
    }

    return (int) db()->lastInsertId();
}

function get_lead_activities(int $leadId): array
{
    $stmt = db()->prepare(
        'SELECT la.*, u.full_name AS user_name
         FROM lead_activities la
         LEFT JOIN users u ON u.id = la.user_id
         WHERE la.lead_id = :lead_id
         ORDER BY la.created_at DESC'
    );
    $stmt->execute(['lead_id' => $leadId]);
    return $stmt->fetchAll();
}

/** Fetch one activity row, or false if it doesn't exist. */
function find_lead_activity(int $activityId): array|false
{
    $stmt = db()->prepare('SELECT * FROM lead_activities WHERE id = :id');
    $stmt->execute(['id' => $activityId]);
    return $stmt->fetch();
}

/**
 * Edits a logged follow-up's description/response/next-follow-up date.
 * Before overwriting, the current values are archived into
 * lead_activity_history so there's a full "what changed, when, by whom"
 * trail — then updated_at is stamped with the edit time.
 */
function update_lead_activity(int $activityId, int $userId, array $data): bool
{
    $current = find_lead_activity($activityId);
    if ($current === false) {
        return false;
    }

    $now = date('Y-m-d H:i:s');

    $histStmt = db()->prepare(
        'INSERT INTO lead_activity_history
        (activity_id, old_content, old_client_response, old_next_follow_up_at, changed_by, changed_at)
        VALUES (:activity_id, :old_content, :old_response, :old_next, :changed_by, :changed_at)'
    );
    $histStmt->execute([
        'activity_id' => $activityId,
        'old_content' => $current['content'],
        'old_response' => $current['client_response'],
        'old_next' => $current['next_follow_up_at'],
        'changed_by' => $userId,
        'changed_at' => $now,
    ]);

    $stmt = db()->prepare(
        'UPDATE lead_activities
         SET content = :content, client_response = :response, next_follow_up_at = :next_followup, updated_at = :now
         WHERE id = :id'
    );

    return $stmt->execute([
        'content' => $data['content'] ?? null,
        'response' => $data['client_response'] ?? null,
        'next_followup' => $data['next_follow_up_at'] ?? null,
        'now' => $now,
        'id' => $activityId,
    ]);
}

/** Prior versions of a follow-up, most recent edit first. */
function get_lead_activity_history(int $activityId): array
{
    $stmt = db()->prepare(
        'SELECT h.*, u.full_name AS changed_by_name
         FROM lead_activity_history h
         LEFT JOIN users u ON u.id = h.changed_by
         WHERE h.activity_id = :activity_id
         ORDER BY h.changed_at DESC'
    );
    $stmt->execute(['activity_id' => $activityId]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------
// Documents
// ---------------------------------------------------------------------

function add_lead_document(int $leadId, ?int $uploadedBy, array $uploadResult): int
{
    $stmt = db()->prepare(
        'INSERT INTO lead_documents (lead_id, uploaded_by, original_filename, stored_filename, file_size_bytes, mime_type, created_at)
         VALUES (:lead_id, :uploaded_by, :original, :stored, :size, :mime, :now)'
    );
    $stmt->execute([
        'lead_id' => $leadId,
        'uploaded_by' => $uploadedBy,
        'original' => $uploadResult['original_filename'],
        'stored' => $uploadResult['stored_filename'],
        'size' => $uploadResult['size'],
        'mime' => $uploadResult['mime'],
        'now' => date('Y-m-d H:i:s'),
    ]);
    return (int) db()->lastInsertId();
}

function get_lead_documents(int $leadId): array
{
    $stmt = db()->prepare('SELECT * FROM lead_documents WHERE lead_id = :lead_id ORDER BY created_at DESC');
    $stmt->execute(['lead_id' => $leadId]);
    return $stmt->fetchAll();
}

function find_lead_document(int $documentId): array|false
{
    $stmt = db()->prepare('SELECT * FROM lead_documents WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $documentId]);
    return $stmt->fetch();
}

// ---------------------------------------------------------------------
// Missed / cancelled lead automation
//
// Spec: "If follow-up is not updated, move automatically to Missed
// Leads. After configured missed attempts, move to Cancelled Leads."
//
// There's no cron in this environment, so this runs on-demand — called
// both from a visible "Process Missed Leads" admin action and
// opportunistically whenever the lead list is viewed, so it stays
// accurate without needing external scheduling. In a real deployment,
// wire this same function to a cron job (e.g. every 15 minutes) instead.
// ---------------------------------------------------------------------

function process_missed_leads(int $maxMissedAttemptsBeforeCancel = 3): array
{
    $now = date('Y-m-d H:i:s');

    // "Missed follow-up" means exactly one thing: a Next Follow-up
    // date/time was set on the lead, and that date/time has passed.
    // The Deadline field is separate and is NOT used here — a lead
    // with no Next Follow-up set is simply not due for one yet, so
    // it never gets auto-flagged just because its deadline passed.
    // Terminal statuses (won/lost/cancelled) are always excluded, but
    // 'missed' stays in scope so it keeps getting re-checked day after
    // day (see the DATE(...) < CURDATE() guard below) instead of being
    // frozen on its first missed date forever.
    $stmt = db()->prepare(
        "SELECT id, missed_attempts, client_name, assigned_to FROM leads
         WHERE deleted_at IS NULL
           AND status NOT IN ('won','lost','cancelled')
           AND next_follow_up_at IS NOT NULL
           AND next_follow_up_at < :now
           AND DATE(next_follow_up_at) < CURDATE()"
    );
    $stmt->execute(['now' => $now]);
    $overdue = $stmt->fetchAll();

    $missedCount = 0;
    $cancelledCount = 0;

    foreach ($overdue as $lead) {
        $newAttempts = (int) $lead['missed_attempts'] + 1;
        $newStatus = $newAttempts >= $maxMissedAttemptsBeforeCancel ? 'cancelled' : 'missed';

        $updateData = ['status' => $newStatus, 'missed_attempts' => $newAttempts];
        if ($newStatus === 'missed') {
            // Roll the follow-up date/time forward to right now, so this
            // lead reappears on TODAY's date on the calendar (still red/
            // missed) instead of staying stuck on the date it was
            // originally due. The DATE(...) < CURDATE() guard above means
            // this only happens once per calendar day even though this
            // function runs on every page view.
            $updateData['next_follow_up_at'] = $now;
        }

        update_lead((int) $lead['id'], $updateData);
        log_lead_activity(
            (int) $lead['id'],
            null,
            'status_change',
            $newStatus === 'cancelled'
                ? "Automatically cancelled after {$newAttempts} missed follow-up attempts."
                : 'Automatically marked as missed — scheduled follow-up passed without an update.'
        );

        if ($newStatus === 'cancelled') {
            $cancelledCount++;
        } else {
            $missedCount++;
            notify_missed_lead((int) $lead['id'], $lead['client_name'], $lead['assigned_to'] !== null ? (int) $lead['assigned_to'] : null);
        }
    }

    return ['missed' => $missedCount, 'cancelled' => $cancelledCount, 'checked' => count($overdue)];
}

// ---------------------------------------------------------------------
// Calendar widget (see includes/calendar.php)
// ---------------------------------------------------------------------

/**
 * Builds calendar events (one per lead with a next_follow_up_at in the
 * given month, plus one per lead with a buffer_date in the given month)
 * for the shared calendar widget on leads/index.php.
 * Colour follows the lead's own status: missed/cancelled -> missed,
 * an already-progressed lead -> done, anything else -> upcoming.
 * Buffer date events are always tagged 'cancelled' colour (grey) so
 * they're visually distinct from the main follow-up date on the same
 * calendar.
 */
function get_lead_calendar_events(string $monthStart, string $monthEnd, ?int $assignedUserId = null): array
{
    $sql = "SELECT id, client_name, company, mobile, status, next_follow_up_at FROM leads
          WHERE deleted_at IS NULL
            AND next_follow_up_at IS NOT NULL
            AND DATE(next_follow_up_at) BETWEEN :start AND :end";
    $params = ['start' => $monthStart, 'end' => $monthEnd];

    // Employees only see follow-ups for leads assigned to them on the
    // calendar widget — same scoping as the list view. Admins pass null.
    if ($assignedUserId !== null) {
        $sql .= ' AND assigned_to = :assigned_uid';
        $params['assigned_uid'] = $assignedUserId;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $events = [];
    foreach ($stmt->fetchAll() as $row) {
        if (in_array($row['status'], ['missed', 'cancelled'], true)) {
            $status = 'missed';
        } elseif (in_array($row['status'], ['won', 'contacted', 'qualified', 'proposal', 'negotiation'], true)) {
            $status = 'done';
        } else {
            $status = 'upcoming';
        }

        $events[] = [
            'date' => date('Y-m-d', strtotime($row['next_follow_up_at'])),
            'initials' => mb_substr($row['client_name'], 0, 2),
            'title' => $row['client_name'] . ($row['company'] ? ' — ' . $row['company'] : ''),
            'subtitle' => 'Contact by ' . date('h:i A', strtotime($row['next_follow_up_at'])) . ' · ' . ucfirst($row['status']),
            'phone' => $row['mobile'],
            'status' => $status,
            'url' => url('leads/view.php?id=' . $row['id']),
        ];
    }

    // Buffer date is a separate grace-period date (see leads/form.php)
    // independent of next_follow_up_at, so it needs its own query —
    // a lead can have both dates fall in the same visible month.
    $bufferSql = "SELECT id, client_name, company, mobile, status, buffer_date FROM leads
          WHERE deleted_at IS NULL
            AND buffer_date IS NOT NULL
            AND buffer_date BETWEEN :start AND :end";
    $bufferParams = ['start' => $monthStart, 'end' => $monthEnd];

    if ($assignedUserId !== null) {
        $bufferSql .= ' AND assigned_to = :assigned_uid';
        $bufferParams['assigned_uid'] = $assignedUserId;
    }

    $bufferStmt = db()->prepare($bufferSql);
    $bufferStmt->execute($bufferParams);

    foreach ($bufferStmt->fetchAll() as $row) {
        $events[] = [
            'date' => $row['buffer_date'],
            'initials' => mb_substr($row['client_name'], 0, 2),
            'title' => $row['client_name'] . ($row['company'] ? ' — ' . $row['company'] : '') . ' (Buffer)',
            'subtitle' => 'Buffer Date · ' . ucfirst($row['status']),
            'phone' => $row['mobile'],
            'status' => 'buffer',
            'url' => url('leads/view.php?id=' . $row['id']),
        ];
    }

    // Deadline is another independent date on the lead (see
    // leads/form.php) — same pattern as buffer_date above, its own
    // query since a lead can have follow-up/buffer/deadline dates all
    // fall on different days within the same visible month.
    $deadlineSql = "SELECT id, client_name, company, mobile, status, deadline FROM leads
          WHERE deleted_at IS NULL
            AND deadline IS NOT NULL
            AND deadline BETWEEN :start AND :end";
    $deadlineParams = ['start' => $monthStart, 'end' => $monthEnd];

    if ($assignedUserId !== null) {
        $deadlineSql .= ' AND assigned_to = :assigned_uid';
        $deadlineParams['assigned_uid'] = $assignedUserId;
    }

    $deadlineStmt = db()->prepare($deadlineSql);
    $deadlineStmt->execute($deadlineParams);

    foreach ($deadlineStmt->fetchAll() as $row) {
        $events[] = [
            'date' => $row['deadline'],
            'initials' => mb_substr($row['client_name'], 0, 2),
            'title' => $row['client_name'] . ($row['company'] ? ' — ' . $row['company'] : '') . ' (Deadline)',
            'subtitle' => 'Deadline · ' . ucfirst($row['status']),
            'phone' => $row['mobile'],
            'status' => 'deadline',
            'url' => url('leads/view.php?id=' . $row['id']),
        ];
    }


    return $events;
}

// ---------------------------------------------------------------------
// Display helpers
// ---------------------------------------------------------------------

function lead_status_badge_class(string $status): string
{
    return match ($status) {
        'new' => 'text-bg-info',
        'contacted' => 'text-bg-primary',
        'qualified' => 'text-bg-primary',
        'proposal' => 'text-bg-warning',
        'negotiation' => 'text-bg-warning',
        'won' => 'text-bg-success',
        'lost' => 'text-bg-secondary',
        'missed' => 'text-bg-danger',
        'cancelled' => 'text-bg-dark',
        default => 'text-bg-secondary',
    };
}

function lead_priority_badge_class(string $priority): string
{
    return match ($priority) {
        'high' => 'text-bg-danger',
        'medium' => 'text-bg-warning',
        'low' => 'text-bg-secondary',
        default => 'text-bg-secondary',
    };
}
