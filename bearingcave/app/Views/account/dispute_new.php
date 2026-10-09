<?= $this->extend('layouts/dashboard') ?>
<?= $this->section('content') ?>
<?= view('components/page_header', ['title' => 'Raise a dispute', 'subtitle' => 'Order ' . $order['order_number'], 'breadcrumbs' => ['Orders' => $area . '/orders', $order['order_number'] => $area . '/orders/' . $order['id'], 'Dispute' => null]]) ?>
<div class="row"><div class="col-lg-8">
<div class="bc-card"><div class="bc-card-body">
    <form method="post" action="<?= site_url($area . '/disputes/new/' . $order['id']) ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <?= select_field('category', 'What is the issue?', $categories, null, ['required' => true, 'placeholder' => 'Choose a category']) ?>
        <?= field('subject', 'Subject', null, ['required' => true]) ?>
        <?= textarea_field('description', 'Describe what happened', null, ['required' => true, 'rows' => 5, 'help' => 'Include quantities, batch numbers, dates and what you expected.']) ?>
        <?= textarea_field('desired_resolution', 'What resolution are you looking for?', null, ['rows' => 2]) ?>
        <div class="mb-3"><label class="form-label" for="evidence">Evidence (photo or PDF, optional)</label><input class="form-control" type="file" id="evidence" name="evidence" accept=".pdf,.jpg,.jpeg,.png"></div>
        <div class="alert alert-light border small"><?= esc((string) $warranty) ?> BearingCave manages documentation and the agreed intervention process. <?= pending_badge('Dispute terms pending legal approval') ?></div>
        <button class="btn btn-primary" type="submit">Submit dispute</button> <a class="btn btn-light" href="<?= site_url($area . '/orders/' . $order['id']) ?>">Cancel</a>
    </form>
</div></div>
</div></div>
<?= $this->endSection() ?>
