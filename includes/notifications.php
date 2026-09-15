<?php

declare(strict_types=1);

/**
 * includes/notifications.php
 * Notification creation/reading, plus opportunistic scanners that
 * generate notifications for deadlines, follow-ups, and missed leads
 * at page-load time — there's no cron/queue in this stack, so
 * "real-time" here means "checked on every request", the same
 * pattern already used by process_missed_leads() and
 * process_overdue_invoices().
 */

function create_notification(int $userId, string $type, string $title, ?string $message = null, ?string $link = null): int
{
    $stmt = db()->prepare(
        'INSERT INTO notifications (user_id, type, title, message, link, created_at)
         VALUES (:user_id, :type, :title, :message, :link, :now)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'link' => $link,
        'now' => date('Y-m-d H:i:s'),
    ]);
    return (int) db()->lastInsertId();
}

/** Notifies every super_admin/admin — used for events with no single obvious owner (e.g. an overdue invoice). */
function notify_admins(string $type, string $title, ?string $message = null, ?string $link = null): void
{
    $stmt = db()->query("SELECT id FROM users WHERE role IN ('super_admin','admin') AND status = 'active' AND deleted_at IS NULL");
    foreach ($stmt->fetchAll() as $admin) {
        create_notification((int) $admin['id'], $type, $title, $message, $link);
    }
}

function get_unread_notification_count(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = :uid AND is_read = 0');
    $stmt->execute(['uid' => $userId]);
    return (int) $stmt->fetch()['cnt'];
}

function get_notifications(int $userId, int $limit = 20): array
{
    $stmt = db()->prepare('SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT :lim');
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function mark_notification_read(int $id, int $userId): bool
{
    $stmt = db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid');
    return $stmt->execute(['id' => $id, 'uid' => $userId]);
}

function mark_all_notifications_read(int $userId): bool
{
    $stmt = db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0');
    return $stmt->execute(['uid' => $userId]);
}

/**
 * Groups a notification list (as returned by get_notifications()) into
 * "Today" / "Yesterday" / "Day Before Yesterday" / "Older" buckets by
 * their actual created_at date, each already in the newest-first order
 * get_notifications() returns them in — used by the login notification
 * popup (dashboard.php) so notifications read as a clear day-by-day
 * list instead of one jumbled chronological feed. Empty buckets are
 * omitted entirely.
 *
 * @param array<int, array> $notifications
 * @return array<string, array<int, array>>
 */
function group_notifications_by_day(array $notifications): array
{
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $dayBefore = date('Y-m-d', strtotime('-2 days'));

    $groups = ['Today' => [], 'Yesterday' => [], 'Day Before Yesterday' => [], 'Older' => []];

    foreach ($notifications as $notification) {
        $date = substr((string) $notification['created_at'], 0, 10);
        $label = match ($date) {
            $today => 'Today',
            $yesterday => 'Yesterday',
            $dayBefore => 'Day Before Yesterday',
            default => 'Older',
        };
        $groups[$label][] = $notification;
    }

    return array_filter($groups, static fn (array $group) => !empty($group));
}

// ---------------------------------------------------------------------
// Opportunistic scanners — called once per request from bootstrap-level
// pages (dashboard.php); each is idempotent-ish via a same-day existence
// check so refreshing the page doesn't spam duplicate notifications.
// ---------------------------------------------------------------------

/** True if a notification with this exact title already exists for this user today (de-dupe guard for opportunistic scanners). */
function notification_already_sent_today(int $userId, string $title): bool
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS cnt FROM notifications
         WHERE user_id = :uid AND title = :title AND DATE(created_at) = CURDATE()"
    );
    $stmt->execute(['uid' => $userId, 'title' => $title]);
    return (int) $stmt->fetch()['cnt'] > 0;
}

/** Notifies each lead's assigned owner about follow-ups due today. */
function scan_followups_due_today(): void
{
    $stmt = db()->query(
        "SELECT id, client_name, assigned_to FROM leads
         WHERE deleted_at IS NULL AND assigned_to IS NOT NULL
           AND next_follow_up_at IS NOT NULL AND DATE(next_follow_up_at) = CURDATE()
           AND status NOT IN ('won','lost','cancelled')"
    );
    foreach ($stmt->fetchAll() as $lead) {
        $title = "Follow-up due today: {$lead['client_name']}";
        if (!notification_already_sent_today((int) $lead['assigned_to'], $title)) {
            create_notification(
                (int) $lead['assigned_to'],
                'follow_up',
                $title,
                'A follow-up is scheduled for today.',
                'leads/view.php?id=' . $lead['id']
            );
        }
    }
}

