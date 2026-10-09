<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => $title, 'breadcrumbs' => ['Membership plans' => 'admin/memberships', $plan['name'] => null]]) ?>
<form method="post" action="<?= site_url('admin/memberships/' . $plan['id']) ?>" novalidate><?= csrf_field() ?>
<div class="row g-4">
    <div class="col-xl-4"><div class="bc-card"><div class="bc-card-body">
        <?= field('name', 'Plan name', $plan['name'], ['required' => true]) ?>
        <div class="row g-2"><div class="col-7"><?= field('annual_fee', 'Fee per term', $plan['annual_fee'], ['required' => true, 'class' => '']) ?></div><div class="col-5"><?= select_field('currency', 'Currency', currency_options(), $plan['currency'], ['class' => '']) ?></div></div>
        <?= field('duration_months', 'Term (months)', $plan['duration_months'], ['type' => 'number', 'required' => true]) ?>
        <?= textarea_field('description', 'Description', $plan['description'], ['rows' => 3]) ?>
        <?= select_field('is_active', 'Active', ['1' => 'Yes', '0' => 'No'], $plan['is_active']) ?>
        <?= select_field('approval_status', 'Client approval', ['pending_approval' => 'Pending client approval', 'approved' => 'Approved by client'], $plan['approval_status']) ?>
        <p class="small text-muted">Requires verification: <?= $plan['requires_verification'] ? 'yes' : 'no' ?> · Default plan: <?= $plan['is_default'] ? 'yes' : 'no' ?></p>
    </div></div></div>
    <div class="col-xl-8"><div class="bc-card"><div class="bc-card-header"><h2>Entitlements</h2></div><div class="table-responsive"><table class="table table-bc mb-0"><thead><tr><th>Feature</th><th class="text-center">Included</th><th style="width:140px">Limit</th></tr></thead><tbody>
        <?php foreach ($features as $k => [$label]): $e = $ents[$k] ?? ['is_enabled' => 0, 'limit_value' => null]; ?>
        <tr><td><label for="e<?= $k ?>"><?= esc($label) ?></label><div class="small text-muted part-no"><?= esc($k) ?></div></td>
            <td class="text-center"><input type="hidden" name="ent[<?= $k ?>]" value="0"><input class="form-check-input" type="checkbox" name="ent[<?= $k ?>]" value="1" id="e<?= $k ?>"<?= $e['is_enabled'] ? ' checked' : '' ?>></td>
            <td><?php if (str_contains($label, 'limit')): ?><input class="form-control form-control-sm" name="limit[<?= $k ?>]" value="<?= esc((string) ($e['limit_value'] ?? ''), 'attr') ?>" placeholder="Unlimited" inputmode="numeric" aria-label="Limit for <?= esc($label, 'attr') ?>"><?php endif ?></td></tr>
        <?php endforeach ?>
    </tbody></table></div></div>
    <button class="btn btn-primary mt-3" type="submit" data-confirm="Save plan? Access changes take effect immediately for all companies on this plan.">Save plan</button></div>
</div>
</form>
<?= $this->endSection() ?>
