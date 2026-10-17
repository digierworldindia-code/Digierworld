<?php
/**
 * DEALER STOCK VERIFICATION REPORT — the document itself.
 *
 * A partial rather than a page, so the agent who made the visit and the
 * office reviewing it read exactly the same thing. Printing is also how it
 * becomes a PDF: every browser can save a page as one, which beats a second
 * rendering path that would drift from this one.
 *
 * The figures passed in are the ones frozen onto the survey when it was
 * submitted. Recomputing them now would quietly rewrite the past every time
 * stock moved, which is the opposite of what a verification report is for.
 *
 * @var array<string,mixed>       $s
 * @var list<array<string,mixed>> $items
 * @var array<string,int>         $counts
 * @var string                    $agentCode
 */
$outcomeTone = [
    'MATCHED'                  => 'success',
    'POSSIBLE_UNRECORDED_SALE' => 'warning',
    'REVIEW_REQUIRED'          => 'warning',
    'STOCK_DISCREPANCY'        => 'danger',
    'DAMAGED'                  => 'danger',
];
$observations = [
    'PRESENT'          => 'Physically available',
    'DEALER_SAYS_SOLD' => 'Dealer says sold',
    'NOT_FOUND'        => 'Not found',
    'DAMAGED'          => 'Damaged',
    'OTHER'            => 'Other',
];
?>
<div class="report">
    <header class="report__head">
        <div>
            <div class="report__brand"><?= brand_wordmark() ?></div>
            <div class="report__sub"><?= esc(brand('business')) ?> · <?= esc(brand('website')) ?></div>
        </div>
        <div class="text-end">
            <div class="fw-semibold">Dealer stock verification report</div>
            <div class="mono"><?= esc($s['reference']) ?></div>
        </div>
    </header>

    <section class="report__meta">
        <dl>
            <dt>Dealer</dt>
            <dd><?= esc($s['dealer']) ?> <span class="mono">(<?= esc($s['dealer_code']) ?>)</span></dd>

            <dt>Address</dt>
            <dd><?= esc(trim(($s['address_line1'] ?? '') . ', ' . ($s['city'] ?? '') . ', ' . ($s['state'] ?? ''), ', ')) ?></dd>

            <dt>Agent</dt>
            <dd><?= esc($s['agent']) ?> <span class="mono">(<?= esc($agentCode) ?>)</span></dd>

            <dt>Visited</dt>
            <dd><?= esc(local_time($s['started_at'])) ?></dd>

            <dt>Submitted</dt>
            <dd><?= $s['submitted_at'] !== null ? esc(local_time($s['submitted_at'])) : 'not yet submitted' ?></dd>

            <?php if ($s['reviewer'] !== null): ?>
                <dt>Reviewed by</dt>
                <dd><?= esc($s['reviewer']) ?> on <?= esc(local_date($s['reviewed_at'])) ?></dd>
            <?php endif ?>
        </dl>
    </section>

    <section>
        <h2 class="h2">Summary</h2>
        <table class="table report__summary">
            <tbody>
                <tr><th>Mattresses on the records</th><td><?= (int) $counts['system_total'] ?></td></tr>
                <tr><th>— recorded as in stock</th><td><?= (int) $counts['system_available'] ?></td></tr>
                <tr><th>— recorded as sold</th><td><?= (int) $counts['system_sold'] ?></td></tr>
                <tr><th>Checked on this visit</th><td><?= (int) $counts['examined'] ?></td></tr>
                <tr><th>Physically available</th><td><?= (int) $counts['physically_found'] ?></td></tr>
                <tr><th>Dealer says sold</th><td><?= (int) $counts['reported_sold'] ?></td></tr>
                <tr><th>Not found</th><td><?= (int) $counts['not_found'] ?></td></tr>
                <tr><th>Damaged</th><td><?= (int) $counts['damaged'] ?></td></tr>
                <tr class="fw-semibold"><th>Needing attention</th><td><?= (int) $counts['discrepancies'] ?></td></tr>
            </tbody>
        </table>
    </section>

    <?php if ($s['agent_remarks'] !== null && $s['agent_remarks'] !== ''): ?>
        <section>
            <h2 class="h2">Agent's remarks</h2>
            <p class="report__remarks"><?= nl2br(esc($s['agent_remarks'])) ?></p>
        </section>
    <?php endif ?>

    <section>
        <h2 class="h2">Unit by unit</h2>
        <?php if ($items === []): ?>
            <p class="text-muted">Nothing was recorded on this visit.</p>
        <?php else: ?>
            <table class="table report__items">
                <thead>
                    <tr><th>Serial</th><th>Model and size</th><th>Records said</th><th>Agent found</th><th>Outcome</th></tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td class="mono"><?= esc($item['serial_number']) ?></td>
                        <td><?= esc($item['product_name']) ?><span class="d-block small text-muted"><?= esc($item['size_label']) ?></span></td>
                        <td><?= esc(humanise($item['system_status'])) ?></td>
                        <td><?= esc($observations[$item['observation']] ?? $item['observation']) ?>
                            <?= $item['remark'] !== null ? '<span class="d-block small text-muted">' . esc($item['remark']) . '</span>' : '' ?></td>
                        <td><span class="badge text-bg-<?= $outcomeTone[$item['outcome']] ?? 'secondary' ?>"><?= esc(humanise($item['outcome'])) ?></span></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </section>

    <footer class="report__foot">
        <p>
            Findings recorded by the field agent on the date of the visit. A difference between
            the records and what was found is raised for verification; it does not by itself change
            any sale, warranty or stock record.
        </p>
        <div class="report__signatures">
            <div><span></span>Agent</div>
            <div><span></span>Dealer</div>
        </div>
    </footer>
</div>
