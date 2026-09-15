<?php
/**
 * includes/alerts.php
 * Renders any flash messages queued via flash_set(), then clears them.
 * Include wherever flash messages should appear (typically right after
 * the page's opening content wrapper).
 */
$__alertTypes = ['error' => 'danger', 'status' => 'success', 'success' => 'success', 'warning' => 'warning', 'info' => 'info'];
foreach ($__alertTypes as $flashKey => $bsClass):
    foreach (flash_get($flashKey) as $message):
?>
    <div class="alert alert-<?= e($bsClass) ?> alert-dismissible fade show" role="alert">
        <?= e($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php
    endforeach;
endforeach;
unset($__alertTypes, $flashKey, $bsClass, $message);
