<?php
/**
 * Mithaas — inner-page hero banner. Used by every page except the home page,
 * which has its own taller hero.
 *
 * Set before including:
 *   $heroImage       required  path to the banner photograph
 *   $heroImageLabel  required  name shown on the placeholder tile until the photo exists
 *   $heroCrumbs      required  ['Home' => 'index.php', 'Sweets' => null]  (null = current)
 *   $heroKicker      required  small uppercase line above the title
 *   $heroTitle       required  page H1 — trusted markup, may contain <em> or <br>
 *   $heroSub         required  supporting line beneath the title
 *   $heroStyle       optional  inline style on the <section> (the menu page runs shorter)
 */
declare(strict_types=1);

$heroStyle = $heroStyle ?? '';
?>
<section class="hero hero--inner"<?= $heroStyle !== '' ? ' style="' . e($heroStyle) . '"' : '' ?>>
  <div class="hero__media" aria-hidden="true">
    <img src="<?= e($heroImage) ?>" alt="" data-photo data-label="<?= e($heroImageLabel) ?>" loading="eager" fetchpriority="high" decoding="async">
  </div>
  <div class="hero__scrim" aria-hidden="true"></div>
  <div class="container">
    <div class="hero__inner">
      <ol class="crumbs"><?php foreach ($heroCrumbs as $label => $file): ?><li<?= $file === null ? ' aria-current="page"' : '' ?>><?= $file === null ? e($label) : '<a href="' . e($file) . '">' . e($label) . '</a>' ?></li><?php endforeach; ?></ol>
      <p class="hero__kicker"><?= e($heroKicker) ?></p>
      <h1 class="hero__title"><?= $heroTitle ?></h1>
      <p class="hero__sub"><?= e($heroSub) ?></p>
    </div>
  </div>
</section>
