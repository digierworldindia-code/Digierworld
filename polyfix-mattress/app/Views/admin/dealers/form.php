<?= $this->extend('layouts/console') ?>
<?= $this->section('content') ?>
<?php /** @var array|null $d */ ?>
<div class="page-head"><div><div class="small"><a href="<?= site_url('admin/dealers') ?>">Dealers</a></div><h1>New dealer</h1>
<p>The dealer code is issued automatically. Create their login afterwards under Users.</p></div></div>
<form class="panel" method="post" action="<?= site_url('admin/dealers') ?>">
    <div class="panel-body"><?= csrf_field() ?><?= view('admin/dealers/_fields', ['d' => $d]) ?>
        <button class="btn btn-primary mt-3" type="submit">Create dealer</button></div>
</form>
<?= $this->endSection() ?>
