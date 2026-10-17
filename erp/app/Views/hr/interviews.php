<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<ul class="nav nav-tabs small mb-2"><?php foreach (['upcoming' => 'Upcoming', 'mine' => 'My interviews', 'past' => 'Past'] as $k => $l): ?><li class="nav-item"><a class="nav-link <?= $view === $k ? 'active' : '' ?>" href="<?= site_url('hr/interviews?view=' . $k) ?>"><?= $l ?></a></li><?php endforeach ?></ul>
<div class="table-card"><div class="table-responsive"><table class="table align-middle small mb-0">
    <thead><tr><th>When</th><th>Applicant</th><th>Round</th><th>Mode</th><th>Interviewer</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $iv): ?>
    <tr><td class="text-nowrap"><?= dmy($iv['scheduled_at'], true) ?><div class="text-muted"><?= (int) $iv['duration_minutes'] ?> min</div></td>
        <td><a href="<?= site_url('hr/candidates/' . $iv['candidate_id']) ?>"><?= esc($iv['full_name']) ?></a><div class="text-muted"><?= esc($iv['job_title']) ?></div></td>
        <td><?= (int) $iv['round_no'] ?> · <?= esc($types[$iv['interview_type']] ?? $iv['interview_type']) ?></td>
        <td><?= esc(str_replace('_', ' ', $iv['mode'])) ?><?= $iv['location_or_link'] ? '<div class="text-muted text-break">' . esc($iv['location_or_link']) . '</div>' : '' ?></td>
        <td><?= esc((string) $iv['interviewer']) ?></td>
        <td><?= status_badge($iv['status']) ?> <?= $iv['status'] === 'completed' ? status_badge($iv['result']) : '' ?>
            <?php if ($iv['status'] === 'scheduled'): ?><details><summary class="small">Record outcome</summary><?= view('hr/_interview_complete', ['iv' => $iv, 'back' => 'list']) ?></details><?php endif ?></td></tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="6"><div class="empty-state"><i class="bi bi-chat-square-text"></i>No interviews here. Schedule them from an applicant's page.</div></td></tr><?php endif ?>
</tbody></table></div></div>
<?= $this->endSection() ?>
