<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array<string, list<array>> $groups */ ?>
<div class="page-head"><div><h1>Settings</h1><p>Changed here, in force immediately. Secrets are not kept in the database and cannot be set from this screen.</p></div></div>
<?php foreach ($groups as $category => $rows): ?>
<div class="panel">
    <div class="panel-head"><h2><?= esc(ucfirst($category)) ?></h2></div>
    <table class="table"><tbody>
    <?php foreach ($rows as $s): ?>
        <?php
        $decoded = json_decode((string) $s['value'], true);
        $isBool  = is_bool($decoded);
        $display = $isBool ? ($decoded ? 'true' : 'false') : (is_scalar($decoded) ? (string) $decoded : (string) $s['value']);
        ?>
        <tr>
            <td style="width:22rem"><span class="mono small"><?= esc($s['key']) ?></span>
                <?php if ($s['description']): ?><div class="small text-muted"><?= esc($s['description']) ?></div><?php endif ?></td>
            <td>
                <?php if ($ctx->can('system:settings:write')): ?>
                <form class="d-flex gap-2" method="post" action="<?= site_url('admin/settings/' . rawurlencode($s['key'])) ?>">
                    <?= csrf_field() ?>
                    <?php if ($isBool): ?>
                    <select class="form-select form-select-sm" name="value" style="max-width:8rem" aria-label="<?= esc($s['key'], 'attr') ?>">
                        <option value="true"<?= $decoded ? ' selected' : '' ?>>true</option>
                        <option value="false"<?= $decoded ? '' : ' selected' ?>>false</option>
                    </select>
                    <?php else: ?>
                    <input class="form-control form-control-sm" name="value" value="<?= esc($display, 'attr') ?>" maxlength="500" aria-label="<?= esc($s['key'], 'attr') ?>">
                    <?php endif ?>
                    <button class="btn btn-light btn-sm" type="submit">Save</button>
                </form>
                <?php else: ?><span class="mono small"><?= esc($display) ?></span><?php endif ?>
            </td>
            <td class="small text-muted text-nowrap" style="width:10rem"><?= esc(local_time($s['updated_at'], 'd M Y')) ?></td>
        </tr>
    <?php endforeach ?>
    </tbody></table>
</div>
<?php endforeach ?>
<p class="small text-muted">Changing a setting is recorded in the audit trail with its previous value.</p>
<?= $this->endSection() ?>
