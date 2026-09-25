<?php
/**
 * The verification form and, when a lookup has run, its result.
 *
 * Every value shown comes from VerificationService's allow-list — there is no
 * customer, dealer, price or claim history to display, because it is never
 * returned.
 *
 * @var array       $section  the CMS verify-form block (heading, body)
 * @var array|null  $result
 * @var string|null $serial
 * @var string|null $shortcut
 */
$badges = [
    'ACTIVE'        => ['badge-positive', 'Active'],
    'EXPIRED'       => ['badge-neutral', 'Expired'],
    'VOID'          => ['badge-critical', 'Void'],
    'SUPERSEDED'    => ['badge-caution', 'Replaced under warranty'],
    'NOT_ACTIVATED' => ['badge-caution', 'Not activated yet'],
];
?>
<section class="section-tight" id="verify">
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-5">
                <h2><?= esc($section['heading'] ?? 'Check a serial number') ?></h2>
                <?php if (! empty($section['body'])): ?><p class="text-muted-ink mt-3"><?= esc($section['body']) ?></p><?php endif ?>
                <form class="card mt-4" method="get" action="<?= site_url('warranty/verify') ?>" novalidate>
                    <div class="card-body">
                        <label class="form-label" for="serial">Mattress serial number</label>
                        <input class="form-control form-control-lg text-uppercase" id="serial" name="serial" maxlength="20" autocomplete="off"
                               autocapitalize="characters" spellcheck="false" required placeholder="<?= esc(brand('serialPrefix'), 'attr') ?>26000001"
                               value="<?= esc($serial ?? '', 'attr') ?>" aria-describedby="serial-hint">
                        <div class="form-text" id="serial-hint">Printed on the law label stitched to the side panel, near the foot of the mattress.</div>
                        <div class="d-flex flex-wrap align-items-center gap-3 mt-3">
                            <button class="btn btn-primary" type="submit">Verify mattress</button>
                            <span class="small text-muted-ink">Or scan the QR code on the label with your phone camera.</span>
                        </div>
                    </div>
                </form>
            </div>

            <div class="col-lg-7" aria-live="polite">
                <?php if ($result !== null && ! $result['found']): ?>
                <div class="verify-result">
                    <div class="verify-result__head is-missing">
                        <span class="badge badge-critical">Not recognised</span>
                        <h2 class="mt-2">We have no record of that serial number</h2>
                    </div>
                    <div class="verify-result__body">
                        <p class="text-muted-ink"><?= esc($result['note']) ?></p>
                        <p class="small mb-0">If you believe this is a genuine <?= esc(brand('shortName')) ?> product, <a href="<?= site_url('contact') ?>">contact us</a> with a photograph of the label.</p>
                    </div>
                </div>
                <?php elseif ($result !== null): ?>
                <?php [$class, $label] = $badges[$result['warranty']['status']] ?? $badges['NOT_ACTIVATED']; ?>
                <div class="verify-result">
                    <div class="verify-result__head">
                        <span class="badge badge-positive">Genuine <?= esc(brand('shortName')) ?> product</span>
                        <h2 class="mt-2"><?= esc($result['product']['name']) ?></h2>
                    </div>
                    <div class="verify-result__body">
                        <p class="text-muted-ink"><?= esc($result['note']) ?></p>
                        <table class="spec-table">
                            <caption class="visually-hidden">Verification details for this mattress</caption>
                            <tbody>
                                <tr><th scope="row">Serial number</th><td class="font-monospace"><?= esc($result['serial']) ?></td></tr>
                                <tr><th scope="row">Product</th><td><a href="<?= site_url('mattresses/' . $result['product']['slug']) ?>"><?= esc($result['product']['name']) ?></a></td></tr>
                                <tr><th scope="row">Size</th><td><?= esc($result['product']['size']) ?></td></tr>
                                <tr><th scope="row">Comfort level</th><td><?= esc($result['product']['comfort']) ?></td></tr>
                                <tr><th scope="row">Manufactured</th><td><?= esc(local_date($result['manufactured_on'])) ?></td></tr>
                                <tr><th scope="row">Warranty status</th><td><span class="badge <?= $class ?>"><?= esc($label) ?></span></td></tr>
                                <?php if ($result['warranty']['start'] !== null): ?>
                                <tr><th scope="row">Warranty period</th><td><?= esc(local_date($result['warranty']['start'])) ?> to <?= esc(local_date($result['warranty']['end'])) ?> (<?= (int) $result['warranty']['years'] ?> years)</td></tr>
                                <?php endif ?>
                                <?php if ($result['warranty']['status'] === 'ACTIVE' && $result['warranty']['days_remaining'] > 0): ?>
                                <tr><th scope="row">Time remaining</th><td><?= (int) floor($result['warranty']['days_remaining'] / 30) ?> months</td></tr>
                                <?php endif ?>
                            </tbody>
                        </table>
                        <p class="small text-muted-ink mt-3 mb-0">Need to raise a claim? Contact the dealer you bought from — they raise it against this serial number. <a href="<?= site_url('warranty') ?>#claim">How claims work</a>.</p>
                    </div>
                </div>
                <?php if ($shortcut !== null): ?>
                <div class="dealer-shortcut mt-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span class="small"><i class="bi bi-shop" aria-hidden="true"></i> You are signed in as a dealer.</span>
                    <a class="btn btn-primary btn-sm" href="<?= esc($shortcut, 'attr') ?>">Open in dealer portal</a>
                </div>
                <?php endif ?>
                <?php endif ?>
            </div>
        </div>
    </div>
</section>
