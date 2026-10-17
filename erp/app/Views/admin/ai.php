<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="alert alert-light border small">
    <i class="bi bi-shield-lock"></i> The AI is a <strong>service identity</strong>, not a user. It can only use the capabilities below, one record at a time, with the listed fields. It has no access to SQL, generic search or export, files, backups, <code>.env</code>, billing, CRM, bank data or Google Sheets credentials. Every capability is <strong>off by default</strong>; every request is logged here with field names and record ids only (never CV or payroll contents). AI output is decision support — hiring and payroll decisions stay with people.
</div>
<div class="row g-3">
<div class="col-xl-4">
    <div class="card mb-3"><div class="card-body small">
        <div class="d-flex justify-content-between"><div class="card-section-title">Provider</div><?= $provider['configured'] ? '<span class="badge text-bg-success">Configured</span>' : '<span class="badge text-bg-secondary">Not configured</span>' ?></div>
        <dl class="row mb-2">
            <dt class="col-5">Provider</dt><dd class="col-7"><?= esc($provider['name']) ?></dd>
            <dt class="col-5">Model</dt><dd class="col-7 mono"><?= esc($provider['model']) ?></dd>
            <dt class="col-5">API key</dt><dd class="col-7"><?= $provider['hasKey'] ? 'present in server config' : '<span class="text-danger">missing</span> — set <code>ai.apiKey</code> in <code>.env</code>' ?></dd>
            <dt class="col-5">Timeout / retries</dt><dd class="col-7"><?= (int) $provider['timeout'] ?> s / <?= (int) $provider['retries'] ?></dd>
            <dt class="col-5">Effort</dt><dd class="col-7"><?= esc($provider['effort']) ?></dd>
        </dl>
        <?php if ($canManage): ?>
        <details><summary>Change provider settings</summary>
        <form method="post" action="<?= site_url('admin/ai/provider') ?>" class="mt-2"><?= csrf_field() ?>
            <label class="form-label" for="ai_provider">Provider</label><select class="form-select form-select-sm mb-2" id="ai_provider" name="provider"><?php foreach (\App\Services\Ai\AiConfig::PROVIDERS as $k => $l): ?><option value="<?= $k ?>"><?= esc($l) ?></option><?php endforeach ?></select>
            <label class="form-label" for="ai_model">Model</label><input class="form-control form-control-sm mb-2" id="ai_model" name="model" value="<?= esc($provider['model']) ?>" list="ai_models"><datalist id="ai_models"><?php foreach ($models as $m): ?><option value="<?= esc($m) ?>"><?php endforeach ?></datalist>
            <div class="row g-2 mb-2"><div class="col-4"><label class="form-label" for="ai_to">Timeout (s)</label><input class="form-control form-control-sm" id="ai_to" name="timeout" value="<?= (int) $provider['timeout'] ?>"></div>
                <div class="col-4"><label class="form-label" for="ai_rt">Retries</label><input class="form-control form-control-sm" id="ai_rt" name="max_retries" value="<?= (int) $provider['retries'] ?>"></div>
                <div class="col-4"><label class="form-label" for="ai_ef">Effort</label><select class="form-select form-select-sm" id="ai_ef" name="effort"><?php foreach (['low', 'medium', 'high'] as $e): ?><option <?= $provider['effort'] === $e ? 'selected' : '' ?>><?= $e ?></option><?php endforeach ?></select></div></div>
            <button class="btn btn-dark btn-sm">Save</button>
            <div class="form-text">The API key is never entered in the browser. Put it in the server's <code>.env</code> as <code>ai.apiKey</code>. Without it, recruitment works fully with manual HR assessment.</div>
        </form></details>
        <?php endif ?>
    </div></div>

    <div class="card mb-3" id="tokens"><div class="card-body small">
        <div class="card-section-title">Service tokens (for an external AI agent)</div>
        <?php if ($newToken): ?><div class="alert alert-warning p-2"><strong>Copy this token now:</strong><div class="mono text-break user-select-all"><?= esc($newToken) ?></div><div class="form-text">It is stored only as a hash and cannot be shown again.</div></div><?php endif ?>
        <?php foreach ($tokens as $t): $live = $t['revoked_at'] === null && $t['expires_at'] > date('Y-m-d H:i:s'); ?>
        <div class="border-bottom py-1"><div class="d-flex justify-content-between"><span class="mono">…<?= esc($t['token_hint']) ?></span><?= $live ? status_badge('active') : status_badge($t['revoked_at'] ? 'revoked' : 'expired') ?></div>
            <div class="text-muted"><?= esc(implode(', ', json_decode((string) $t['capabilities'], true) ?: [])) ?></div>
            <div class="text-muted">Scope <?= esc((string) $t['scope']) ?> · <?= esc($t['purpose']) ?> · until <?= dmy($t['expires_at'], true) ?> · used <?= (int) $t['use_count'] ?><?= $t['max_uses'] ? '/' . (int) $t['max_uses'] : '' ?> · <?= esc((string) $t['creator']) ?></div>
            <?php if ($live && $canManage): ?><form method="post" action="<?= site_url('admin/ai/tokens/' . $t['id'] . '/revoke') ?>" data-confirm="Revoke this token now?"><?= csrf_field() ?><button class="btn btn-link btn-sm text-danger p-0">Revoke</button></form><?php endif ?></div>
        <?php endforeach ?>
        <?php if ($tokens === []): ?><p class="text-muted">No tokens. In-app AI review does not need one.</p><?php endif ?>
        <?php if ($canManage): ?>
        <details class="mt-2"><summary>Issue a short-lived token</summary>
        <form method="post" action="<?= site_url('admin/ai/tokens') ?>" class="mt-2"><?= csrf_field() ?>
            <?php foreach ($meta as $code => $m): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="capabilities[]" value="<?= esc($code) ?>" id="tk_<?= md5($code) ?>"><label class="form-check-label" for="tk_<?= md5($code) ?>"><span class="mono"><?= esc($code) ?></span></label></div><?php endforeach ?>
            <input class="form-control form-control-sm my-2" name="candidate_ids" placeholder="Candidate ids (recruitment), e.g. 12, 15" aria-label="Candidate ids">
            <input class="form-control form-control-sm mb-2" name="employee_ids" placeholder="Employee ids (payroll summary)" aria-label="Employee ids">
            <input class="form-control form-control-sm mb-2" name="periods" placeholder="Payroll periods, e.g. 2026-09" aria-label="Periods">
            <input class="form-control form-control-sm mb-2" name="purpose" placeholder="Purpose (required)" required aria-label="Purpose">
            <div class="row g-2 mb-2"><div class="col-6"><input class="form-control form-control-sm" name="ttl_hours" value="4" aria-label="Valid hours"><div class="form-text">hours (max 72)</div></div><div class="col-6"><input class="form-control form-control-sm" name="max_uses" placeholder="max uses" aria-label="Max uses"></div></div>
            <button class="btn btn-dark btn-sm">Issue token</button>
            <div class="form-text">Tokens work only on <code>/api/ai/…</code> endpoints for the capabilities, records and periods above; the capability must also be enabled here.</div>
        </form></details>
        <?php endif ?>
    </div></div>
