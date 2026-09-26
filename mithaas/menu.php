<?php
declare(strict_types=1);

$pageTitle       = 'Menu | Mithaas Sweets, Bakery & Restaurant';
$metaDescription = 'The full Mithaas menu — traditional and premium sweets, bakery, North Indian, South Indian, snacks, street food, Chinese, thali, beverages and desserts.';
$breadcrumb      = ['Home' => 'index.php', 'Menu' => 'menu.php'];

require __DIR__ . '/includes/header.php';
?>


<main id="main">
  <?php
$heroImage      = 'assets/img/hero/hero-menu.jpg';
$heroImageLabel = 'Mithaas';
$heroCrumbs     = ['Home' => 'index.php', 'Menu' => null];
$heroKicker     = 'The Menu';
$heroTitle      = 'Everything We Make';
$heroSub        = 'Eleven sections. Search it, filter it, or just scroll — it is built to be read on a phone while you are deciding.';
$heroStyle      = 'min-height:min(46svh,440px)';
require __DIR__ . '/includes/page-hero.php';
?>

  <div class="menu-tools">
    <div class="container">
      <div class="menu-tools__grid">
        <div class="search" data-filled="false">
          <i class="bi bi-search" aria-hidden="true"></i>
          <label class="visually-hidden" for="menuSearch">Search the menu</label>
          <input id="menuSearch" type="search" placeholder="Search a dish or a sweet&hellip;"
                 autocomplete="off" data-menu-search>
          <button type="button" class="search__clear" data-menu-clear aria-label="Clear search">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
          </button>
        </div>
      </div>
    </div>
    <div class="container">
      <div class="chips" role="group" aria-label="Menu categories" data-menu-chips></div>
    </div>
  </div>

  <section class="section" style="padding-top:clamp(1.5rem,3vw,2.5rem)">
    <div class="container">
      <p class="menu-count mb-0" data-menu-count aria-live="polite"></p>
      <div data-menu-root>
        <noscript>
          <div class="menu-empty">
            <h3>Our menu needs JavaScript</h3>
            <p>Please enable JavaScript to browse the full menu, or call us and we will happily
               read it out.</p>
          </div>
        </noscript>
      </div>

      <div class="mt-5 pt-4">
        <hr class="rule">
        <p class="form-note mt-4 mb-0">
          Prices are confirmed at the counter. Sweets are sold by weight and availability changes
          through the day — seasonal items are made only while the season lasts. If you are
          planning a large order, please call ahead.
        </p>
      </div>
    </div>
  </section>

  <?php
$ctaTone    = 'espresso';
$ctaEyebrow = 'Ordering';
$ctaHeading = 'Tell Us What You Need';
$ctaLead    = 'For bulk sweets, cakes to order, party food or catering, a phone call is fastest.';
$ctaButtons = [
        ['label' => 'Call Mithaas', 'action' => 'call'],
        ['label' => 'WhatsApp Us', 'action' => 'whatsapp', 'message' => 'Hello Mithaas, I\'d like to place an order.'],
];
require __DIR__ . '/includes/cta-band.php';
?>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
