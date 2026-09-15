<?php

declare(strict_types=1);

/**
 * includes/calendar.php
 * Shared month-view calendar widget used across Leads, Clients,
 * Projects, GST Billing, and Meetings (every module except Employees
 * — see each module's get_*_calendar_events() for its own date field
 * and status rules: leads/clients use a follow-up/contact date,
 * projects use their deadline, billing uses the invoice due date, and
 * meetings use the meeting date).
 *
 * Each module builds a plain array of events for the visible month and
 * hands it to render_calendar_widget(), which draws the grid,
 * color-codes each entry by status, and shows full details in a modal
 * when a day's chip is clicked. Nothing here talks to the database —
 * it only renders what it's given.
 */

/** Bootstrap badge class per calendar event status. */
const CALENDAR_STATUS_CLASSES = [
    'upcoming'  => 'text-bg-primary',
    'today'     => 'text-bg-warning',
    'done'      => 'text-bg-success',
    'missed'    => 'text-bg-danger',
    'cancelled' => 'text-bg-secondary',
    'buffer'    => 'text-bg-info',
    'deadline'  => 'text-bg-dark',
];

const CALENDAR_STATUS_LABELS = [
    'upcoming'  => 'Upcoming',
    'today'     => 'Due Today',
    'done'      => 'Attended / Done',
    'missed'    => 'Missed',
    'cancelled' => 'Cancelled',
    'buffer'    => 'Buffer Date',
    'deadline'  => 'Deadline',
];

/** Resolves the month currently shown from ?cal_month=YYYY-MM (defaults to the current month). */
function calendar_resolve_month(): array
{
    $param = $_GET['cal_month'] ?? '';
    if (is_string($param) && preg_match('/^\d{4}-\d{2}$/', $param)) {
        [$year, $month] = array_map('intval', explode('-', $param));
    } else {
        $year = (int) date('Y');
        $month = (int) date('n');
    }
    // Guard against an out-of-range month slipping in via a hand-edited URL.
    $month = max(1, min(12, $month));

    $ts = mktime(0, 0, 0, $month, 1, $year);
    return [
        'year' => $year,
        'month' => $month,
        'label' => date('F Y', $ts),
        'prev' => date('Y-m', strtotime('-1 month', $ts)),
        'next' => date('Y-m', strtotime('+1 month', $ts)),
        'start' => date('Y-m-01', $ts),
        'end' => date('Y-m-t', $ts),
        'days_in_month' => (int) date('t', $ts),
        'first_weekday' => (int) date('w', $ts), // 0 = Sunday
    ];
}

/**
 * Renders a month-view calendar widget as an HTML string.
 *
 * @param string $widgetId  Unique DOM id for this widget on the page (e.g. 'leadsCalendar').
 * @param array  $events    List of ['date'=>'Y-m-d','initials'=>string,'title'=>string,'subtitle'=>?string,'phone'=>?string,'status'=>string,'url'=>?string].
 *                          'phone', when present (leads/clients events), shows a click-to-call icon in the modal.
 * @param string $baseUrl   Current page URL (from url('module/index.php')) used to build prev/next month links.
 * @param array  $keepParams  Extra query params (e.g. active filters) to preserve across month navigation.
 * @param bool   $showAmounts Whether the modal shows the Paid/Balance row. Only GST Billing's
 *                             calendar passes true — every other module hides this row entirely.
 */
