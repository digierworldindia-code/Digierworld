<?php
/**
 * Mithaas — the closing call-to-action band that ends the Bakery, Restaurant,
 * Menu and Gallery pages. Same shape each time; tone, wording and buttons vary.
 *
 * Set before including:
 *   $ctaTone     required  section tone: espresso | wine | cream | sand
 *   $ctaEyebrow  required  small uppercase line — trusted markup
 *   $ctaHeading  required  heading — trusted markup, may contain <br>
 *   $ctaLead     required  supporting paragraph
 *   $ctaButtons  required  list of buttons, each:
 *                            label   required  button text
 *                            class   optional  defaults to "btn btn-on-dark"
 *                            action  optional  call | whatsapp | instagram
 *                            message optional  prefilled WhatsApp message
 *                            href    optional  defaults to "#"
 */
declare(strict_types=1);
?>
<section class="section section--<?= e($ctaTone) ?> section--tight">
    <div class="container text-center">
      <p class="eyebrow eyebrow--center" data-reveal><?= $ctaEyebrow ?></p>
      <h2 class="display-2" data-reveal><?= $ctaHeading ?></h2>
      <p class="lead mt-3" data-reveal style="margin-inline:auto">
        <?= e($ctaLead) ?>
      </p>
      <div class="btn-row btn-row--center mt-4" data-reveal>
<?php foreach ($ctaButtons as $b): ?>
        <a class="<?= e($b['class'] ?? 'btn btn-on-dark') ?>"<?= isset($b['action']) ? ' data-action="' . e($b['action']) . '"' : '' ?><?= isset($b['message']) ? ' data-message="' . e($b['message']) . '"' : '' ?> href="<?= e($b['href'] ?? '#') ?>"><?= e($b['label']) ?></a>
<?php endforeach; ?>
      </div>
    </div>
  </section>
