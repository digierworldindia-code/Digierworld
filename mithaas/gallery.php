<?php
declare(strict_types=1);

$pageTitle       = 'Gallery | Mithaas Sweets, Bakery & Restaurant';
$metaDescription = 'Photographs from Mithaas — the sweet counter, the bakery, the kitchen, the dining room and orders going out for celebrations.';
$breadcrumb      = ['Home' => 'index.php', 'Gallery' => 'gallery.php'];

require __DIR__ . '/includes/header.php';
?>


<?php
$heroImage      = 'assets/img/gallery/hero-gallery.jpg';
$heroImageLabel = 'Inside Mithaas';
$heroCrumbs     = ['Home' => 'index.php', 'Gallery' => null];
$heroKicker     = 'Gallery';
$heroTitle      = 'A Look <em>Around</em>';
$heroSub        = 'The counter, the kitchen, the dining room, and what leaves the shop on a busy week.';
require __DIR__ . '/includes/page-hero.php';
?>

<main id="main">
  <section class="section">
    <div class="container">
      <div class="chips mb-4" role="group" aria-label="Filter the gallery" data-gallery-filters>
        <button type="button" class="chip" data-filter="all" aria-pressed="true">All</button>
        <button type="button" class="chip" data-filter="sweets" aria-pressed="false">Sweets</button>
        <button type="button" class="chip" data-filter="bakery" aria-pressed="false">Bakery</button>
        <button type="button" class="chip" data-filter="restaurant" aria-pressed="false">Restaurant</button>
        <button type="button" class="chip" data-filter="ambience" aria-pressed="false">Ambience</button>
        <button type="button" class="chip" data-filter="celebrations" aria-pressed="false">Celebrations</button>
      </div>

      <div class="masonry" data-gallery>
        <button type="button" data-lb data-cat="sweets"
                data-lb-src="assets/img/gallery/sweet-counter.jpg" data-lb-cap="The sweet counter, mid-morning"
                aria-label="View: The sweet counter, mid-morning">
          <div class="fig ar-34 fig--zoom"><img src="assets/img/gallery/sweet-counter.jpg" alt="The sweet counter, mid-morning" data-photo data-label="The sweet counter, mid-morning" loading="lazy" decoding="async"></div>
        </button>
        <button type="button" data-lb data-cat="sweets"
                data-lb-src="assets/img/gallery/kaju-katli-stack.jpg" data-lb-cap="Kaju katli, cut and stacked"
                aria-label="View: Kaju katli, cut and stacked">
          <div class="fig ar-1 fig--zoom"><img src="assets/img/gallery/kaju-katli-stack.jpg" alt="Kaju katli, cut and stacked" data-photo data-label="Kaju katli, cut and stacked" loading="lazy" decoding="async"></div>
        </button>
        <button type="button" data-lb data-cat="bakery"
                data-lb-src="assets/img/gallery/bread-oven.jpg" data-lb-cap="Bread coming out of the oven"
                aria-label="View: Bread coming out of the oven">
          <div class="fig ar-45 fig--zoom"><img src="assets/img/gallery/bread-oven.jpg" alt="Bread coming out of the oven" data-photo data-label="Bread coming out of the oven" loading="lazy" decoding="async"></div>
        </button>
        <button type="button" data-lb data-cat="restaurant"
                data-lb-src="assets/img/gallery/thali.jpg" data-lb-cap="A full vegetarian thali"
                aria-label="View: A full vegetarian thali">
          <div class="fig ar-1 fig--zoom"><img src="assets/img/gallery/thali.jpg" alt="A full vegetarian thali" data-photo data-label="A full vegetarian thali" loading="lazy" decoding="async"></div>
        </button>
        <button type="button" data-lb data-cat="sweets"
                data-lb-src="assets/img/gallery/laddoo-tray.jpg" data-lb-cap="Motichoor laddoo, still warm"
                aria-label="View: Motichoor laddoo, still warm">
          <div class="fig ar-43 fig--zoom"><img src="assets/img/gallery/laddoo-tray.jpg" alt="Motichoor laddoo, still warm" data-photo data-label="Motichoor laddoo, still warm" loading="lazy" decoding="async"></div>
        </button>
        <button type="button" data-lb data-cat="ambience"
                data-lb-src="assets/img/gallery/dining.jpg" data-lb-cap="The dining room in the afternoon"
                aria-label="View: The dining room in the afternoon">
          <div class="fig ar-34 fig--zoom"><img src="assets/img/gallery/dining.jpg" alt="The dining room in the afternoon" data-photo data-label="The dining room in the afternoon" loading="lazy" decoding="async"></div>
        </button>
        <button type="button" data-lb data-cat="bakery"
                data-lb-src="assets/img/gallery/bakery-shelf.jpg" data-lb-cap="The bakery shelf"
                aria-label="View: The bakery shelf">
          <div class="fig ar-1 fig--zoom"><img src="assets/img/gallery/bakery-shelf.jpg" alt="The bakery shelf" data-photo data-label="The bakery shelf" loading="lazy" decoding="async"></div>
        </button>
        <button type="button" data-lb data-cat="restaurant"
                data-lb-src="assets/img/gallery/masala-dosa.jpg" data-lb-cap="Masala dosa off the tawa"
                aria-label="View: Masala dosa off the tawa">
          <div class="fig ar-45 fig--zoom"><img src="assets/img/gallery/masala-dosa.jpg" alt="Masala dosa off the tawa" data-photo data-label="Masala dosa off the tawa" loading="lazy" decoding="async"></div>
        </button>
        <button type="button" data-lb data-cat="celebrations"
                data-lb-src="assets/img/gallery/diwali-order.jpg" data-lb-cap="A Diwali order, packed and ready"
                aria-label="View: A Diwali order, packed and ready">
          <div class="fig ar-43 fig--zoom"><img src="assets/img/gallery/diwali-order.jpg" alt="A Diwali order, packed and ready" data-photo data-label="A Diwali order, packed and ready" loading="lazy" decoding="async"></div>
        </button>
        <button type="button" data-lb data-cat="restaurant"
                data-lb-src="assets/img/gallery/chaat-counter.jpg" data-lb-cap="The chaat counter at five o'clock"
                aria-label="View: The chaat counter at five o'clock">
          <div class="fig ar-1 fig--zoom"><img src="assets/img/gallery/chaat-counter.jpg" alt="The chaat counter at five o'clock" data-photo data-label="The chaat counter at five o'clock" loading="lazy" decoding="async"></div>
        </button>
        <button type="button" data-lb data-cat="sweets"
                data-lb-src="assets/img/gallery/jalebi.jpg" data-lb-cap="Jalebi, fried to order"
                aria-label="View: Jalebi, fried to order">
          <div class="fig ar-34 fig--zoom"><img src="assets/img/gallery/jalebi.jpg" alt="Jalebi, fried to order" data-photo data-label="Jalebi, fried to order" loading="lazy" decoding="async"></div>
        </button>
        <button type="button" data-lb data-cat="ambience"
                data-lb-src="assets/img/gallery/shopfront.jpg" data-lb-cap="The shopfront in the evening"
                aria-label="View: The shopfront in the evening">
          <div class="fig ar-43 fig--zoom"><img src="assets/img/gallery/shopfront.jpg" alt="The shopfront in the evening" data-photo data-label="The shopfront in the evening" loading="lazy" decoding="async"></div>
        </button>
        <button type="button" data-lb data-cat="celebrations"
                data-lb-src="assets/img/gallery/wedding-order.jpg" data-lb-cap="A wedding order leaving the shop"
                aria-label="View: A wedding order leaving the shop">
          <div class="fig ar-1 fig--zoom"><img src="assets/img/gallery/wedding-order.jpg" alt="A wedding order leaving the shop" data-photo data-label="A wedding order leaving the shop" loading="lazy" decoding="async"></div>
        </button>
        <button type="button" data-lb data-cat="bakery"
                data-lb-src="assets/img/gallery/cake-fridge.jpg" data-lb-cap="Cakes waiting to be collected"
                aria-label="View: Cakes waiting to be collected">
          <div class="fig ar-45 fig--zoom"><img src="assets/img/gallery/cake-fridge.jpg" alt="Cakes waiting to be collected" data-photo data-label="Cakes waiting to be collected" loading="lazy" decoding="async"></div>
        </button>
        <button type="button" data-lb data-cat="restaurant"
                data-lb-src="assets/img/gallery/chilli-paneer.jpg" data-lb-cap="Chilli paneer, straight from the wok"
                aria-label="View: Chilli paneer, straight from the wok">
          <div class="fig ar-1 fig--zoom"><img src="assets/img/gallery/chilli-paneer.jpg" alt="Chilli paneer, straight from the wok" data-photo data-label="Chilli paneer, straight from the wok" loading="lazy" decoding="async"></div>
        </button></div>

      <p class="form-note mt-5 mb-0">
        Photographs are of Mithaas sweets, bakery and dishes. Seasonal items appear only while
        they are being made.
      </p>
    </div>
  </section>

  <?php
$ctaTone    = 'cream';
$ctaEyebrow = '<span data-field="instagramHandle" data-pending="Instagram">Instagram</span>';
$ctaHeading = 'More On Instagram';
$ctaLead    = 'What came out of the kitchen today, and what is on the counter right now.';
$ctaButtons = [
        ['label' => 'Follow Mithaas', 'class' => 'btn', 'action' => 'instagram'],
];
require __DIR__ . '/includes/cta-band.php';
?>
</main>

<div class="modal fade modal-lightbox" id="lightbox" tabindex="-1" aria-labelledby="lbTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <h2 class="visually-hidden" id="lbTitle">Gallery image</h2>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      <button type="button" class="lb-btn lb-prev" data-lb-nav="prev" aria-label="Previous image">
        <i class="bi bi-chevron-left" aria-hidden="true"></i></button>
      <div data-lb-stage></div>
      <button type="button" class="lb-btn lb-next" data-lb-nav="next" aria-label="Next image">
        <i class="bi bi-chevron-right" aria-hidden="true"></i></button>
      <p class="lb-cap" data-lb-cap aria-live="polite"></p>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
