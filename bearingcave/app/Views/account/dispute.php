<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Dispute ' . $d['dispute_number'], 'subtitle' => $d['subject'], 'breadcrumbs' => ['Disputes' => $area . '/disputes', $d['dispute_number'] => null]]) ?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="bc-card mb-4"><div class="bc-card-body">
            <p class="small-caps mb-1">Original complaint</p>
            <p style="white-space:pre-line"><?= esc($d['description']) ?></p>
            <?php if ($d['desired_resolution']): ?><p class="small mb-0"><strong>Requested resolution:</strong> <?= esc($d['desired_resolution']) ?></p><?php endif ?>
        </div></div>
        <div class="bc-card mb-4"><div class="bc-card-header"><h2>Conversation</h2></div><div class="bc-card-body d-grid gap-2">
            <?php if (! $messages): ?><p class="text-muted small mb-0">No messages yet.</p><?php endif ?>
            <?php foreach ($messages as $m): ?>
                <div class="chat-msg <?= $m['sender_side'] === $mySide ? 'mine' : '' ?>"><div class="small fw-semibold"><?= esc($m['sender_side'] === 'admin' ? 'BearingCave support' : ($m['sender_side'] === $mySide ? 'You' : ucfirst($m['sender_side']))) ?> <span class="text-muted fw-normal">· <?= fdt($m['created_at']) ?></span></div><div style="white-space:pre-line"><?= esc($m['message']) ?></div></div>
            <?php endforeach ?>
            <?php if ($d['status'] !== 'closed' && service('companyContext')->can('disputes.manage')): ?>
            <form method="post" action="<?= site_url($area . '/disputes/' . $d['id'] . '/reply') ?>" class="mt-2">
                <?= csrf_field() ?><label class="form-label" for="msg">Add a message</label><textarea id="msg" class="form-control mb-2" name="message" rows="3" required></textarea><button class="btn btn-primary btn-sm" type="submit">Send</button>
            </form>
            <?php endif ?>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="bc-card mb-3"><div class="bc-card-body">
            <dl class="dl-grid small mb-0"><dt>Status</dt><dd><?= status_badge($d['status'], $statuses[$d['status']]) ?></dd><dt>Category</dt><dd><?= esc($categories[$d['category']]) ?></dd><dt>Order</dt><dd><a href="<?= site_url($area . '/orders/' . $order['id']) ?>"><?= esc($order['order_number']) ?></a></dd><dt>Opened</dt><dd><?= fdt($d['created_at']) ?></dd>
            <?php if ($d['resolution_notes']): ?><dt>Resolution</dt><dd><?= esc($d['resolution_notes']) ?></dd><?php endif ?></dl>
        </div></div>
        <div class="bc-card"><div class="bc-card-header"><h2>Supporting documents</h2></div><div class="bc-card-body">
            <?php foreach ($documents as $doc): ?><div class="small mb-1"><a href="<?= site_url('documents/' . $doc['uuid']) ?>"><i class="bi bi-paperclip" aria-hidden="true"></i> <?= esc($doc['title']) ?></a></div><?php endforeach ?>
            <?php if ($d['status'] !== 'closed'): ?>
            <form method="post" action="<?= site_url($area . '/disputes/' . $d['id'] . '/documents') ?>" enctype="multipart/form-data" class="mt-2">
                <?= csrf_field() ?><input class="form-control form-control-sm mb-2" name="title" placeholder="Title" aria-label="Document title"><input class="form-control form-control-sm mb-2" type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" required aria-label="File"><button class="btn btn-sm btn-outline-primary" type="submit">Upload</button>
            </form>
            <?php endif ?>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>
