<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Documents', 'subtitle' => 'All files your company has uploaded. Private documents are visible only to your team and authorised BearingCave staff.', 'actions' => '<a class="btn btn-primary btn-sm" href="' . site_url($area . '/verification#documents') . '"><i class="bi bi-upload me-1" aria-hidden="true"></i>Upload verification document</a>']) ?>
<div class="bc-card">
<?php if (! $documents): ?>
    <?= empty_state('bi-folder2-open', 'No documents yet', 'Upload registration, tax, bank and certification documents from the Verification page.') ?>
<?php else: ?>
    <div class="table-responsive"><table class="table table-bc table-stack mb-0">
        <thead><tr><th>Title</th><th>Related to</th><th>Type</th><th>Version</th><th>Expires</th><th>Visibility</th><th>Review</th><th>Uploaded</th></tr></thead>
        <tbody>
        <?php foreach ($documents as $d): $expiring = $d['expires_on'] && $d['expires_on'] <= date('Y-m-d', strtotime('+30 days')); ?>
            <tr>
                <td data-label="Title"><a href="<?= site_url('documents/' . $d['uuid']) ?>"><?= esc($d['title']) ?></a><?= $d['is_current'] ? '' : ' <span class="small text-muted">(old version)</span>' ?></td>
                <td data-label="Related to" class="small"><?= esc(str_replace('_', ' ', $d['entity_type'])) ?><?= $d['entity_id'] ? ' #' . (int) $d['entity_id'] : '' ?></td>
                <td data-label="Type" class="small"><?= esc(str_replace('_', ' ', $d['doc_type'])) ?></td>
                <td data-label="Version">v<?= (int) $d['version'] ?></td>
                <td data-label="Expires"><?= $d['expires_on'] ? ($expiring ? '<span class="text-danger fw-semibold">' . fdate($d['expires_on']) . '</span>' : fdate($d['expires_on'])) : '—' ?></td>
                <td data-label="Visibility"><?= status_badge('neutral', ucfirst(str_replace('_', ' ', $d['visibility']))) ?></td>
                <td data-label="Review"><?= status_badge($d['review_status']) ?></td>
                <td data-label="Uploaded" class="small"><?= fdate($d['created_at']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
<?php endif ?>
</div>
<?= $this->endSection() ?>
