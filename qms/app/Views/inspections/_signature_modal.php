<?php
/**
 * Shared signature dialog (remarks + password). Opened by buttons carrying
 * data-sign-* attributes (see public/assets/js/app.js).
 *
 * @var array<string, mixed> $report
 * @var array<string, mixed> $currentUser
 * @var string               $returnTo
 */
?>
<div class="modal fade" id="signatureModal" tabindex="-1" aria-labelledby="signatureTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= site_url('inspections/' . $report['id']) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="">
            <input type="hidden" name="seen_status" value="<?= esc($report['status'], 'attr') ?>">
            <input type="hidden" name="idempotency_key" value="">
            <input type="hidden" name="return_to" value="<?= esc($returnTo ?? 'show', 'attr') ?>">
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
                <p class="small mb-0"><?= qms_icon('bi-person-check') ?> Signing as <strong><?= esc($currentUser['display_name']) ?></strong>
                    (<?= esc($currentUser['role_name']) ?><?= $currentUser['employee_code'] ? ', ' . esc($currentUser['employee_code']) : '' ?>)</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-lg" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-lg" data-once>Sign</button>
            </div>
        </form>
    </div>
</div>
