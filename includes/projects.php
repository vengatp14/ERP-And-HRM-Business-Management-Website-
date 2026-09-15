<?php

declare(strict_types=1);

/**
 * includes/projects.php
 * Project Management business logic: CRUD, team members, tasks, and a
 * notes timeline — mirrors includes/leads.php and includes/clients.php
 * for consistency.
 */

/**
 * Canonical status pipeline, in board-column order. Index 0 is the
 * "default" bucket — any project with a missing/unrecognised status
 * lands here so it always shows up somewhere on the Kanban board.
 */
const PROJECT_STATUSES = ['planning', 'in_progress', 'on_hold', 'pending_approval', 'completed', 'cancelled'];

const PROJECT_STATUS_LABELS = [
    'planning' => 'Planning',
    'in_progress' => 'In Progress',
    'on_hold' => 'On Hold',
    'pending_approval' => 'Pending Approval',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];

function create_project(array $data, int $createdBy): int
{
    $data['uuid'] = generate_uuid_v4();
    $data['created_by'] = $createdBy;
    $data['created_at'] = date('Y-m-d H:i:s');
    $data['updated_at'] = date('Y-m-d H:i:s');

    $columns = array_keys($data);
    $placeholders = array_map(static fn ($c) => ':' . $c, $columns);
    $sql = sprintf('INSERT INTO projects (%s) VALUES (%s)', implode(', ', $columns), implode(', ', $placeholders));

    $stmt = db()->prepare($sql);
    $stmt->execute($data);

    return (int) db()->lastInsertId();
}

function update_project(int $id, array $data): bool
{
    $data['updated_at'] = date('Y-m-d H:i:s');
    $assignments = implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", array_keys($data)));
    $stmt = db()->prepare("UPDATE projects SET {$assignments} WHERE id = :__id");
    $data['__id'] = $id;
    return $stmt->execute($data);
}

function find_project(int $id): array|false
{
    $stmt = db()->prepare(
        'SELECT projects.*, clients.company_name AS client_name, u.full_name AS manager_name
         FROM projects
         LEFT JOIN clients ON clients.id = projects.client_id
         LEFT JOIN users u ON u.id = projects.manager_id
         WHERE projects.id = :id AND projects.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    return $stmt->fetch();
}

function soft_delete_project(int $id): bool
{
    $stmt = db()->prepare('UPDATE projects SET deleted_at = :now WHERE id = :id');
    return $stmt->execute(['now' => date('Y-m-d H:i:s'), 'id' => $id]);
}

/**
 * @param array{status?:string,priority?:string,client_id?:int,search?:string,assigned_user_id?:int} $filters
 * @return array{sql_where:string, params:array}
 */
function build_project_filters(array $filters): array
{
    $where = ['projects.deleted_at IS NULL'];
    $params = [];

    if (!empty($filters['status'])) {
        $where[] = 'projects.status = :status';
        $params['status'] = $filters['status'];
    }
    if (!empty($filters['priority'])) {
        $where[] = 'projects.priority = :priority';
        $params['priority'] = $filters['priority'];
    }
    if (!empty($filters['client_id'])) {
        $where[] = 'projects.client_id = :client_id';
        $params['client_id'] = $filters['client_id'];
    }
    if (!empty($filters['search'])) {
        $where[] = '(projects.title LIKE :search1 OR clients.company_name LIKE :search2)';
        $searchTerm = '%' . $filters['search'] . '%';
        $params['search1'] = $searchTerm;
        $params['search2'] = $searchTerm;
    }
    // Restricts the list to projects a specific user is allowed to see:
    // ones they manage, or ones they've been added to as a team member.
    // Used to scope plain 'employee' accounts to only their assigned
    // properties/projects — admins never pass this filter.
    if (!empty($filters['assigned_user_id'])) {
        $where[] = '(projects.manager_id = :assigned_uid1 OR EXISTS (
            SELECT 1 FROM project_members pm
            WHERE pm.project_id = projects.id AND pm.user_id = :assigned_uid2
        ))';
        $params['assigned_uid1'] = $filters['assigned_user_id'];
        $params['assigned_uid2'] = $filters['assigned_user_id'];
    }

    return ['sql_where' => implode(' AND ', $where), 'params' => $params];
}

function count_projects(array $filters): int
{
    ['sql_where' => $where, 'params' => $params] = build_project_filters($filters);
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS cnt FROM projects LEFT JOIN clients ON clients.id = projects.client_id WHERE {$where}"
    );
    $stmt->execute($params);
    return (int) $stmt->fetch()['cnt'];
}

