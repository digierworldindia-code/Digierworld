<?php
declare(strict_types=1);

$pageTitle       = 'Bakery — Cakes, Pastries & Fresh Bread | Mithaas';
$metaDescription = 'Cakes, pastries, cookies, breads and baked snacks from the Mithaas bakery. Photo and theme cakes to order with a day\'s notice.';
$breadcrumb      = ['Home' => 'index.php', 'Bakery' => 'bakery.php'];

require __DIR__ . '/includes/header.php';
?>


<?php
$heroImage      = 'assets/img/bakery/hero-bakery.jpg';
$heroImageLabel = 'Fresh bread and cakes at the Mithaas bakery';
$heroCrumbs     = ['Home' => 'index.php', 'Bakery' => null];
$heroKicker     = 'The Bakery';
$heroTitle      = 'Fresh From <em>The Oven</em>';
$heroSub        = 'Bread and cookies first thing, cakes through the morning, and not much left by evening.';
require __DIR__ . '/includes/page-hero.php';
?>

<main id="main">
  <section class="section">
    <div class="container">
      <div class="row g-5 align-items-center mb-5">
        <div class="col-lg-6">
          <div data-reveal>
            <p class="eyebrow">Baked Daily</p>
            <h2 class="display-2 mb-4">The oven starts before the shop does</h2>
            <p class="lead">
              Bread comes out first, then cookies, then cakes through the morning. It is a small
              bakery on purpose — everything is baked in batches so nothing sits around waiting
              to be sold.
            </p>
            <p>
              If you want a cake for a particular day, tell us a day ahead. Photo cakes, theme
              cakes and anything with writing on it need the notice; a plain truffle or
              pineapple can usually be managed the same afternoon.
            </p>
            <div class="btn-row mt-4">
              <a class="btn" href="menu.php#bakery">See The Bakery Menu</a>
              <a class="btn btn-ghost" data-action="whatsapp"
                 data-message="Hello Mithaas, I'd like to order a cake." href="#">Order A Cake</a>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div data-reveal="curtain"><div class="fig ar-45 fig--zoom"><img src="assets/img/bakery/bakery-editorial.jpg" alt="The bakery shelf at Mithaas" data-photo data-label="The bakery shelf at Mithaas" loading="lazy" decoding="async"></div></div>
        </div>
      </div>

      <div class="row g-4" data-stagger>

        <div class="col-sm-6 col-lg-4" data-reveal>
          <a class="tile fig ar-43 fig--zoom d-block" href="menu.php#bakery">
            <img src="assets/img/bakery/truffle-cake.jpg" alt="Cakes at the Mithaas bakery"
                 data-photo data-label="Cakes" loading="lazy" decoding="async">
            <span class="tile__cap"><h3>Cakes</h3><p>Truffle, butterscotch, pineapple, red velvet and fresh fruit. Photo and theme cakes with a day's notice.</p></span>
          </a>
        </div>
        <div class="col-sm-6 col-lg-4" data-reveal>
          <a class="tile fig ar-43 fig--zoom d-block" href="menu.php#bakery">
            <img src="assets/img/bakery/pastry-counter.jpg" alt="Pastries at the Mithaas bakery"
                 data-photo data-label="Pastries" loading="lazy" decoding="async">
            <span class="tile__cap"><h3>Pastries</h3><p>Cut from the day's cakes and kept cold until you ask for one.</p></span>
          </a>
        </div>
        <div class="col-sm-6 col-lg-4" data-reveal>
          <a class="tile fig ar-43 fig--zoom d-block" href="menu.php#bakery">
            <img src="assets/img/bakery/cookies.jpg" alt="Cookies at the Mithaas bakery"
                 data-photo data-label="Cookies" loading="lazy" decoding="async">
            <span class="tile__cap"><h3>Cookies</h3><p>Ajwain, jeera, coconut, butter and chocolate chip — sold by weight.</p></span>
          </a>
        </div>
        <div class="col-sm-6 col-lg-4" data-reveal>
          <a class="tile fig ar-43 fig--zoom d-block" href="menu.php#bakery">
            <img src="assets/img/bakery/bread.jpg" alt="Breads at the Mithaas bakery"
                 data-photo data-label="Breads" loading="lazy" decoding="async">
            <span class="tile__cap"><h3>Breads</h3><p>White, brown and multigrain loaves, plus pav and burger buns.</p></span>
          </a>
        </div>
        <div class="col-sm-6 col-lg-4" data-reveal>
          <a class="tile fig ar-43 fig--zoom d-block" href="menu.php#bakery">
            <img src="assets/img/bakery/veg-puff.jpg" alt="Baked Snacks at the Mithaas bakery"
                 data-photo data-label="Baked Snacks" loading="lazy" decoding="async">
            <span class="tile__cap"><h3>Baked Snacks</h3><p>Veg puff, paneer puff and pizza puff. The four o'clock regulars.</p></span>
          </a>
        </div>
        <div class="col-sm-6 col-lg-4" data-reveal>
          <a class="tile fig ar-43 fig--zoom d-block" href="menu.php#bakery">
            <img src="assets/img/bakery/brownie.jpg" alt="Bakery Specials at the Mithaas bakery"
                 data-photo data-label="Bakery Specials" loading="lazy" decoding="async">
            <span class="tile__cap"><h3>Bakery Specials</h3><p>Doughnuts, brownies, muffins, croissants and cake rusk.</p></span>
          </a>
        </div>
      </div>
    </div>
  </section>

  <?php
$ctaTone    = 'espresso';
$ctaEyebrow = 'Cakes To Order';
$ctaHeading = 'Birthdays, Anniversaries<br>And Last Days At The Office';
$ctaLead    = 'Tell us the weight, the flavour, what should be written on it and when you need it. A day\'s notice is usually enough.';
$ctaButtons = [
        ['label' => 'Order On WhatsApp', 'action' => 'whatsapp', 'message' => 'Hello Mithaas, I\'d like to order a cake. Date, weight and flavour:'],
        ['label' => 'Call The Bakery', 'action' => 'call'],
];
require __DIR__ . '/includes/cta-band.php';
?>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
