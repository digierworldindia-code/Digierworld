<?php
/** Setup wizard stepper (shown on settings pages when ?wizard=1). @var string $current */
$c     = db_connect()->table('company_settings')->select('setup_steps')->where('id', 1)->get()->getRowArray();
$done  = json_decode((string) ($c['setup_steps'] ?? '{}'), true) ?: [];
$steps = [
    'company'    => ['Legal profile', 'settings/company'],
    'branding'   => ['Brand assets', 'settings/branding'],
    'banks'      => ['Bank & payment', 'settings/banks'],
    'tax'        => ['Tax & finance', 'settings/tax'],
    'sequences'  => ['Document series', 'settings/sequences'],
    'templates'  => ['Invoice template', 'settings/templates'],
    'signature'  => ['Signature policy', 'settings/signature-policy'],
];
$i = 0;
?>
<nav class="wizard-steps" aria-label="Setup steps">
    <?php foreach ($steps as $key => [$label, $url]): $i++; ?>
        <a href="<?= site_url($url . '?wizard=1') ?>" class="wizard-step <?= ! empty($done[$key]) ? 'done' : '' ?> <?= $current === $key ? 'active' : '' ?>" <?= $current === $key ? 'aria-current="step"' : '' ?>>
            <span class="num"><?= ! empty($done[$key]) ? '✓' : $i ?></span><?= esc($label) ?>
        </a>
    <?php endforeach ?>
</nav>
