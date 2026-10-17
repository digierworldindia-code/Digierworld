<?= $this->extend('layouts/portal') ?>
<?= $this->section('content') ?>
<?php
/**
 * Warranty reviews opened against this dealer's warranties.
 *
 * @var list<array<string,mixed>> $rows
 * @var array<string,string>      $reasons
 */
$tone = [
    'RECOMMENDED'           => 'warning',
    'UNDER_REVIEW'          => 'info',
    'INFORMATION_REQUESTED' => 'warning',
    'UPHELD'                => 'danger',
    'DISMISSED'             => 'success',
    'WITHDRAWN'             => 'secondary',
];
$label = [
    'RECOMMENDED'           => 'Being looked at',
    'UNDER_REVIEW'          => 'Being looked at',
    'INFORMATION_REQUESTED' => 'Waiting on you',
    'UPHELD'                => 'Warranty rejected',
    'DISMISSED'             => 'Warranty stands',
    'WITHDRAWN'             => 'Withdrawn',
];
?>
<div class="page-head">
    <h1>Warranty reviews</h1>
    <p>Where something about a sale's paperwork has been questioned. Nothing changes until it is decided.</p>
</div>

<?php if ($rows === []): ?>
    <div class="panel p-4 text-center text-muted">Nothing is under review.</div>
<?php else: ?>
    <div class="panel">
        <ul class="list-unstyled mb-0">
            <?php foreach ($rows as $row): ?>
                <li class="border-bottom">
                    <a class="d-block p-3 text-decoration-none text-body" href="<?= site_url('dealer/reviews/' . $row['id']) ?>">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <span class="mono fw-semibold"><?= esc($row['serial_number']) ?></span>
                                <span class="d-block small"><?= esc($row['product_name']) ?> · <?= esc($row['size_label']) ?></span>
                            </div>
                            <span class="badge text-bg-<?= $tone[$row['status']] ?? 'secondary' ?>">
                                <?= esc($label[$row['status']] ?? humanise($row['status'])) ?>
                            </span>
                        </div>
                        <div class="small text-muted mt-1">
                            <span class="mono"><?= esc($row['reference']) ?></span>
                            · <?= esc($reasons[$row['reason']] ?? $row['reason']) ?>
                            · <?= esc(local_date($row['raised_at'])) ?>
                        </div>
                    </a>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
<?php endif ?>
<?= $this->endSection() ?>
