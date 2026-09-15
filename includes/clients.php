<?php

declare(strict_types=1);

/**
 * includes/clients.php
 * Client Management business logic: CRUD plus contacts/notes timeline.
 * Mirrors the structure of includes/leads.php for consistency.
 */

function create_client(array $data, int $createdBy): int
{
    $data['uuid'] = generate_uuid_v4();
    $data['created_by'] = $createdBy;
    $data['created_at'] = date('Y-m-d H:i:s');
    $data['updated_at'] = date('Y-m-d H:i:s');

    $columns = array_keys($data);
    $placeholders = array_map(static fn ($c) => ':' . $c, $columns);
    $sql = sprintf('INSERT INTO clients (%s) VALUES (%s)', implode(', ', $columns), implode(', ', $placeholders));

    $stmt = db()->prepare($sql);
    $stmt->execute($data);

    return (int) db()->lastInsertId();
}

function update_client(int $id, array $data): bool
{
    $data['updated_at'] = date('Y-m-d H:i:s');
    $assignments = implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", array_keys($data)));
    $stmt = db()->prepare("UPDATE clients SET {$assignments} WHERE id = :__id");
    $data['__id'] = $id;
    return $stmt->execute($data);
}

function find_client(int $id): array|false
{
    $stmt = db()->prepare(
        'SELECT clients.*, au.full_name AS assigned_to_name
         FROM clients
         LEFT JOIN users au ON au.id = clients.assigned_to
         WHERE clients.id = :id AND clients.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}

/** Finds the client (if any) that was auto/manually linked to a given lead via source_lead_id — used to avoid duplicate client records when a lead is marked Won more than once. */
function find_client_by_source_lead(int $leadId): array|false
{
    $stmt = db()->prepare('SELECT * FROM clients WHERE source_lead_id = :lead_id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute(['lead_id' => $leadId]);
    return $stmt->fetch();
}

function soft_delete_client(int $id): bool
{
    $stmt = db()->prepare('UPDATE clients SET deleted_at = :now WHERE id = :id');
    return $stmt->execute(['now' => date('Y-m-d H:i:s'), 'id' => $id]);
}

/**
 * @param array{status?:string,search?:string,assigned_user_id?:int} $filters
 * @return array{sql_where:string, params:array}
 */
function build_client_filters(array $filters): array
{
    $where = ['clients.deleted_at IS NULL'];
    $params = [];

    if (!empty($filters['status'])) {
        $where[] = 'clients.status = :status';
        $params['status'] = $filters['status'];
    }
    if (!empty($filters['search'])) {
        $where[] = '(clients.company_name LIKE :search1 OR clients.contact_name LIKE :search2 OR clients.mobile LIKE :search3 OR clients.email LIKE :search4 OR clients.gstin LIKE :search5)';
        $searchTerm = '%' . $filters['search'] . '%';
        $params['search1'] = $searchTerm;
        $params['search2'] = $searchTerm;
        $params['search3'] = $searchTerm;
        $params['search4'] = $searchTerm;
        $params['search5'] = $searchTerm;
    }
    // Restricts the list to clients directly assigned to a specific user
    // via clients.assigned_to. Used to scope plain 'employee' accounts to
    // only their own clients — admins never pass this filter.
    if (!empty($filters['assigned_user_id'])) {
        $where[] = 'clients.assigned_to = :assigned_uid1';
        $params['assigned_uid1'] = $filters['assigned_user_id'];
    }

    return ['sql_where' => implode(' AND ', $where), 'params' => $params];
}

function count_clients(array $filters): int
{
    ['sql_where' => $where, 'params' => $params] = build_client_filters($filters);
    $stmt = db()->prepare("SELECT COUNT(*) AS cnt FROM clients WHERE {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetch()['cnt'];
}

function list_clients(array $filters, int $limit, int $offset): array
{
    ['sql_where' => $where, 'params' => $params] = build_client_filters($filters);
    $stmt = db()->prepare(
        "SELECT clients.*, u.full_name AS created_by_name, au.full_name AS assigned_to_name
         FROM clients
         LEFT JOIN users u ON u.id = clients.created_by
         LEFT JOIN users au ON au.id = clients.assigned_to
         WHERE {$where}
         ORDER BY clients.created_at DESC
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

/** Simple list for <select> dropdowns used by other modules (Projects, Billing). */
function list_active_clients_for_select(): array
{
    $stmt = db()->query(
        "SELECT id, company_name, gstin FROM clients WHERE deleted_at IS NULL AND status = 'active' ORDER BY company_name ASC"
    );
    return $stmt->fetchAll();
}

/**
 * True if this client is directly assigned to the user via
 * clients.assigned_to. Same "assigned to me" rule as list_clients()'s
 * assigned_user_id filter — used to guard direct links (clients/view.php)
 * the same way projects/view.php guards against a plain employee opening
 * someone else's project by URL.
 */
function user_is_assigned_to_client(int $clientId, int $userId): bool
{
    $stmt = db()->prepare(
        'SELECT 1
         FROM clients
         WHERE clients.id = :client_id
           AND clients.deleted_at IS NULL
           AND clients.assigned_to = :uid1
         LIMIT 1'
    );
    $stmt->execute(['client_id' => $clientId, 'uid1' => $userId]);
    return $stmt->fetch() !== false;
}

// ---------------------------------------------------------------------
// Additional contacts
// ---------------------------------------------------------------------

function add_client_contact(int $clientId, array $data): int
{
    $stmt = db()->prepare(
        'INSERT INTO client_contacts (client_id, name, designation, email, mobile, created_at)
         VALUES (:client_id, :name, :designation, :email, :mobile, :now)'
    );
    $stmt->execute([
        'client_id' => $clientId,
        'name' => $data['name'],
        'designation' => $data['designation'] ?? null,
        'email' => $data['email'] ?? null,
        'mobile' => $data['mobile'] ?? null,
        'now' => date('Y-m-d H:i:s'),
    ]);
    return (int) db()->lastInsertId();
}

function get_client_contacts(int $clientId): array
{
    $stmt = db()->prepare('SELECT * FROM client_contacts WHERE client_id = :client_id ORDER BY created_at ASC');
    $stmt->execute(['client_id' => $clientId]);
    return $stmt->fetchAll();
}

function delete_client_contact(int $contactId, int $clientId): bool
{
    $stmt = db()->prepare('DELETE FROM client_contacts WHERE id = :id AND client_id = :client_id');
    return $stmt->execute(['id' => $contactId, 'client_id' => $clientId]);
}

// ---------------------------------------------------------------------
// Notes timeline
// ---------------------------------------------------------------------

function add_client_note(int $clientId, ?int $userId, string $content): int
{
    $stmt = db()->prepare(
        'INSERT INTO client_notes (client_id, user_id, content, created_at) VALUES (:client_id, :user_id, :content, :now)'
    );
    $stmt->execute([
        'client_id' => $clientId,
        'user_id' => $userId,
        'content' => $content,
        'now' => date('Y-m-d H:i:s'),
    ]);
    return (int) db()->lastInsertId();
}

function get_client_notes(int $clientId): array
{
    $stmt = db()->prepare(
        'SELECT cn.*, u.full_name AS user_name
         FROM client_notes cn
         LEFT JOIN users u ON u.id = cn.user_id
         WHERE cn.client_id = :client_id
         ORDER BY cn.created_at DESC'
    );
    $stmt->execute(['client_id' => $clientId]);
    return $stmt->fetchAll();
}

/** Fetch one note row, or false if it doesn't exist. */
function find_client_note(int $noteId): array|false
{
    $stmt = db()->prepare('SELECT * FROM client_notes WHERE id = :id');
    $stmt->execute(['id' => $noteId]);
    return $stmt->fetch();
}

/**
 * Edits a client note/description. Before overwriting, the current
 * text is archived into client_note_history so there's a full
 * "what it said before, when, by whom" trail — then updated_at is
 * stamped with the edit time.
 */
function update_client_note(int $noteId, int $userId, string $content): bool
{
    $current = find_client_note($noteId);
    if ($current === false) {
        return false;
    }

    $now = date('Y-m-d H:i:s');

    $histStmt = db()->prepare(
        'INSERT INTO client_note_history (note_id, old_content, changed_by, changed_at)
         VALUES (:note_id, :old_content, :changed_by, :changed_at)'
    );
    $histStmt->execute([
        'note_id' => $noteId,
        'old_content' => $current['content'],
        'changed_by' => $userId,
        'changed_at' => $now,
    ]);

    $stmt = db()->prepare('UPDATE client_notes SET content = :content, updated_at = :now WHERE id = :id');
    return $stmt->execute(['content' => $content, 'now' => $now, 'id' => $noteId]);
}

/** Prior versions of a note, most recent edit first. */
function get_client_note_history(int $noteId): array
{
    $stmt = db()->prepare(
        'SELECT h.*, u.full_name AS changed_by_name
         FROM client_note_history h
         LEFT JOIN users u ON u.id = h.changed_by
         WHERE h.note_id = :note_id
         ORDER BY h.changed_at DESC'
    );
    $stmt->execute(['note_id' => $noteId]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------
// Branding (logo / seal / signature) — shown on the client's side of
// the GST invoice print view, alongside the company's own branding.
// Reuses handle_upload()'s 'images' category (jpg/jpeg/png/webp, 10MB
// cap, MIME-sniffed) so the same validation/storage rules apply as any
// other upload in the app. Files live under uploads/images/ and are
// only ever served through clients/branding-download.php (never a
// direct URL — matches the rest of uploads/, see uploads/.htaccess).
// ---------------------------------------------------------------------

const CLIENT_BRANDING_TYPES = ['logo', 'seal', 'signature'];

/**
 * Uploads and saves one branding image (logo/seal/signature) for a
 * client, deleting the previous file for that slot if one existed.
 * Returns true on success, or a string error message on failure.
 */
function update_client_branding_file(int $clientId, string $type, array $file): bool|string
{
    if (!in_array($type, CLIENT_BRANDING_TYPES, true)) {
        return 'Invalid branding type.';
    }

    $result = handle_upload($file, 'images');
    if (!$result['ok']) {
        return $result['error'];
    }

    $client = find_client($clientId);
    if ($client !== false && !empty($client["{$type}_stored_filename"])) {
        $oldPath = dirname(__DIR__) . '/uploads/images/' . $client["{$type}_stored_filename"];
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $stmt = db()->prepare(
        "UPDATE clients SET {$type}_stored_filename = :stored, {$type}_original_filename = :original,
                {$type}_mime = :mime, updated_at = :now WHERE id = :id"
    );
    $stmt->execute([
        'stored' => $result['stored_filename'],
        'original' => $result['original_filename'],
        'mime' => $result['mime'],
        'now' => date('Y-m-d H:i:s'),
        'id' => $clientId,
    ]);

    return true;
}

/** Removes a client's branding image (logo/seal/signature) for one slot, if set. */
function remove_client_branding_file(int $clientId, string $type): bool
{
    if (!in_array($type, CLIENT_BRANDING_TYPES, true)) {
        return false;
    }

    $client = find_client($clientId);
    if ($client === false || empty($client["{$type}_stored_filename"])) {
        return false;
    }

    $path = dirname(__DIR__) . '/uploads/images/' . $client["{$type}_stored_filename"];
    if (is_file($path)) {
        @unlink($path);
    }

    $stmt = db()->prepare(
        "UPDATE clients SET {$type}_stored_filename = NULL, {$type}_original_filename = NULL,
                {$type}_mime = NULL, updated_at = :now WHERE id = :id"
    );
    return $stmt->execute(['now' => date('Y-m-d H:i:s'), 'id' => $clientId]);
}

// ---------------------------------------------------------------------
// Display helpers
// ---------------------------------------------------------------------

function client_status_badge_class(string $status): string
{
    return match ($status) {
        'active' => 'text-bg-success',
        'inactive' => 'text-bg-secondary',
        'completed' => 'text-bg-primary',
        'cancelled' => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
}

/** The full list of valid client status values, for form/filter/validation use. */
function client_status_options(): array
{
    return ['active', 'inactive', 'completed', 'cancelled'];
}

// ---------------------------------------------------------------------
// Next-contact date tracking + calendar widget (see includes/calendar.php)
// ---------------------------------------------------------------------

/** Marks a client as contacted today and clears the "missed" flag, mirroring the meeting "mark held" action. */
function mark_client_contacted(int $clientId): bool
{
    $now = date('Y-m-d H:i:s');
    $stmt = db()->prepare(
        "UPDATE clients SET contact_status = 'contacted', last_contacted_at = :now1, updated_at = :now2 WHERE id = :id"
    );
    return $stmt->execute(['now1' => $now, 'now2' => $now, 'id' => $clientId]);
}

/**
 * Same on-demand pattern as process_missed_leads() / process_missed_meetings():
 * any client whose next_contact_at has passed while still 'pending' (or
 * still unresolved 'missed' from an earlier day) gets flagged 'missed'
 * and its next_contact_at is rolled forward to right now, so it keeps
 * reappearing on TODAY's date on the calendar instead of staying stuck
 * on the date it was originally due. The DATE(...) < CURDATE() guard
 * means this only happens once per calendar day even though this
 * function runs on every page view. Called opportunistically from
 * clients/index.php on every list view.
 */
function process_missed_clients(): array
{
    $now = date('Y-m-d H:i:s');
    $stmt = db()->prepare(
        "SELECT id, company_name FROM clients
          WHERE deleted_at IS NULL
            AND contact_status IN ('pending','missed')
            AND next_contact_at IS NOT NULL
            AND next_contact_at < :now
            AND DATE(next_contact_at) < CURDATE()"
    );
    $stmt->execute(['now' => $now]);
    $overdue = $stmt->fetchAll();

    foreach ($overdue as $client) {
        db()->prepare(
            "UPDATE clients SET contact_status = 'missed', next_contact_at = :now1, updated_at = :now2 WHERE id = :id"
        )->execute(['now1' => $now, 'now2' => $now, 'id' => $client['id']]);
        notify_missed_client_contact((int) $client['id'], $client['company_name']);
    }

    return ['missed' => count($overdue), 'checked' => count($overdue)];
}

function client_contact_status_badge_class(string $status): string
{
    return match ($status) {
        'contacted' => 'text-bg-success',
        'missed' => 'text-bg-danger',
        default => 'text-bg-primary',
    };
}

/**
 * Builds calendar events (one per client with a next_contact_at in the
 * given month) for the shared calendar widget on clients/index.php.
 */
function get_client_calendar_events(string $monthStart, string $monthEnd, ?int $assignedUserId = null): array
{
    $sql = "SELECT clients.id, clients.company_name, clients.contact_name, clients.mobile,
                   clients.contact_status, clients.next_contact_at
              FROM clients
             WHERE clients.deleted_at IS NULL
               AND clients.next_contact_at IS NOT NULL
               AND DATE(clients.next_contact_at) BETWEEN :start AND :end";
    $params = ['start' => $monthStart, 'end' => $monthEnd];

    // Employees only see follow-ups for clients directly assigned to
    // them on the calendar widget — same scoping as the list view.
    // Admins pass null.
    if ($assignedUserId !== null) {
        $sql .= ' AND clients.assigned_to = :assigned_uid1';
        $params['assigned_uid1'] = $assignedUserId;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $events = [];
    foreach ($stmt->fetchAll() as $row) {
        $status = match ($row['contact_status']) {
            'contacted' => 'done',
            'missed' => 'missed',
            default => 'upcoming',
        };

        $events[] = [
            'date' => date('Y-m-d', strtotime($row['next_contact_at'])),
            'initials' => mb_substr($row['company_name'], 0, 2),
            'title' => $row['company_name'] . ' — ' . $row['contact_name'],
            'subtitle' => 'Contact by ' . date('h:i A', strtotime($row['next_contact_at'])) . ' · ' . ucfirst($row['contact_status']),
            'phone' => $row['mobile'],
            'status' => $status,
            'url' => url('clients/view.php?id=' . $row['id']),
        ];
    }

    return $events;
}
