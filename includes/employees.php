<?php

declare(strict_types=1);

/**
 * includes/employees.php
 * Employee Directory business logic. Employees ARE users (the `users`
 * table already carries department/designation/status) — this module
 * is a staff-focused view/management layer over that same table,
 * distinct from Admin's account-security-focused user list
 * (admin/index.php, which handles suspend/reactivate).
 */

/**
 * @param array{status?:string,department?:string,search?:string} $filters
 * @return array{sql_where:string, params:array}
 */
function build_employee_filters(array $filters): array
{
    $where = ['deleted_at IS NULL'];
    $params = [];

    if (!empty($filters['status'])) {
        $where[] = 'status = :status';
        $params['status'] = $filters['status'];
    }
    if (!empty($filters['department'])) {
        $where[] = 'department = :department';
        $params['department'] = $filters['department'];
    }
    if (!empty($filters['search'])) {
        $where[] = '(full_name LIKE :search1 OR email LIKE :search2 OR mobile LIKE :search3 OR designation LIKE :search4)';
        $searchTerm = '%' . $filters['search'] . '%';
        $params['search1'] = $searchTerm;
        $params['search2'] = $searchTerm;
        $params['search3'] = $searchTerm;
        $params['search4'] = $searchTerm;
    }

    return ['sql_where' => implode(' AND ', $where), 'params' => $params];
}

function count_employees(array $filters): int
{
    ['sql_where' => $where, 'params' => $params] = build_employee_filters($filters);
    $stmt = db()->prepare("SELECT COUNT(*) AS cnt FROM users WHERE {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetch()['cnt'];
}

function list_employees(array $filters, int $limit, int $offset): array
{
    ['sql_where' => $where, 'params' => $params] = build_employee_filters($filters);
    $stmt = db()->prepare(
        "SELECT id, full_name, email, mobile, role, department, designation, status, last_login_at, created_at
         FROM users
         WHERE {$where}
         ORDER BY full_name ASC
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

/** Distinct department list, for the filter dropdown. */
function list_departments(): array
{
    $stmt = db()->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
    return array_column($stmt->fetchAll(), 'department');
}

/** Generates a random temporary password for a newly created employee account. */
function generate_temp_password(): string
{
    return bin2hex(random_bytes(6)) . '#A1';
}

function employee_status_badge_class(string $status): string
{
    return match ($status) {
        'active' => 'text-bg-success',
        'inactive' => 'text-bg-secondary',
        'suspended' => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
}