</div>

<div class="col-xl-8">
    <?php foreach ($caps as $c): $fields = json_decode((string) $c['allowed_fields'], true) ?: []; $u = $usage[$c['code']] ?? null; $known = $meta[$c['code']]['fields'] ?? []; $scope = json_decode((string) $c['scope_config'], true) ?: []; ?>
    <form method="post" action="<?= site_url('admin/ai/capabilities/' . rawurlencode($c['code'])) ?>" class="card mb-2"><div class="card-body small"><?= csrf_field() ?>
        <div class="d-flex flex-wrap justify-content-between gap-2">
            <div><span class="mono fw-semibold"><?= esc($c['code']) ?></span> <?= $c['enabled'] ? '<span class="badge text-bg-success">ON</span>' : '<span class="badge text-bg-secondary">OFF</span>' ?><div class="text-muted"><?= esc($c['label']) ?></div></div>
            <div class="text-end text-muted">This month: <?= (int) ($u['requests'] ?? 0) ?> requests · <?= (int) ($u['denied'] ?? 0) ?> denied · <?= number_format((int) ($u['tin'] ?? 0) + (int) ($u['tout'] ?? 0)) ?> tokens · est. $<?= number_format((float) ($u['cost'] ?? 0), 2) ?> of $<?= number_format((float) $c['monthly_cost_ceiling'], 2) ?></div>
        </div>
        <fieldset <?= $canManage ? '' : 'disabled' ?>>
        <div class="row g-2 mt-1">
            <div class="col-md-5"><span class="form-label d-block">Fields the AI may receive</span><?php foreach ($known as $fld): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="fields[]" value="<?= esc($fld) ?>" id="f<?= md5($c['code'] . $fld) ?>" <?= in_array($fld, $fields, true) ? 'checked' : '' ?>><label class="form-check-label mono" for="f<?= md5($c['code'] . $fld) ?>"><?= esc($fld) ?></label></div><?php endforeach ?></div>
            <div class="col-md-7"><div class="row g-2">
                <div class="col-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="enabled" value="1" id="en<?= md5($c['code']) ?>" <?= $c['enabled'] ? 'checked' : '' ?>><label class="form-check-label" for="en<?= md5($c['code']) ?>">Enabled</label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="share_with_provider" value="1" id="sh<?= md5($c['code']) ?>" <?= $c['share_with_provider'] ? 'checked' : '' ?>><label class="form-check-label" for="sh<?= md5($c['code']) ?>">May send data to the external AI provider</label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="requires_consent" value="1" id="rc<?= md5($c['code']) ?>" <?= $c['requires_consent'] ? 'checked' : '' ?>><label class="form-check-label" for="rc<?= md5($c['code']) ?>">Requires the person's consent</label></div></div>
                <div class="col-6"><label class="form-label" for="rl<?= md5($c['code']) ?>">Requests / hour</label><input class="form-control form-control-sm mb-1" id="rl<?= md5($c['code']) ?>" name="rate_limit_per_hour" value="<?= (int) $c['rate_limit_per_hour'] ?>">
                    <label class="form-label" for="mc<?= md5($c['code']) ?>">Monthly cost ceiling (USD, 0 = blocked)</label><input class="form-control form-control-sm mb-1" id="mc<?= md5($c['code']) ?>" name="monthly_cost_ceiling" value="<?= esc($c['monthly_cost_ceiling']) ?>">
                    <label class="form-label" for="rd<?= md5($c['code']) ?>">Keep AI outputs (days)</label><input class="form-control form-control-sm" id="rd<?= md5($c['code']) ?>" name="retention_days" value="<?= (int) $c['retention_days'] ?>"></div>
                <?php if (str_starts_with($c['code'], 'ai.payroll.')): ?><div class="col-12"><label class="form-label" for="se<?= md5($c['code']) ?>">Only these employee ids (blank = per token)</label><input class="form-control form-control-sm" id="se<?= md5($c['code']) ?>" name="scope_employee_ids" value="<?= esc(implode(', ', (array) ($scope['employee_ids'] ?? []))) ?>"><div class="form-text">Read-only aggregates of finalized payroll. The AI can never edit salary, run or finalize payroll, pay anyone or see bank accounts.</div></div><?php endif ?>
            </div></div>
        </div>
        <?php if ($canManage): ?><button class="btn btn-sm btn-dark mt-2">Save <?= esc($c['code']) ?></button><?php endif ?>
        </fieldset>
    </div></form>
    <?php endforeach ?>

    <div class="table-card mt-3"><div class="card-header bg-white d-flex flex-wrap justify-content-between gap-2"><strong>Action log</strong>
        <form method="get" class="d-flex gap-1"><select name="outcome" class="form-select form-select-sm" data-autosubmit aria-label="Outcome"><option value="">All outcomes</option><?php foreach (['allowed', 'completed', 'denied', 'error'] as $o): ?><option <?= $f['outcome'] === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach ?></select>
            <select name="capability" class="form-select form-select-sm" data-autosubmit aria-label="Capability"><option value="">All capabilities</option><?php foreach ($caps as $c): ?><option <?= $f['capability'] === $c['code'] ? 'selected' : '' ?>><?= esc($c['code']) ?></option><?php endforeach ?></select></form></div>
    <div class="table-responsive"><table class="table table-sm small mb-0">
        <thead><tr><th>When</th><th>Capability</th><th>Outcome</th><th>Record</th><th>Fields sent (names only)</th><th>Who / token</th><th class="num">Tokens</th><th class="num">Est. $</th></tr></thead><tbody>
        <?php foreach ($logs as $l): ?>
        <tr><td class="text-nowrap"><?= dmy($l['occurred_at'], true) ?></td><td class="mono"><?= esc((string) $l['capability']) ?: '—' ?></td><td><?= status_badge($l['outcome']) ?><?= $l['deny_reason'] ? '<div class="text-muted">' . esc($l['deny_reason']) . '</div>' : '' ?></td>
            <td><?= esc((string) $l['record_type']) ?> <?= esc(implode(',', json_decode((string) $l['record_ids'], true) ?: [])) ?></td><td class="mono"><?= esc(implode(', ', json_decode((string) $l['field_names'], true) ?: [])) ?></td>
            <td><?= esc((string) $l['actor']) ?><?= $l['token_id'] ? ' <span class="text-muted">token #' . (int) $l['token_id'] . '</span>' : '' ?><?= $l['http_path'] ? '<div class="text-muted">' . esc($l['http_path']) . '</div>' : '' ?></td>
            <td class="num"><?= $l['input_tokens'] !== null ? number_format((int) $l['input_tokens'] + (int) $l['output_tokens']) : '' ?></td><td class="num"><?= $l['cost_estimate'] !== null ? number_format((float) $l['cost_estimate'], 4) : '' ?></td></tr>
        <?php endforeach ?>
        <?php if ($logs === []): ?><tr><td colspan="8" class="text-muted">No AI activity yet.</td></tr><?php endif ?>
        </tbody></table></div></div>
    <p class="form-text mt-2">Google Sheets: data shared to a Google Sheet is governed by that sheet's own sharing settings — DGR Office permissions cannot protect a sheet that is shared broadly.</p>
</div>
</div>
<?= $this->endSection() ?>
