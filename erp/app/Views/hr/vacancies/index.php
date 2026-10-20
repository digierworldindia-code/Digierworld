<?= $this->extend('layouts/app') ?>
<?= $this->section('pageActions') ?>
<?php if (can('hr.vacancy.manage')): ?><a href="<?= site_url('hr/vacancies/new') ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> New vacancy</a><?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<ul class="nav nav-tabs small mb-2"><?php foreach (['active' => 'Active', 'open' => 'Open', 'filled' => 'Filled', 'closed' => 'Closed', 'all' => 'All'] as $k => $l): ?><li class="nav-item"><a class="nav-link <?= $status === $k ? 'active' : '' ?>" href="<?= site_url('hr/vacancies?status=' . $k) ?>"><?= $l ?></a></li><?php endforeach ?></ul>
<div class="table-card"><div class="table-responsive"><table class="table table-hover align-middle small mb-0">
    <thead><tr><th>Code</th><th>Role</th><th>Department</th><th>Experience</th><?= $salaryVisible ? '<th>Salary range</th>' : '' ?><th class="num">Openings</th><th class="num">Applicants</th><th>JD</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
    <tr><td class="mono"><?= esc($r['code']) ?></td><td><a class="fw-semibold" href="<?= site_url('hr/vacancies/' . $r['id']) ?>"><?= esc($r['title']) ?></a><div class="text-muted"><?= esc((string) $r['location']) ?> · <?= esc($r['work_mode']) ?><?= $r['recruiter'] ? ' · ' . esc($r['recruiter']) : '' ?></div></td>
        <td><?= esc((string) $r['department']) ?></td><td><?= $r['experience_min'] !== null ? (float) $r['experience_min'] . '–' . (float) $r['experience_max'] . ' yrs' : '—' ?></td>
        <?= $salaryVisible ? '<td>' . ($r['salary_min'] ? inr($r['salary_min'], false) . '–' . inr($r['salary_max'], false) : '—') . '</td>' : '' ?>
        <td class="num"><?= (int) $r['openings_count'] ?></td><td class="num"><a href="<?= site_url('hr/candidates?job=' . $r['id']) ?>"><?= (int) $r['applicants'] ?></a><?= $r['shortlisted'] ? '<div class="text-success">' . (int) $r['shortlisted'] . ' shortlisted</div>' : '' ?></td>
        <td>v<?= (int) $r['jd_version'] ?></td><td><?= status_badge($r['status']) ?></td></tr>
    <?php endforeach ?>
    <?php if ($rows === []): ?><tr><td colspan="9"><div class="empty-state"><i class="bi bi-briefcase"></i>No vacancies. Create one with its job description before uploading CVs.</div></td></tr><?php endif ?>
</tbody></table></div></div>
<?= $this->endSection() ?>
