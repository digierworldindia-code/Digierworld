<?php
/**
 * Shared signature dialog (remarks + password), opened by buttons with
 * data-sign-* attributes (assets/js/app.js sets the form action and "action").
 * Variables: $report, $user, $returnTo ('edit' or 'show').
 */
defined('QMS') || exit;
?>
<div class="modal fade" id="signatureModal" tabindex="-1" aria-labelledby="signatureTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= e(url('inspection_action.php?id=' . (int) $report['id'])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="">
            <input type="hidden" name="seen_status" value="<?= e($report['status']) ?>">
            <input type="hidden" name="idempotency_key" value="">
            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
            <div class="modal-header">
                <h2 class="modal-title h5" id="signatureTitle" data-sign-title>Confirm</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted" data-sign-meaning></p>
                <div class="mb-3">
                    <label class="form-label" for="signRemarks" data-remarks-label>Remarks</label>
                    <textarea class="form-control" id="signRemarks" name="remarks" rows="3" maxlength="1000"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label required" for="signPassword">Your password</label>
                    <input class="form-control form-control-lg" type="password" id="signPassword" name="password" autocomplete="current-password">
                    <div class="form-text">Re-entering your password confirms that you are signing this record yourself.</div>
                </div>
                <p class="small mb-0"><?= qms_icon('bi-person-check') ?> Signing as <strong><?= e($user['display_name']) ?></strong>
                    (<?= e($user['role_name']) ?><?= $user['employee_code'] ? ', ' . e($user['employee_code']) : '' ?>)</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-lg" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-lg" data-once>Sign</button>
            </div>
        </form>
    </div>
</div>