function count_active_projects(?int $assignedUserId = null): int
{
    $where = "deleted_at IS NULL AND status IN ('planning','in_progress','on_hold','pending_approval')";
    $params = [];

    // Scope plain 'employee' accounts to only the projects they manage or
    // are a team member on — same assigned-only visibility rule used by
    // build_project_filters() for the Projects list.
    if ($assignedUserId !== null) {
        $where .= ' AND (manager_id = :assigned_uid1 OR EXISTS (
            SELECT 1 FROM project_members pm
            WHERE pm.project_id = projects.id AND pm.user_id = :assigned_uid2
        ))';
        $params['assigned_uid1'] = $assignedUserId;
        $params['assigned_uid2'] = $assignedUserId;
    }

    $stmt = db()->prepare("SELECT COUNT(*) AS cnt FROM projects WHERE {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetch()['cnt'];
}

function list_projects(array $filters, int $limit, int $offset): array
{
    ['sql_where' => $where, 'params' => $params] = build_project_filters($filters);
    $stmt = db()->prepare(
        "SELECT projects.*, clients.company_name AS client_name, u.full_name AS manager_name
         FROM projects
         LEFT JOIN clients ON clients.id = projects.client_id
         LEFT JOIN users u ON u.id = projects.manager_id
         WHERE {$where}
         ORDER BY projects.created_at DESC
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
 * All non-deleted projects, unpaginated, for the Kanban board. Board
 * traffic is admin-only and one office's project count is small, so a
 * single unpaginated query is simpler than teaching list_projects()
 * about a "no limit" mode.
 */
function list_all_projects_for_board(): array
{
    $stmt = db()->query(
        "SELECT projects.*, clients.company_name AS client_name, u.full_name AS manager_name
         FROM projects
         LEFT JOIN clients ON clients.id = projects.client_id
         LEFT JOIN users u ON u.id = projects.manager_id
         WHERE projects.deleted_at IS NULL
         ORDER BY projects.created_at DESC"
    );
    return $stmt->fetchAll();
}

/**
 * Buckets projects by status for the Kanban board. Any project whose
 * stored status isn't one of PROJECT_STATUSES (missing, blank, or
 * stale data) is dropped into the first column by default, so nothing
 * silently disappears from the board.
 *
 * @param array $projects rows from list_all_projects_for_board()
 * @return array<string, array> status => list of project rows
 */
function group_projects_by_status(array $projects): array
{
    $columns = [];
    foreach (PROJECT_STATUSES as $status) {
        $columns[$status] = [];
    }

    $defaultStatus = PROJECT_STATUSES[0];
    foreach ($projects as $project) {
        $status = $project['status'] ?? '';
        if (!isset($columns[$status])) {
            $status = $defaultStatus;
        }
        $columns[$status][] = $project;
    }

    return $columns;
}

/**
 * True if the given user is allowed to see this specific project when
 * they're restricted to assigned-only visibility — i.e. they manage it
 * or are listed as a project_members team member. Admins/super_admins
 * should never be run through this check; they already see everything.
 */
function user_is_assigned_to_project(int $projectId, int $userId): bool
{
    $stmt = db()->prepare(
        'SELECT 1 FROM projects
          WHERE id = :project_id AND manager_id = :user_id AND deleted_at IS NULL
         UNION
         SELECT 1 FROM project_members
          WHERE project_id = :project_id2 AND user_id = :user_id2
         LIMIT 1'
    );
    $stmt->execute([
        'project_id' => $projectId,
        'user_id' => $userId,
        'project_id2' => $projectId,
        'user_id2' => $userId,
    ]);
    return $stmt->fetch() !== false;
}

/**
 * The user_id who most recently pushed this project into
 * 'pending_approval' via the employee "mark complete" flow on
 * projects/view.php — looked up from the audit trail rather than a
 * dedicated column, since it's only needed at the moment an admin
 * accepts the move to Completed (to notify that person directly, since
 * they may not be the project's manager_id at all).
 */
function find_pending_approval_requester(int $projectId): ?int
{
    $stmt = db()->prepare(
        "SELECT user_id FROM audit_logs
         WHERE module = 'projects' AND action = 'status_update'
           AND description = :desc
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['desc' => "Updated project #{$projectId} status to 'pending_approval'."]);
    $row = $stmt->fetch();
    return $row && $row['user_id'] !== null ? (int) $row['user_id'] : null;
}

// ---------------------------------------------------------------------
// Team members
// ---------------------------------------------------------------------

function add_project_member(int $projectId, int $userId, ?string $roleOnProject): bool
{
    $stmt = db()->prepare(
        'INSERT IGNORE INTO project_members (project_id, user_id, role_on_project, added_at)
         VALUES (:project_id, :user_id, :role, :now)'
    );
    return $stmt->execute([
        'project_id' => $projectId,
        'user_id' => $userId,
        'role' => $roleOnProject,
        'now' => date('Y-m-d H:i:s'),
    ]);
}

function remove_project_member(int $projectId, int $userId): bool
{
    $stmt = db()->prepare('DELETE FROM project_members WHERE project_id = :project_id AND user_id = :user_id');
    return $stmt->execute(['project_id' => $projectId, 'user_id' => $userId]);
}

function get_project_members(int $projectId): array
{
    $stmt = db()->prepare(
        'SELECT pm.*, u.full_name, u.designation
         FROM project_members pm
         JOIN users u ON u.id = pm.user_id
         WHERE pm.project_id = :project_id
         ORDER BY u.full_name ASC'
    );
    $stmt->execute(['project_id' => $projectId]);
    return $stmt->fetchAll();
}

// Projects a given employee is on — either as manager or as a team member.
// Used on the employee profile page to populate the "assign task" dropdown.
function get_projects_for_employee(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT DISTINCT p.id, p.title
         FROM projects p
         LEFT JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = :user_id1
         WHERE p.deleted_at IS NULL AND (p.manager_id = :user_id2 OR pm.user_id = :user_id3)
         ORDER BY p.title ASC'
    );
    $stmt->execute(['user_id1' => $userId, 'user_id2' => $userId, 'user_id3' => $userId]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------
// Tasks
// ---------------------------------------------------------------------

function add_project_task(int $projectId, string $title, ?int $assignedTo, ?string $dueDate): int
{
    $stmt = db()->prepare(
        'INSERT INTO project_tasks (project_id, title, assigned_to, due_date, created_at, updated_at)
         VALUES (:project_id, :title, :assigned_to, :due_date, :created_at, :updated_at)'
    );
    $now = date('Y-m-d H:i:s');
    $stmt->execute([
        'project_id' => $projectId,
        'title' => $title,
        'assigned_to' => $assignedTo,
        'due_date' => $dueDate,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    return (int) db()->lastInsertId();
}

function update_project_task_status(int $taskId, int $projectId, string $status): bool
{
    $stmt = db()->prepare(
        'UPDATE project_tasks SET status = :status, updated_at = :now WHERE id = :id AND project_id = :project_id'
    );
    return $stmt->execute(['status' => $status, 'now' => date('Y-m-d H:i:s'), 'id' => $taskId, 'project_id' => $projectId]);
}

function get_project_tasks(int $projectId): array
{
    $stmt = db()->prepare(
        'SELECT pt.*, u.full_name AS assigned_to_name
         FROM project_tasks pt
         LEFT JOIN users u ON u.id = pt.assigned_to
         WHERE pt.project_id = :project_id
         ORDER BY FIELD(pt.status, "pending","in_progress","done"), pt.due_date IS NULL, pt.due_date ASC'
    );
    $stmt->execute(['project_id' => $projectId]);
    return $stmt->fetchAll();
}

// Tasks assigned to a specific employee, across all of their projects.
// Used on the employee profile page (employees/view.php) — tasks are no
// longer listed on the project view page.
function get_tasks_for_employee(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT pt.*, p.title AS project_title
         FROM project_tasks pt
         JOIN projects p ON p.id = pt.project_id
         WHERE pt.assigned_to = :user_id
         ORDER BY FIELD(pt.status, "pending","in_progress","done"), pt.due_date IS NULL, pt.due_date ASC'
    );
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------
// Completion submission — the link + supporting documents an employee
// attaches when marking a project complete (pushing it to
// 'pending_approval'), so an admin has proof to check before accepting
// the move to Completed on the Project Board. Mirrors the lead_documents
// pattern in includes/leads.php.
// ---------------------------------------------------------------------

/**
 * Stores/replaces the reference link submitted with a completion
 * request. Pass null to clear it.
 */
function set_project_completion_link(int $projectId, ?string $link): void
{
    $stmt = db()->prepare('UPDATE projects SET completion_link = :link WHERE id = :id');
    $stmt->execute(['link' => $link, 'id' => $projectId]);
}

function add_project_completion_document(int $projectId, ?int $uploadedBy, array $uploadResult): int
{
    $stmt = db()->prepare(
        'INSERT INTO project_completion_documents (project_id, uploaded_by, original_filename, stored_filename, file_size_bytes, mime_type, created_at)
         VALUES (:project_id, :uploaded_by, :original, :stored, :size, :mime, :now)'
    );
    $stmt->execute([
        'project_id' => $projectId,
        'uploaded_by' => $uploadedBy,
        'original' => $uploadResult['original_filename'],
        'stored' => $uploadResult['stored_filename'],
        'size' => $uploadResult['size'],
        'mime' => $uploadResult['mime'],
        'now' => date('Y-m-d H:i:s'),
    ]);
    return (int) db()->lastInsertId();
}

function get_project_completion_documents(int $projectId): array
{
    $stmt = db()->prepare('SELECT * FROM project_completion_documents WHERE project_id = :project_id ORDER BY created_at DESC');
    $stmt->execute(['project_id' => $projectId]);
    return $stmt->fetchAll();
}

function find_project_completion_document(int $documentId): array|false
{
    $stmt = db()->prepare('SELECT * FROM project_completion_documents WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $documentId]);
    return $stmt->fetch();
}

// ---------------------------------------------------------------------
// Notes timeline
// ---------------------------------------------------------------------

function add_project_note(int $projectId, ?int $userId, string $content): int
{
    $stmt = db()->prepare(
        'INSERT INTO project_notes (project_id, user_id, content, created_at) VALUES (:project_id, :user_id, :content, :now)'
    );
    $stmt->execute([
        'project_id' => $projectId,
        'user_id' => $userId,
        'content' => $content,
        'now' => date('Y-m-d H:i:s'),
    ]);
    return (int) db()->lastInsertId();
}

function get_project_notes(int $projectId): array
{
    $stmt = db()->prepare(
        'SELECT pn.*, u.full_name AS user_name
         FROM project_notes pn
         LEFT JOIN users u ON u.id = pn.user_id
         WHERE pn.project_id = :project_id
         ORDER BY pn.created_at DESC'
    );
    $stmt->execute(['project_id' => $projectId]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------
// Display helpers
// ---------------------------------------------------------------------

/**
 * Builds calendar events (one per project with a deadline in the given
 * month) for the shared calendar widget on projects/index.php. A
 * project past its deadline and not completed shows as missed.
 */
function get_project_calendar_events(string $monthStart, string $monthEnd, ?int $assignedUserId = null): array
{
    $sql = "SELECT projects.id, projects.title, projects.status, projects.deadline, clients.company_name AS client_name, clients.mobile AS client_mobile
              FROM projects
              LEFT JOIN clients ON clients.id = projects.client_id
             WHERE projects.deleted_at IS NULL
               AND projects.deadline IS NOT NULL
               AND projects.deadline BETWEEN :start AND :end";
    $params = ['start' => $monthStart, 'end' => $monthEnd];

    if ($assignedUserId !== null) {
        $sql .= ' AND (projects.manager_id = :assigned_uid1 OR EXISTS (
            SELECT 1 FROM project_members pm
            WHERE pm.project_id = projects.id AND pm.user_id = :assigned_uid2
        ))';
        $params['assigned_uid1'] = $assignedUserId;
        $params['assigned_uid2'] = $assignedUserId;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $today = date('Y-m-d');
    $events = [];
    foreach ($stmt->fetchAll() as $row) {
        if ($row['status'] === 'completed') {
            $status = 'done';
        } elseif ($row['status'] === 'cancelled') {
            $status = 'cancelled';
        } elseif ($row['deadline'] < $today) {
            $status = 'missed';
        } else {
            $status = 'upcoming';
        }

        $events[] = [
            'date' => $row['deadline'],
            'initials' => mb_substr($row['client_name'] ?? $row['title'], 0, 2),
            'title' => $row['title'] . ($row['client_name'] ? ' — ' . $row['client_name'] : ''),
            'subtitle' => 'Deadline · ' . ucwords(str_replace('_', ' ', $row['status'])),
            'phone' => $row['client_mobile'],
            'status' => $status,
            'url' => url('projects/view.php?id=' . $row['id']),
        ];
    }

    return $events;
}

function project_status_badge_class(string $status): string
{
    return match ($status) {
        'planning' => 'text-bg-info',
        'in_progress' => 'text-bg-primary',
        'on_hold' => 'text-bg-warning',
        'pending_approval' => 'text-bg-warning',
        'completed' => 'text-bg-success',
        'cancelled' => 'text-bg-secondary',
        default => 'text-bg-secondary',
    };
}
