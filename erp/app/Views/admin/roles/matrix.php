<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<p class="small text-muted">Read-only overview of every role's grants. Edit a role to change it. Super Admin roles hold everything.</p>
<div class="table-card"><div class="u-mxh-75vh table-responsive">
<table class="table table-sm table-bordered perm-matrix small mb-0">
    <thead class="sticky-top"><tr><th>Permission</th><?php foreach ($roles as $r): ?><th class="rot text-center"><a href="<?= site_url('admin/roles/' . $r['id']) ?>"><?= esc($r['name']) ?></a></th><?php endforeach ?></tr></thead>
    <tbody>
    <?php foreach ($modules as $mod => $def): ?>
        <tr><th colspan="<?= count($roles) + 1 ?>"><?= esc($def['label']) ?></th></tr>
        <?php foreach ($def['perms'] as $key => $meta): ?>
            <tr><td class="pk"><code><?= esc($key) ?></code><?= ! empty($meta[1]['danger']) ? ' <span class="perm-danger">⚠</span>' : '' ?></td>
            <?php foreach ($roles as $r): $on = (int) $r['is_super'] === 1 || isset($grant[$key][(int) $r['id']]); ?>
                <td><?= $on ? '<i class="bi bi-check-lg text-success" aria-label="granted"></i>' : '<span class="text-muted">·</span>' ?></td>
            <?php endforeach ?></tr>
        <?php endforeach ?>
    <?php endforeach ?>
    </tbody>
</table>
</div></div>
<?= $this->endSection() ?>
