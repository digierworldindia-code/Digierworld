<?php
/**
 * Read-only In-Process grid with round signatures (QE verification and reopen
 * are available here too).
 *
 * @var array<string, mixed>       $report
 * @var list<array<string, mixed>> $structure
 * @var list<array<string, mixed>> $rounds
 * @var array<string, mixed>       $currentUser
 */
$authz     = service('authorization');
$open      = in_array($report['status'], ['DRAFT', 'RETURNED'], true);
$canVerify = $open && $authz->userCan($currentUser, 'inspection.round_verify');
$canReopen = $open && $authz->userCan($currentUser, 'inspection.round_reopen');
$reauth    = (bool) qms_setting('workflow.reauth_on_sign', true);
?>
<?php if ($rounds === []): ?>
    <div class="qms-empty"><?= qms_icon('bi-grid-3x3') ?>No inspections recorded yet.</div>
<?php else: ?>
<div class="qms-grid-wrap">
    <table class="qms-grid qms-grid--read">
        <thead>
        <tr>
            <th class="sticky-col" scope="col">Parameter</th>
            <?php foreach ($rounds as $r): ?>
                <th scope="col" id="round-<?= (int) $r['id'] ?>">Shift <?= esc((string) $r['shift_code']) ?><div class="fw-normal">#<?= (int) $r['round_no'] ?> · <?= esc(substr((string) $r['inspection_time'], 0, 5)) ?></div></th>
            <?php endforeach ?>
        </tr>
        </thead>
        <tbody>
        <?php $sr = 0; foreach ($structure as $section): foreach ($section['parameters'] as $param): $sr++; ?>
            <tr>
                <th class="sticky-col" scope="row"><div class="fw-bold"><?= $sr ?>. <?= esc($param['name']) ?></div>
                    <div class="small text-muted"><?= esc(spec_limits($param['lsl'], $param['usl'], (int) $param['decimal_places'], $param['unit_symbol'])) ?></div></th>
                <?php foreach ($rounds as $r): $obs = $r['observations'][(int) $param['id']] ?? null; ?>
                    <td class="text-center num<?= ($obs['result'] ?? '') === 'FAIL' ? ' qms-oos' : '' ?>">
                        <?php if ($obs !== null): ?>
                            <?php for ($n = 1; $n <= (int) $param['observation_count']; $n++): $reading = $obs['readings'][$n] ?? null; ?>
                                <div><?= esc(reading_text($param, $reading)) ?><?= ($reading['result'] ?? null) === 'FAIL' ? ' <span class="qms-oos-mark">✗</span>' : '' ?></div>
                            <?php endfor ?>
                            <?php if ($obs['gauge_code'] !== null): ?><div class="small text-muted"><?= esc($obs['gauge_code']) ?></div><?php endif ?>
                        <?php endif ?>
                    </td>
                <?php endforeach ?>
            </tr>
        <?php endforeach; endforeach ?>
        </tbody>
        <tbody class="round-foot">
        <tr><th class="sticky-col" scope="row">Lot status</th><?php foreach ($rounds as $r): ?><td><?= esc(ucwords(strtolower(str_replace('_', ' ', (string) $r['lot_status'])))) ?></td><?php endforeach ?></tr>
        <tr><th class="sticky-col" scope="row">Accepted / rejected / rework</th><?php foreach ($rounds as $r): ?><td class="num"><?= (int) $r['accepted_qty'] ?> / <?= (int) $r['rejected_qty'] ?> / <?= (int) $r['rework_qty'] ?></td><?php endforeach ?></tr>
        <tr><th class="sticky-col" scope="row">Remarks</th><?php foreach ($rounds as $r): ?><td class="small"><?= esc((string) ($r['remarks'] ?? '')) ?></td><?php endforeach ?></tr>
        <tr><th class="sticky-col" scope="row">Inspected by operator</th>
            <?php foreach ($rounds as $r): ?><td><?php if ($r['status'] !== 'OPEN'): ?><strong><?= esc($r['operator_name'] ?? $r['operator_username'] ?? '') ?></strong><div class="small text-muted"><?= esc(plant_dt($r['operator_signed_at'], 'd-m H:i')) ?></div><?php else: ?><span class="small text-muted">Not signed</span><?php endif ?></td><?php endforeach ?></tr>
        <tr><th class="sticky-col" scope="row">Inspected by QE</th>
            <?php foreach ($rounds as $r): ?>
                <td>
                    <?php if ($r['status'] === 'VERIFIED'): ?>
                        <strong><?= esc($r['qe_name'] ?? $r['qe_username'] ?? '') ?></strong><div class="small text-muted"><?= esc(plant_dt($r['qe_verified_at'], 'd-m H:i')) ?></div>
                    <?php elseif ($r['status'] === 'SIGNED' && $canVerify && (int) $r['operator_user_id'] !== (int) $currentUser['id']): ?>
                        <button class="btn btn-sm btn-primary w-100" type="button" data-sign-action="verify" data-sign-url="<?= site_url('inspections/' . $report['id'] . '/rounds/' . $r['id'] . '/verify') ?>"
                                data-sign-title="Verify inspection #<?= (int) $r['round_no'] ?>" data-sign-meaning="Inspected By Quality Engineer: you confirm the readings of this inspection."
                                data-sign-remarks="none" data-sign-password="<?= $reauth ? 'yes' : 'no' ?>" data-sign-button="Verify"><?= qms_icon('bi-patch-check') ?> Verify</button>
                    <?php else: ?><span class="small text-muted">–</span><?php endif ?>
                    <?php if ($r['status'] !== 'OPEN' && $canReopen): ?>
                        <button class="btn btn-sm btn-link w-100" type="button" data-sign-action="reopen" data-sign-url="<?= site_url('inspections/' . $report['id'] . '/rounds/' . $r['id'] . '/reopen') ?>"
                                data-sign-title="Reopen inspection #<?= (int) $r['round_no'] ?>" data-sign-meaning="The signatures of this inspection are cleared so the values can be corrected."
                                data-sign-remarks="required" data-sign-password="no" data-sign-button="Reopen" data-sign-tone="btn-warning"><?= qms_icon('bi-unlock') ?> Reopen</button>
                    <?php endif ?>
                </td>
            <?php endforeach ?>
        </tr>
        </tbody>
    </table>
</div>
<?php endif ?>
