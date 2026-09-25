<?= $this->extend('layouts/site') ?>
<?= $this->section('content') ?>
<?php /** A CMS page: hero, then its sections. @var array $hero @var array $sections @var array $trail @var array $slots */ ?>
<?= view('partials/breadcrumbs', ['trail' => $trail]) ?>
<?= view('partials/page_hero', ['hero' => $hero]) ?>
<?= view('sections/render', ['sections' => $sections, 'slots' => $slots]) ?>
<?= $this->endSection() ?>
