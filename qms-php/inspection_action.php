<?php
/**
 * Form actions on one report (POST ?id=<report id>, field "action"):
 *   approve, return, reject       – stage signature (signature dialog)
 *   cancel, revise                – cancel a draft, correction revision of an approved report
 *   round_add, round_sign, round_delete, verify, reopen – In-Process inspection times (verify/reopen: &round=<id>)
 * Each action checks its permission here and again in the workflow functions.
 */
require __DIR__ . '/includes/init.php';
$user = require_login();
require_once QMS_ROOT . '/includes/workflow.php';

if (! is_post()) {
    redirect('inspection.php?id=' . get_int('id'));
}
$id      = get_int('id');
$action  = post('action');
$roundId = get_int('round') ?: (ctype_digit(post('round_id')) ? (int) post('round_id') : 0);
$back    = post('return_to') === 'edit' || in_array($action, ['round_add', 'round_delete'], true) ? 'inspection_edit.php?id=' . $id : 'inspection.php?id=' . $id;

$permissions = match ($action) {
    'approve', 'return', 'reject' => ['inspection.verify_production', 'inspection.verify_quality', 'inspection.approve_qa'],
    'cancel'                      => ['inspection.cancel_own', 'inspection.cancel_any'],
    'revise'                      => ['inspection.revise'],
    'round_add', 'round_sign', 'round_delete' => ['inspection.create'],
    'verify'                      => ['inspection.round_verify'],
    'reopen'                      => ['inspection.round_reopen'],
    default                       => null,
};
if ($permissions === null) {
    flash('error', 'Unknown action.');
    redirect($back);
}
require_permission(...$permissions);

try {
    $message = 'Saved.';
    $target  = $back;
    switch ($action) {
        case 'approve':
        case 'return':
        case 'reject':
            $result  = wf_act($id, $action, post('remarks'), (string) ($_POST['password'] ?? ''), post('seen_status'), idempotency_key_from_request($_POST), $user);
            $message = $result['message'];
            $target  = 'inspection.php?id=' . $id;
            break;
        case 'cancel':
            $result  = wf_cancel($id, post('remarks'), post('seen_status'), $user);
            $message = $result['message'];
            $target  = 'inspection.php?id=' . $id;
            break;
        case 'revise':
            $result  = wf_revise($id, post('remarks'), idempotency_key_from_request($_POST), $user);
            $message = $result['message'];
            $target  = 'inspection_edit.php?id=' . (int) $result['report_id'];
            break;
        case 'round_add':
            $roundId = insp_add_round($id, ctype_digit(post('shift_id')) ? (int) post('shift_id') : 0, post('inspection_time'), $user);
            $message = 'Inspection time added.';
            $target  = 'inspection_edit.php?id=' . $id . '#round-' . $roundId;
            break;
        case 'round_sign':
            insp_sign_round($id, $roundId, $user);
            $message = 'Inspection signed by the operator.';
            $target  = $back . '#round-' . $roundId;
            break;
        case 'round_delete':
            insp_delete_round($id, $roundId, $user);
            $message = 'Empty inspection removed.';
            $target  = 'inspection_edit.php?id=' . $id;
            break;
        case 'verify':
            insp_verify_round($id, $roundId, (string) ($_POST['password'] ?? ''), $user);
            $message = 'Inspection verified by the quality engineer.';
            $target  = $back . '#round-' . $roundId;
            break;
        case 'reopen':
            insp_reopen_round($id, $roundId, post('remarks'), $user);
            $message = 'Inspection reopened for correction.';
            $target  = $back . '#round-' . $roundId;
            break;
    }
} catch (Throwable $e) {
    back_with_error($e, $back);
}
done($message, $target);
