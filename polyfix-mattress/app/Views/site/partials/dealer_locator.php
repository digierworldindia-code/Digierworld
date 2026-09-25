<?php
/**
 * @var array $section @var bool $enabled @var list<array> $regions @var list<array> $dealers
 * @var array{state:?string, city:?string, pincode:?string} $filter
 */
$states = array_values(array_unique(array_column($regions, 'state')));
$cities = array_values(array_unique(array_column(array_filter($regions, static fn ($r) => $filter['state'] === null || $r['state'] === $filter['state']), 'city')));
?>
<section class="section-tight" id="locator">
    <div class="container">
        <h2><?= esc($section['heading'] ?? 'Dealers near you') ?></h2>
        <?php if (! $enabled): ?>
        <div class="alert alert-secondary mt-3">The dealer list is being updated. <a href="<?= site_url('contact') ?>">Contact us</a> and we will point you to your nearest dealer.</div>
        <?php else: ?>
        <form class="row g-2 align-items-end mt-3" method="get" action="<?= site_url('dealers') ?>#locator">
            <div class="col-12 col-md-3">
                <label class="form-label" for="state">State</label>
                <select class="form-select" id="state" name="state">
                    <option value="">All states</option>
                    <?php foreach ($states as $s): ?><option<?= $filter['state'] === $s ? ' selected' : '' ?>><?= esc($s) ?></option><?php endforeach ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="city">City</label>
                <select class="form-select" id="city" name="city">
                    <option value="">All cities</option>
                    <?php foreach ($cities as $c): ?><option<?= $filter['city'] === $c ? ' selected' : '' ?>><?= esc($c) ?></option><?php endforeach ?>
                </select>
            </div>
            <div class="col-8 col-md-3">
                <label class="form-label" for="pincode">PIN code</label>
                <input class="form-control" id="pincode" name="pincode" inputmode="numeric" maxlength="6" value="<?= esc($filter['pincode'] ?? '', 'attr') ?>">
            </div>
            <div class="col-4 col-md-3 d-flex gap-2">
                <button class="btn btn-primary" type="submit">Search</button>
                <?php if (array_filter($filter)): ?><a class="btn btn-link-quiet" href="<?= site_url('dealers') ?>#locator">Clear</a><?php endif ?>
            </div>
        </form>

        <?php if ($dealers === []): ?>
        <p class="text-muted-ink mt-4">No listed dealers match that search yet. <a href="<?= site_url('contact') ?>">Contact us</a> and we will point you to the nearest one.</p>
        <?php else: ?>
        <p class="small text-muted-ink mt-4"><?= count($dealers) ?> dealer<?= count($dealers) === 1 ? '' : 's' ?></p>
        <div class="row g-3">
            <?php foreach ($dealers as $d): ?>
            <div class="col-12 col-md-6 col-lg-4">
                <article class="dealer-card card h-100"><div class="card-body">
                    <h3 class="h5 mb-1"><?= esc($d['business_name']) ?></h3>
                    <?php if ($d['is_showroom']): ?><span class="badge badge-positive mb-2">Showroom</span><?php endif ?>
                    <address class="small text-muted-ink mb-2">
                        <?= esc(implode(', ', array_filter([$d['address_line1'], $d['address_line2']]))) ?><br>
                        <?= esc($d['city']) ?>, <?= esc($d['state']) ?> <?= esc($d['pincode']) ?>
                    </address>
                    <a class="small" href="tel:<?= esc(preg_replace('/[^0-9+]/', '', $d['phone']), 'attr') ?>"><i class="bi bi-telephone" aria-hidden="true"></i> <?= esc($d['phone']) ?></a>
                </div></article>
            </div>
            <?php endforeach ?>
        </div>
        <?php endif ?>
        <?php endif ?>
    </div>
</section>