function render_calendar_widget(string $widgetId, array $events, string $baseUrl, array $keepParams = [], bool $showAmounts = false): string
{
    $m = calendar_resolve_month();

    $byDate = [];
    foreach ($events as $ev) {
        $d = $ev['date'] ?? null;
        if ($d === null) {
            continue;
        }
        $byDate[$d][] = $ev;
    }

    $prevUrl = $baseUrl . '?' . http_build_query(array_merge($keepParams, ['cal_month' => $m['prev']])) . '#' . $widgetId;
    $nextUrl = $baseUrl . '?' . http_build_query(array_merge($keepParams, ['cal_month' => $m['next']])) . '#' . $widgetId;

    // Starts collapsed (kutty/compact) by default; auto-expands once the
    // user has navigated to a specific month, so clicking prev/next
    // month doesn't collapse itself again.
    $autoExpand = isset($_GET['cal_month']);

    ob_start();
    ?>
    <div class="card mb-3 calendar-widget" id="<?= e($widgetId) ?>">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-link text-decoration-none p-0 h6 mb-0 d-flex align-items-center gap-1"
                    data-bs-toggle="collapse" data-bs-target="#<?= e($widgetId) ?>Body"
                    aria-expanded="<?= $autoExpand ? 'true' : 'false' ?>" aria-controls="<?= e($widgetId) ?>Body">
                <i class="bi bi-calendar3"></i> <?= e($m['label']) ?>
                <i class="bi bi-chevron-down small calendar-toggle-icon"></i>
            </button>
            <div class="btn-group btn-group-sm">
                <a href="<?= e($prevUrl) ?>" class="btn btn-outline-secondary" aria-label="Previous month"><i class="bi bi-chevron-left"></i></a>
                <a href="<?= e($nextUrl) ?>" class="btn btn-outline-secondary" aria-label="Next month"><i class="bi bi-chevron-right"></i></a>
            </div>
        </div>
        <div class="collapse <?= $autoExpand ? 'show' : '' ?>" id="<?= e($widgetId) ?>Body">
        <div class="card-body p-2 p-md-3">
            <div class="calendar-grid">
                <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $wd): ?>
                    <div class="calendar-weekday"><?= e($wd) ?></div>
                <?php endforeach; ?>

                <?php for ($i = 0; $i < $m['first_weekday']; $i++): ?>
                    <div class="calendar-cell calendar-cell-blank"></div>
                <?php endfor; ?>

                <?php
                $today = date('Y-m-d');
                for ($day = 1; $day <= $m['days_in_month']; $day++):
                    $dateStr = sprintf('%04d-%02d-%02d', $m['year'], $m['month'], $day);
                    $dayEvents = $byDate[$dateStr] ?? [];
                    $isToday = $dateStr === $today;
                    ?>
                    <div class="calendar-cell <?= $isToday ? 'calendar-cell-today' : '' ?>">
                        <div class="calendar-date"><?= (int) $day ?></div>
                        <div class="calendar-chips">
                            <?php foreach (array_slice($dayEvents, 0, 3) as $ev):
                                $status = $ev['status'] ?? 'upcoming';
                                $cls = CALENDAR_STATUS_CLASSES[$status] ?? 'text-bg-primary';
                                $initials = mb_strtoupper((string) ($ev['initials'] ?? '??'));
                                ?>
                                <button type="button" class="calendar-chip badge <?= e($cls) ?>"
                                        data-bs-toggle="modal" data-bs-target="#<?= e($widgetId) ?>Modal"
                                        data-title="<?= e($ev['title'] ?? '') ?>"
                                        data-subtitle="<?= e($ev['subtitle'] ?? '') ?>"
                                        data-phone="<?= e($ev['phone'] ?? '') ?>"
                                        data-status="<?= e($status) ?>"
                                        data-date="<?= e($dateStr) ?>"
                                        data-url="<?= e($ev['url'] ?? '') ?>"<?php if ($showAmounts): ?>

                                        data-paid="<?= e($ev['paid'] ?? '') ?>"
                                        data-balance="<?= e($ev['balance'] ?? '') ?>"<?php endif; ?>>
                                    <?= e($initials) ?>
                                </button>
                            <?php endforeach; ?>
                            <?php if (count($dayEvents) > 3): ?>
                                <span class="calendar-chip-more">+<?= count($dayEvents) - 3 ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
            <div class="calendar-legend small text-muted mt-2">
                <span class="badge text-bg-primary">&nbsp;</span> Upcoming
                <span class="badge text-bg-success ms-2">&nbsp;</span> Attended / Done
                <span class="badge text-bg-danger ms-2">&nbsp;</span> Missed
                <span class="badge text-bg-secondary ms-2">&nbsp;</span> Cancelled
                <span class="badge text-bg-info ms-2">&nbsp;</span> Buffer Date
                <span class="badge text-bg-dark ms-2">&nbsp;</span> Deadline
            </div>
        </div>
        </div>
    </div>

    <div class="modal fade" id="<?= e($widgetId) ?>Modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Calendar Entry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1"><strong id="<?= e($widgetId) ?>ModalName"></strong></p>
                    <p class="mb-1 text-muted" id="<?= e($widgetId) ?>ModalSubtitle"></p>
                    <p class="mb-2" id="<?= e($widgetId) ?>ModalDate"></p>
                    <?php if ($showAmounts): ?>
                    <div class="d-flex justify-content-between small mb-2" id="<?= e($widgetId) ?>ModalAmounts" style="display:none;">
                        <span>Paid: <strong id="<?= e($widgetId) ?>ModalPaid"></strong></span>
                        <span>Balance: <strong id="<?= e($widgetId) ?>ModalBalance"></strong></span>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="badge" id="<?= e($widgetId) ?>ModalStatus"></span>
                        <a href="#" id="<?= e($widgetId) ?>ModalPhone" class="btn btn-outline-success btn-sm" style="display:none;">
                            <i class="bi bi-telephone-fill"></i> Call
                        </a>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="#" id="<?= e($widgetId) ?>ModalLink" class="btn btn-primary btn-sm">Open</a>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script nonce="<?= e(csp_nonce()) ?>">
    (function () {
        var modalEl = document.getElementById('<?= e($widgetId) ?>Modal');
        if (!modalEl) { return; }
        var statusLabels = <?= json_encode(CALENDAR_STATUS_LABELS) ?>;
        var statusClasses = <?= json_encode(CALENDAR_STATUS_CLASSES) ?>;
        modalEl.addEventListener('show.bs.modal', function (evt) {
            var btn = evt.relatedTarget;
            if (!btn) { return; }
            var status = btn.getAttribute('data-status') || 'upcoming';
            document.getElementById('<?= e($widgetId) ?>ModalName').textContent = btn.getAttribute('data-title') || '';
            document.getElementById('<?= e($widgetId) ?>ModalSubtitle').textContent = btn.getAttribute('data-subtitle') || '';
            document.getElementById('<?= e($widgetId) ?>ModalDate').textContent = btn.getAttribute('data-date') || '';
            var phone = btn.getAttribute('data-phone') || '';
            var phoneBtn = document.getElementById('<?= e($widgetId) ?>ModalPhone');
            if (phone) {
                phoneBtn.href = 'tel:' + phone.replace(/[^0-9+]/g, '');
                phoneBtn.style.display = '';
            } else {
                phoneBtn.style.display = 'none';
            }
            var amountsRow = document.getElementById('<?= e($widgetId) ?>ModalAmounts');
            if (amountsRow) {
                var paid = btn.getAttribute('data-paid') || '';
                var balance = btn.getAttribute('data-balance') || '';
                if (paid || balance) {
                    document.getElementById('<?= e($widgetId) ?>ModalPaid').textContent = paid;
                    document.getElementById('<?= e($widgetId) ?>ModalBalance').textContent = balance;
                    amountsRow.style.display = '';
                } else {
                    amountsRow.style.display = 'none';
                }
            }
            var statusBadge = document.getElementById('<?= e($widgetId) ?>ModalStatus');
            statusBadge.className = 'badge ' + (statusClasses[status] || 'text-bg-primary');
            statusBadge.textContent = statusLabels[status] || status;
            var link = document.getElementById('<?= e($widgetId) ?>ModalLink');
            var url = btn.getAttribute('data-url') || '';
            link.href = url || '#';
            link.style.display = url ? '' : 'none';
        });
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}