/**
 * Notifies each lead's assigned owner when their lead has just been
 * auto-flagged as missed, and also notifies admins with the owner's
 * name attached — so managers can see who missed a follow-up without
 * having to be the assignee themselves.
 */
function notify_missed_lead(int $leadId, string $clientName, ?int $assignedTo): void
{
    $link = 'leads/view.php?id=' . $leadId;
    $ownerName = null;

    if ($assignedTo !== null) {
        $owner = find_user_by_id($assignedTo);
        $ownerName = $owner ? $owner['full_name'] : null;

        create_notification(
            $assignedTo,
            'missed_lead',
            "Lead marked missed: {$clientName}",
            'This lead missed its scheduled follow-up and was automatically flagged.',
            $link
        );
    }

    $ownerLabel = $ownerName ?? 'Unassigned';
    notify_admins(
        'missed_lead',
        "Missed follow-up: {$clientName} ({$ownerLabel})",
        "{$ownerLabel} did not update the follow-up in time — lead was automatically flagged as missed.",
        $link
    );
}

/** Notifies every attendee (and admins) when a scheduled meeting has just been auto-flagged as missed. */
function notify_missed_meeting(int $meetingId, string $title, array $attendees): void
{
    $link = 'hr/meetings.php?id=' . $meetingId;

    foreach ($attendees as $attendee) {
        create_notification(
            (int) $attendee['id'],
            'missed_meeting',
            "Meeting missed: {$title}",
            'This meeting passed without being marked held and was automatically flagged as missed.',
            $link
        );
    }

    $attendeeNames = !empty($attendees) ? implode(', ', array_column($attendees, 'full_name')) : 'no attendees recorded';
    notify_admins(
        'missed_meeting',
        "Missed meeting: {$title}",
        "Attendees ({$attendeeNames}) did not mark this meeting held — it was automatically flagged as missed.",
        $link
    );
}

/** Notifies admins when a client's scheduled next-contact date has just been auto-flagged as missed (same on-demand pattern as notify_missed_lead()). */
function notify_missed_client_contact(int $clientId, string $companyName): void
{
    notify_admins(
        'missed_client_contact',
        "Missed client contact: {$companyName}",
        'This client was not marked contacted before its scheduled next-contact date and was automatically flagged as missed.',
        'clients/view.php?id=' . $clientId
    );
}

/** Notifies project managers about projects with a deadline in the next 3 days. */
function scan_project_deadlines(): void
{
    $stmt = db()->query(
        "SELECT id, title, manager_id, deadline FROM projects
         WHERE deleted_at IS NULL AND manager_id IS NOT NULL
           AND status IN ('planning','in_progress','on_hold','pending_approval')
           AND deadline IS NOT NULL AND deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)"
    );
    foreach ($stmt->fetchAll() as $project) {
        $title = "Project deadline approaching: {$project['title']}";
        if (!notification_already_sent_today((int) $project['manager_id'], $title)) {
            create_notification(
                (int) $project['manager_id'],
                'deadline',
                $title,
                "Deadline: {$project['deadline']}",
                'projects/view.php?id=' . $project['id']
            );
        }
    }
}

/** Notifies the invoice creator when their invoice has just been flagged overdue. */
function notify_overdue_invoice(int $invoiceId, string $invoiceNumber, int $createdBy): void
{
    create_notification(
        $createdBy,
        'overdue_invoice',
        "Invoice overdue: {$invoiceNumber}",
        'This invoice has passed its due date without full payment.',
        'billing/view.php?id=' . $invoiceId
    );
}

function notification_icon(string $type): string
{
    return match ($type) {
        'deadline' => 'bi-alarm',
        'follow_up' => 'bi-telephone',
        'missed_lead' => 'bi-exclamation-triangle',
        'payment' => 'bi-cash-coin',
        'meeting' => 'bi-people',
        'missed_meeting' => 'bi-calendar-x',
        'missed_client_contact' => 'bi-person-x',
        'leave' => 'bi-calendar-check',
        'task' => 'bi-check2-square',
        'overdue_invoice' => 'bi-receipt',
        'auto_expense' => 'bi-arrow-repeat',
        default => 'bi-bell',
    };
}

/** Bootstrap text-color class for a notification type — overdue invoices are flagged red so they stand out from routine notifications. */
function notification_color_class(string $type): string
{
    return match ($type) {
        'overdue_invoice', 'missed_lead', 'missed_meeting', 'missed_client_contact' => 'text-danger',
        default => 'text-muted',
    };
}
