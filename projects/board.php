<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
// Board is an admin-only view, same hard role gate as Company Branding —
// intentionally not on the granular per-module permission system, so a
// manager who was merely granted projects.edit can't reach it.
require_role('super_admin', 'admin');

$projects = list_all_projects_for_board();
$columns = group_projects_by_status($projects);

$pageTitle = 'Project Board';
$activeMenu = 'project_board';
$breadcrumbs = [
    ['label' => 'Project Board', 'url' => null],
];

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/sidebar.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Project Board</h1>
    <a href="<?= e(url('projects/index.php')) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-list-ul"></i> List View
    </a>
</div>

<div id="boardAlert" class="alert alert-danger d-none py-2" role="alert"></div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1080;">
    <div id="boardMoveToast" class="toast align-items-center text-bg-success border-0" role="status" aria-live="polite" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="boardMoveToastBody">Project moved.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<div class="modal fade" id="boardMoveConfirmModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Move project?</h5>
            </div>
            <div class="modal-body" id="boardMoveConfirmBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="boardMoveCancelBtn">Cancel</button>
                <button type="button" class="btn btn-primary" id="boardMoveAcceptBtn">Accept</button>
            </div>
        </div>
    </div>
</div>

<div class="board-scroll">
    <div class="d-flex gap-3 align-items-start board-columns">
        <?php foreach (PROJECT_STATUSES as $status): ?>
            <div class="board-column">
                <div class="board-column-header d-flex justify-content-between align-items-center">
                    <span><?= e(PROJECT_STATUS_LABELS[$status]) ?></span>
                    <span class="badge text-bg-secondary board-count"><?= e((string) count($columns[$status])) ?></span>
                </div>
                <div class="board-dropzone" data-status="<?= e($status) ?>">
                    <?php foreach ($columns[$status] as $project): ?>
                        <div class="board-card" data-project-id="<?= e((string) $project['id']) ?>">
                            <div class="board-card-title">
                                <a href="<?= e(url('projects/view.php?id=' . $project['id'])) ?>" draggable="false"><?= e($project['title']) ?></a>
                            </div>
                            <div class="board-card-client text-muted small"><?= e($project['client_name'] ?? '—') ?></div>
                            <div class="board-card-dates text-muted small mt-1">
                                <div><i class="bi bi-play-circle"></i> Start: <?= e($project['start_date'] ?? '—') ?></div>
                                <div><i class="bi bi-flag"></i> Deadline: <?= e($project['deadline'] ?? '—') ?></div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="badge <?= e(lead_priority_badge_class($project['priority'])) ?>"><?= e(ucfirst($project['priority'])) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($columns[$status])): ?>
                        <div class="board-empty text-muted small">No projects</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<style>
    .board-scroll { overflow-x: auto; padding-bottom: .5rem; }
    .board-columns { min-width: max-content; }
    .board-column { width: 260px; flex: 0 0 260px; }
    .board-column-header {
        font-weight: 600;
        padding: .5rem .75rem;
        background: var(--bs-tertiary-bg, #f1f3f5);
        border-radius: .5rem .5rem 0 0;
        border: 1px solid var(--bs-border-color);
        border-bottom: none;
    }
    .board-dropzone {
        min-height: 120px;
        padding: .5rem;
        border: 1px solid var(--bs-border-color);
        border-radius: 0 0 .5rem .5rem;
        background: var(--bs-body-bg);
    }
    .board-dropzone.drag-over { background: rgba(13, 110, 253, .08); }
    .board-card {
        background: var(--bs-body-bg);
        border: 1px solid var(--bs-border-color);
        border-radius: .4rem;
        padding: .5rem .6rem;
        margin-bottom: .5rem;
        cursor: grab;
        box-shadow: 0 1px 2px rgba(0,0,0,.04);
        min-height: 128px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .board-card:active { cursor: grabbing; }
    .board-card.dragging { opacity: .4; }
    .board-card-title a {
        text-decoration: none;
        font-weight: 500;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .board-card-dates { font-size: .75rem; line-height: 1.4; }
    .board-card-dates i { width: 1rem; display: inline-block; }
    .board-empty { text-align: center; padding: .75rem 0; }

    /* Mobile: the side-scrolling columns are hard to use on a phone
       (each column is easy to miss, and horizontal scroll + the
       card drag gesture fight each other). Stack columns as full-width
       blocks instead, one under the other, so the whole board is just
       a normal vertical scroll. */
    @media (max-width: 767.98px) {
        .board-scroll { overflow-x: visible; }
        .board-columns { flex-direction: column; min-width: 0; }
        .board-column { width: 100%; flex: 1 1 auto; }
    }
</style>

<script src="<?= e(asset('js/vendor/Sortable.min.js')) ?>"></script>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    var csrfToken = <?= json_encode(csrf_token()) ?>;
    var updateUrl = <?= json_encode(url('api/project-status-update.php')) ?>;
    var sortableInstances = [];

    if (typeof Sortable === 'undefined') {
        // The CDN script tag failed to load (no internet on this machine,
        // firewall/ad-blocker, offline dev environment, etc). Fail loudly
        // instead of silently — this is exactly the "drag does nothing"
        // symptom with no clue why.
        showAlert('Drag-and-drop could not start: a required script (Sortable.js from cdn.jsdelivr.net) failed to load. Check this machine\'s internet connection, then hard-refresh the page (Ctrl+Shift+R).');
        return;
    }

    // SortableJS instead of hand-rolled native HTML5 drag events: native
    // drag/drop has a lot of browser-specific gotchas (links inside a
    // draggable card hijacking the gesture, Firefox needing setData(),
    // Safari behaving differently again). forceFallback makes it use its
    // own mouse-based implementation everywhere instead of relying on the
    // browser's native (and inconsistent) drag API.
    //
    // initBoard() (re)creates every dropzone's Sortable instance from
    // scratch. With forceFallback + multiple same-group lists, SortableJS
    // can leave its internal drag state (and, occasionally, a leftover
    // ".sortable-fallback" ghost node parked on <body>) stuck after a
    // cross-column move — the next drag then silently does nothing
    // because the library thinks a drag is still in progress, and only
    // a full page reload clears it. Tearing down and rebuilding all
    // instances after every drag (success or failure) resets that state
    // so the very next drag always starts clean, without needing a
    // manual refresh.
    function initBoard() {
        sortableInstances.forEach(function (instance) {
            try { instance.destroy(); } catch (err) { /* ignore — instance already gone */ }
        });
        sortableInstances = [];

        // Safety net: remove any ghost/clone nodes SortableJS's fallback
        // mode may have left behind directly on <body> from an
        // interrupted drag. Scoped to direct children of <body> only —
        // real cards live nested inside .board-dropzone, so this can
        // never touch actual project cards, only stray fallback clones.
        document.querySelectorAll('body > .sortable-fallback, body > .sortable-ghost, body > .sortable-drag, body > .dragging').forEach(function (el) {
            el.parentNode && el.parentNode.removeChild(el);
        });
        // Also strip (not remove) any leftover drag-state classes that
        // may still be sitting on a real card after an interrupted drag.
        document.querySelectorAll('.board-card.dragging, .board-card.sortable-chosen, .board-card.sortable-ghost').forEach(function (el) {
            el.classList.remove('dragging', 'sortable-chosen', 'sortable-ghost', 'sortable-drag');
        });

        document.querySelectorAll('.board-dropzone').forEach(function (zone) {
            sortableInstances.push(new Sortable(zone, {
                group: 'project-board',
                animation: 150,
                forceFallback: true,
                fallbackClass: 'dragging',
                fallbackOnBody: true,
                filter: '.board-empty, a',
                preventOnFilter: false,
                onEnd: function (evt) {
                    var card = evt.item;
                    var fromZone = evt.from;
                    var toZone = evt.to;
                    var projectId = card.getAttribute('data-project-id');
                    var newStatus = toZone.getAttribute('data-status');

                    updateColumnCounts();

                    if (fromZone === toZone) {
                        // Don't touch the Sortable instances synchronously from
                        // inside this callback — SortableJS is still unwinding
                        // its own internal drag state at this point (it shares
                        // that state across every instance on the page), and
                        // destroying/recreating mid-unwind corrupts it. Wait a
                        // tick so its own cleanup finishes first.
                        setTimeout(initBoard, 0);
                        return; // reordered within the same column, no status change
                    }

                    hideAlert();

                    // Card already visually sits in the new column at this point
                    // (SortableJS moved the DOM node during the drag). Don't call
                    // the API yet — ask for confirmation first. If the user
                    // cancels, put the card back where it was and skip the request
                    // entirely.
                    var titleEl = card.querySelector('.board-card-title');
                    var projectTitle = titleEl ? titleEl.textContent.trim() : 'This project';
                    var newColumnLabel = toZone.closest('.board-column').querySelector('.board-column-header span').textContent.trim();
                    var refNode = fromZone.children[evt.oldIndex] || null;

                    console.debug('[board] move requested', { projectId: projectId, from: fromZone.getAttribute('data-status'), to: newStatus });

                    confirmMove(
                        projectTitle + ' will be moved to "' + newColumnLabel + '".',
                        function onAccept() {
                            $.post(updateUrl, {
                                project_id: projectId,
                                status: newStatus,
                                _csrf_token: csrfToken
                            }).done(function () {
                                showMoveToast(projectTitle + ' moved to "' + newColumnLabel + '".');
                            }).fail(function (xhr) {
                                // Revert to the original column/position if the server rejects it.
                                fromZone.insertBefore(card, refNode);
                                updateColumnCounts();
                                var msg = 'Could not update the project status.';
                                try {
                                    var body = JSON.parse(xhr.responseText);
                                    if (body && body.error) msg = body.error;
                                } catch (err) {
                                    // Response wasn't valid JSON — log the raw body so it
                                    // shows up in the console for debugging instead of
                                    // silently falling back to the generic message.
                                    console.error('project-status-update: non-JSON response', xhr.status, xhr.responseText);
                                    msg = 'Server returned an unexpected response (HTTP ' + xhr.status + '). Check the browser console for details.';
                                }
                                showAlert(msg);
                            }).always(function () {
                                // Same reasoning as above: defer to the next tick so
                                // SortableJS's own end-of-drag bookkeeping is fully
                                // done before we tear down and rebuild its instances.
                                setTimeout(initBoard, 0);
                            });
                        },
                        function onCancel() {
                            // User declined the move — put the card back and don't touch the server.
                            fromZone.insertBefore(card, refNode);
                            updateColumnCounts();
                            setTimeout(initBoard, 0);
                        }
                    );
                }
            }));
        });
    }

    initBoard();

    function updateColumnCounts() {
        document.querySelectorAll('.board-column').forEach(function (col) {
            var count = col.querySelectorAll('.board-card').length;
            col.querySelector('.board-count').textContent = count;
            var empty = col.querySelector('.board-empty');
            if (empty) empty.style.display = count > 0 ? 'none' : 'block';
        });
    }

    function showAlert(message) {
        var el = document.getElementById('boardAlert');
        el.textContent = message;
        el.classList.remove('d-none');
    }
    function hideAlert() {
        document.getElementById('boardAlert').classList.add('d-none');
    }

    function showMoveToast(message) {
        var toastEl = document.getElementById('boardMoveToast');
        document.getElementById('boardMoveToastBody').textContent = message;
        var toast = bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 3000 });
        toast.show();
    }

    // Shows the confirm modal and wires up its buttons for this one move.
    // Re-binds onClick fresh each call (instead of one persistent listener)
    // so each drag gets exactly one accept/cancel callback pair, with no
    // risk of a previous drag's stale handler firing too.
    //
    // Bootstrap's show()/hide() are animated (not instant) — calling show()
    // again while a previous modal is still mid-hide-transition can leave
    // it stuck behind its own backdrop with nothing visibly appearing.
    // pendingMove + the 'hidden.bs.modal' listener queue up a second call
    // instead of firing it immediately, so a fast second drag always still
    // gets its popup instead of silently doing nothing.
    var moveModalEl = document.getElementById('boardMoveConfirmModal');
    var moveModal = new bootstrap.Modal(moveModalEl);
    var settled = true;
    var pendingMove = null;

    function confirmMove(message, onAccept, onCancel) {
        if (!settled) {
            // A previous confirm is still open/closing — queue this one to
            // open right after it finishes instead of calling show() now.
            pendingMove = { message: message, onAccept: onAccept, onCancel: onCancel };
            return;
        }
        openMoveModal(message, onAccept, onCancel);
    }

    function openMoveModal(message, onAccept, onCancel) {
        settled = false;
        document.getElementById('boardMoveConfirmBody').textContent = message;

        var acceptBtn = document.getElementById('boardMoveAcceptBtn');
        var cancelBtn = document.getElementById('boardMoveCancelBtn');
        var newAcceptBtn = acceptBtn.cloneNode(true);
        var newCancelBtn = cancelBtn.cloneNode(true);
        acceptBtn.parentNode.replaceChild(newAcceptBtn, acceptBtn);
        cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);

        newAcceptBtn.addEventListener('click', function () {
            settled = true;
            moveModal.hide();
            onAccept();
        });
        newCancelBtn.addEventListener('click', function () {
            settled = true;
            moveModal.hide();
            onCancel();
        });

        moveModal.show();
    }

    // Backdrop click is disabled (data-bs-backdrop="static"), but the modal
    // can still be closed via Esc in some browsers or a stray dismiss — if
    // it closes without either button being clicked, treat that as Cancel
    // so the card never silently stays moved without a server update.
    moveModalEl.addEventListener('hidden.bs.modal', function () {
        if (!settled) {
            settled = true;
            var cancelBtn = document.getElementById('boardMoveCancelBtn');
            cancelBtn.click();
        }
        if (pendingMove) {
            var next = pendingMove;
            pendingMove = null;
            openMoveModal(next.message, next.onAccept, next.onCancel);
        }
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
