<?= $this->extend('layouts/floor') ?>

<?php
/**
 * The agent's copy of the verification report.
 *
 * @var array<string,mixed>       $s
 * @var list<array<string,mixed>> $items
 * @var array<string,int>         $counts
 * @var string                    $agentCode
 */
?>

<?= $this->section('content') ?>

<div class="page-head no-print d-flex justify-content-between align-items-start gap-2">
    <div>
        <h1>Stock verification report</h1>
        <p><span class="mono"><?= esc($s['reference']) ?></span> · <?= esc(humanise($s['status'])) ?></p>
    </div>
    <button class="btn btn-primary btn-floor" type="button" data-print>
        <i class="bi bi-printer me-1" aria-hidden="true"></i>Print / PDF
    </button>
</div>

<?= view('partials/survey_report', compact('s', 'items', 'counts', 'agentCode')) ?>

<?= $this->endSection() ?>
