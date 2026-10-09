<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?php $canManage = auth()->user()->can('disputes.manage'); ?>
<?= view('components/page_header', ['title' => 'Dispute ' . $d['dispute_number'], 'subtitle' => $d['subject'], 'breadcrumbs' => ['Disputes' => 'admin/disputes', $d['dispute_number'] => null]]) ?>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="bc-card mb-4"><div class="bc-card-body"><dl class="dl-grid small"><dt>Order</dt><dd><a href="<?= site_url('admin/orders/' . $order['id']) ?>"><?= esc($order['order_number']) ?></a> · <?= status_badge($order['status']) ?></dd><dt>Raised by</dt><dd><?= esc($raisedBy['legal_name']) ?> (<?= esc($raisedBy['company_type']) ?>)</dd><dt>Against</dt><dd><?= esc($against['legal_name']) ?> (<?= esc($against['company_type']) ?>)</dd><dt>Category</dt><dd><?= esc($categories[$d['category']]) ?></dd><dt>Requested</dt><dd><?= esc($d['desired_resolution'] ?? '—') ?></dd></dl><p class="mb-0" style="white-space:pre-line"><?= esc($d['description']) ?></p></div></div>
        <div class="bc-card"><div class="bc-card-header"><h2>Conversation</h2></div><div class="bc-card-body d-grid gap-2">
            <?php foreach ($messages as $m): ?><div class="chat-msg small <?= $m['is_internal'] ? 'internal' : ($m['sender_side'] === 'admin' ? 'mine' : '') ?>"><strong><?= $m['is_internal'] ? 'Internal note' : esc(ucfirst($m['sender_side'] === 'admin' ? 'BearingCave' : $m['sender_side'])) ?></strong> · <?= fdt($m['created_at']) ?><div style="white-space:pre-line"><?= esc($m['message']) ?></div></div><?php endforeach ?>
            <?php if ($canManage && $d['status'] !== 'closed'): ?><form method="post" action="<?= site_url('admin/disputes/' . $d['id'] . '/reply') ?>"><?= csrf_field() ?><label class="form-label" for="am">Message</label><textarea id="am" class="form-control form-control-sm mb-2" name="message" rows="3" required></textarea><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="internal" value="1" id="int"><label class="form-check-label small" for="int">Internal note (not visible to parties)</label></div><button class="btn btn-sm btn-primary" type="submit">Post</button></form><?php endif ?>
        </div></div>
    </div>
    <div class="col-xl-4">
        <div class="bc-card mb-3"><div class="bc-card-body"><p class="small">Status: <?= status_badge($d['status'], $statuses[$d['status']]) ?></p>
            <?php if ($canManage): ?><form method="post" action="<?= site_url('admin/disputes/' . $d['id'] . '/assign') ?>" class="d-flex gap-1 mb-3"><?= csrf_field() ?><select class="form-select form-select-sm" name="assigned_to" aria-label="Assign to"><option value="">Unassigned</option><?php foreach ($agents as $id => $n): ?><option value="<?= $id ?>"<?= (int) $d['assigned_to'] === (int) $id ? ' selected' : '' ?>><?= esc($n) ?></option><?php endforeach ?></select><button class="btn btn-sm btn-light" type="submit">Assign</button></form>
            <?php if ($d['status'] !== 'closed'): ?><form method="post" action="<?= site_url('admin/disputes/' . $d['id'] . '/resolve') ?>"><?= csrf_field() ?><?= select_field('status', 'Outcome', ['resolved' => 'Resolved', 'rejected' => 'Rejected (not upheld)', 'closed' => 'Closed'], null, ['placeholder' => 'Select']) ?><?= textarea_field('resolution_notes', 'Resolution notes (sent to both parties)', $d['resolution_notes'], ['rows' => 3, 'required' => true]) ?><button class="btn btn-sm btn-primary w-100" type="submit">Record outcome</button></form><?php endif ?><?php endif ?>
        </div></div>
        <div class="bc-card"><div class="bc-card-header"><h2>Evidence</h2></div><div class="bc-card-body small"><?php foreach ($documents as $doc): ?><a href="<?= site_url('documents/' . $doc['uuid']) ?>" target="_blank"><?= esc($doc['title']) ?></a><br><?php endforeach ?><?= $documents ? '' : '<span class="text-muted">None</span>' ?></div></div>
    </div>
</div>
<?= $this->endSection() ?>
