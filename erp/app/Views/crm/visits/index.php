<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<a class="btn btn-primary btn-sm" href="<?= site_url('crm/visits/new') ?>"><i class="bi bi-plus-lg"></i> Schedule visit</a>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<ul class="nav nav-tabs small mb-2">
    <li class="nav-item"><a class="nav-link <?= $tab === 'upcoming' ? 'active' : '' ?>" href="<?= site_url('crm/visits') ?>">Upcoming</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'past' ? 'active' : '' ?>" href="<?= site_url('crm/visits?tab=past') ?>">Past</a></li>
</ul>
<div class="table-card"><div class="table-responsive"><table class="table table-hover small align-middle">
    <thead><tr><th>When</th><th>Where / who</th><th>Purpose</th><th>Visitor</th><th>Status</th><th>Outcome</th></tr></thead><tbody>
    <?php foreach ($rows as $v): $late = $v['status'] === 'scheduled' && $v['scheduled_at'] < date('Y-m-d H:i:s'); ?>
    <tr><td class="text-nowrap <?= $late ? 'text-danger' : '' ?>"><a href="<?= site_url('crm/visits/' . $v['id']) ?>"><?= dmy($v['scheduled_at'], true) ?></a><?= $late ? '<div class="small">outcome pending</div>' : '' ?></td>
        <td><?= esc($v['company_name'] ?? $v['client_name'] ?? $v['location']) ?><div class="text-muted"><?= esc((string) $v['address']) ?></div></td>
        <td><?= esc((string) $v['purpose']) ?></td><td><?= esc((string) $v['visitor']) ?></td><td><?= status_badge($v['status']) ?></td>
        <td><?= esc(mb_strimwidth((string) $v['outcome'], 0, 80, '…')) ?></td></tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="6"><div class="empty-state"><i class="bi bi-geo-alt"></i>No visits.</div></td></tr><?php endif ?>
</tbody></table></div></div>
<?= $this->endSection() ?>
