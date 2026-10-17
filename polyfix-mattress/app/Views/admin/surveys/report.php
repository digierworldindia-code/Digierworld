<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php
/**
 * The office's copy of the verification report — the same document the
 * agent signed, rendered from the same partial so the two cannot drift.
 *
 * @var array<string,mixed>       $s
 * @var list<array<string,mixed>> $items
 * @var array<string,int>         $counts
 * @var string                    $agentCode
 */
?>
<div class="page-head no-print">
    <div>
        <div class="small"><a href="<?= site_url('admin/surveys/' . $s['id']) ?>">Survey <?= esc($s['reference']) ?></a></div>
        <h1>Stock verification report</h1>
        <p><?= esc($s['dealer']) ?> · <?= esc(humanise($s['status'])) ?></p>
    </div>
    <button class="btn btn-primary" type="button" data-print><i class="bi bi-printer me-1"></i>Print / PDF</button>
</div>

<?= view('partials/survey_report', compact('s', 'items', 'counts', 'agentCode')) ?>
<?= $this->endSection() ?>
