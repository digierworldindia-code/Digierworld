<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<form class="filter-bar row g-2" method="get">
    <div class="col-md-4"><select name="job" class="form-select form-select-sm" data-autosubmit aria-label="Vacancy"><option value="">All vacancies</option><?php foreach ($jobs as $id => $l): ?><option value="<?= $id ?>" <?= $f['job'] === (int) $id ? 'selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></div>
    <div class="col-6 col-md-2"><input name="score_min" value="<?= esc((string) $f['score_min']) ?>" class="form-control form-control-sm" placeholder="score ≥" inputmode="decimal" aria-label="Minimum score"></div>
    <div class="col-6 col-md-2"><input type="date" name="from" value="<?= esc((string) $f['from']) ?>" class="form-control form-control-sm" aria-label="Applied from"></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Filter</button></div>
    <div class="col-12 form-text mt-0">Cards are sorted by score within each stage. Moving a card is a human decision and is recorded on the applicant's timeline; rejections and holds need a reason.</div>
</form>
<div class="kanban d-flex gap-2 overflow-auto pb-2">
<?php foreach ($cols as $k => $col): ?>
<div class="u-w-240px kanban-col flex-shrink-0">
    <div class="small fw-semibold mb-1"><?= $col['label'] ?> <span class="text-muted"><?= count($col['items']) ?></span></div>
    <?php foreach ($col['items'] as $r): $score = $r['hr_score'] ?? $r['ai_fit']; ?>
    <div class="card mb-2"><div class="card-body p-2 small">
        <div class="d-flex justify-content-between"><a class="fw-semibold" href="<?= site_url('hr/candidates/' . $r['id']) ?>"><?= esc($r['full_name']) ?></a><?= $score !== null ? '<span class="badge ' . ($r['hr_score'] !== null ? 'badge-gold' : 'badge-neutral') . '" title="' . ($r['hr_score'] !== null ? 'HR score' : 'AI fit (decision support)') . '">' . number_format((float) $score, 1) . '</span>' : '' ?></div>
        <div class="text-muted"><?= esc($r['job_code']) ?> · <?= dmy($r['application_date']) ?></div>
        <?php if (can(['hr.candidate.decide', 'hr.candidate.edit']) && ! in_array($k, ['joined', 'rejected'], true)): ?>
        <details class="mt-1"><summary class="text-muted">Move…</summary>
        <form method="post" action="<?= site_url('hr/candidates/' . $r['id'] . '/status') ?>" class="mt-1"><?= csrf_field() ?><input type="hidden" name="back" value="board">
            <select class="form-select form-select-sm mb-1" name="status" aria-label="New stage"><?php foreach (\App\Services\Hr\RecruitmentService::STATUSES as $s => $l): if ($s === 'joined' || $s === $k) { continue; } ?><option value="<?= $s ?>"><?= $l ?></option><?php endforeach ?></select>
            <input class="form-control form-control-sm mb-1" name="notes" placeholder="Reason / note" aria-label="Note">
            <button class="btn btn-sm btn-primary w-100">Move</button></form></details>
        <?php endif ?>
    </div></div>
    <?php endforeach ?>
</div>
<?php endforeach ?>
</div>
<?= $this->endSection() ?>
