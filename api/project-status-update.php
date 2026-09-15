<?php

declare(strict_types=1);

/**
 * api/project-status-update.php
 * JSON endpoint backing the drag-and-drop Kanban board at
 * projects/board.php. Moving a card to another column POSTs here to
 * persist the new status.
 *
 * POST project_id, status, _csrf_token
 * -> {"success": true} or {"success": false, "error": "..."}
 *
 * Admin/super_admin only — board access is a hard role check, same as
 * Company Branding and the Admin menu, not the granular per-module
 * permission system.
 *
 * Output buffering: any stray warning/notice printed by a require'd
 * file (APP_DEBUG=true prints these inline) would otherwise land in
 * front of our json_encode() and corrupt the response body — the
 * browser then can't parse it as JSON, jQuery's $.post() treats that
 * as a failed request, and the card visually reverts even though the
 * database update behind it may have already succeeded. Buffering the
 * whole request and discarding anything printed before our own JSON
 * output closes that gap.
 */

ob_start();

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function board_json_response(int $httpCode, array $body): never
{
    ob_end_clean();
    http_response_code($httpCode);
    echo json_encode($body);
    exit;
}

try {
    if (($_SESSION['user_role'] ?? '') !== 'super_admin' && ($_SESSION['user_role'] ?? '') !== 'admin') {
        board_json_response(403, ['success' => false, 'error' => 'Admins only.']);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        board_json_response(405, ['success' => false, 'error' => 'Method not allowed.']);
    }

    // csrf_verify_or_die() renders an HTML 403 page, which is wrong for a
    // JSON endpoint — check the token directly instead so the client
    // always gets JSON back.
    if (!csrf_verify($_POST['_csrf_token'] ?? null)) {
        board_json_response(403, ['success' => false, 'error' => 'Invalid CSRF token, please refresh the page.']);
    }

    $projectId = (int) ($_POST['project_id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');

    if (!in_array($status, PROJECT_STATUSES, true)) {
        board_json_response(422, ['success' => false, 'error' => 'Invalid status.']);
    }

    $project = find_project($projectId);
    if ($project === false) {
        board_json_response(404, ['success' => false, 'error' => 'Project not found.']);
    }

    $oldStatus = (string) $project['status'];

    update_project($projectId, ['status' => $status]);
    audit_log(
        (int) current_user()['id'],
        'projects',
        'status_update',
        sprintf("Moved project #%d (%s) to status '%s'.", $projectId, $project['title'], $status)
    );

    // Notify the project's manager whenever the card is moved to a
    // different column — but not the person who just moved it
    // themselves, so admins don't get pinged for their own drag.
    $currentUserId = (int) current_user()['id'];
    $alreadyNotified = [];
    if ($oldStatus !== $status && !empty($project['manager_id']) && (int) $project['manager_id'] !== $currentUserId) {
        create_notification(
            (int) $project['manager_id'],
            'task',
            "Project status changed: {$project['title']}",
            sprintf('Moved from %s to %s.', PROJECT_STATUS_LABELS[$oldStatus] ?? $oldStatus, PROJECT_STATUS_LABELS[$status] ?? $status),
            'projects/view.php?id=' . $projectId
        );
        $alreadyNotified[] = (int) $project['manager_id'];
    }

    // Accepting a card into Completed (typically from Pending Approval,
    // where an employee's own "mark complete" lands it) is what actually
    // finalizes it — same trigger as the admin setting it directly on the
    // project page, drafting a GST invoice off the project's budget.
    if ($status === 'completed' && $oldStatus !== 'completed') {
        auto_generate_invoice_for_completed_project($projectId, $currentUserId);

        // The employee who requested completion isn't necessarily the
        // project's manager_id (they may just be a team member) — the
        // "moved" notification above can miss them entirely if
        // manager_id is unset or belongs to someone else. Look up who
        // actually asked for this and notify them directly that it's
        // been approved, if we haven't already notified that same person.
        if ($oldStatus === 'pending_approval') {
            $requesterId = find_pending_approval_requester($projectId);
            if ($requesterId !== null && $requesterId !== $currentUserId && !in_array($requesterId, $alreadyNotified, true)) {
                create_notification(
                    $requesterId,
                    'task',
                    "Approved: {$project['title']}",
                    'An admin approved your completion request. The project is now marked Completed.',
                    'projects/view.php?id=' . $projectId
                );
            }
        }
    }

    board_json_response(200, ['success' => true, 'status' => $status]);
} catch (Throwable $e) {
    error_log('project-status-update failed: ' . $e->getMessage());
    board_json_response(500, [
        'success' => false,
        'error' => APP_DEBUG ? $e->getMessage() : 'Server error while updating the project status.',
    ]);
}
