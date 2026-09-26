<?php
declare(strict_types=1);

$pageTitle       = 'Restaurant — North Indian, South Indian, Chaat & Chinese | Mithaas';
$metaDescription = 'A full vegetarian restaurant at Mithaas: North Indian curries and breads, dosa and idli, chaat and street food, Indo-Chinese, thali, beverages and desserts.';
$breadcrumb      = ['Home' => 'index.php', 'Restaurant' => 'restaurant.php'];

require __DIR__ . '/includes/header.php';
?>


<?php
$heroImage      = 'assets/img/restaurant/hero-restaurant.jpg';
$heroImageLabel = 'The dining room at Mithaas';
$heroCrumbs     = ['Home' => 'index.php', 'Restaurant' => null];
$heroKicker     = 'The Restaurant';
$heroTitle      = 'More Than Mithai.<br><em>A Table Full of Flavour.</em>';
$heroSub        = 'Dosa in the morning, thali at lunch, chaat and noodles by evening. All vegetarian.';
require __DIR__ . '/includes/page-hero.php';
?>

<main id="main">
  <section class="section">
    <div class="container">
      <div class="row g-5 align-items-center mb-5 pb-lg-4">
        <div class="col-lg-7">
          <div data-reveal>
            <p class="eyebrow">The Kitchen</p>
            <h2 class="display-2 mb-4">Busier than most people expect</h2>
            <p class="lead">
              Past the sweet counter there is a proper kitchen, and it runs from breakfast to
              closing. Dosa and idli in the morning. Families at lunch, mostly for thali.
              By five the chaat counter is loud, the wok is going, and somebody's cake is
              coming out of the fridge with a candle already in it.
            </p>
            <p>
              Everything is vegetarian. Spice is adjusted on request — tell the kitchen and
              they will cook it the way you want it rather than the way the menu assumes.
            </p>
            <div class="btn-row mt-4">
              <a class="btn" href="menu.php">Browse The Full Menu</a>
              <a class="btn btn-ghost" href="contact.php">Find Us</a>
            </div>
          </div>
        </div>
        <div class="col-lg-5">
          <div data-reveal="curtain"><div class="fig ar-45 fig--zoom"><img src="assets/img/restaurant/kitchen.jpg" alt="The kitchen at Mithaas" data-photo data-label="The kitchen at Mithaas" loading="lazy" decoding="async"></div></div>
        </div>
      </div>

      
      <div class="row g-5 align-items-center mb-5 pb-lg-4">
        <div class="col-lg-6">
          <div data-reveal>
            <p class="panel__num">01</p>
            <h2 class="display-2 mb-3">North Indian</h2>
            <p class="lead">Paneer and dal cooked to order, breads off the tandoor, rice and a full thali.</p>
            <ul class="panel__list"><li>Paneer Butter Masala</li><li>Shahi Paneer</li><li>Kadai Paneer</li><li>Dal Makhani</li><li>Chole</li><li>Malai Kofta</li><li>Tandoori Roti</li><li>Butter Naan</li><li>Stuffed Paratha</li><li>Veg Biryani</li><li>Raita &amp; Salad</li><li>Thali</li></ul>
            <p class="mt-4 mb-0"><a class="tlink" href="menu.php#north-indian">
              Open this section <i class="bi bi-arrow-right" aria-hidden="true"></i></a></p>
          </div>
        </div>
        <div class="col-lg-6">
          <div data-reveal="curtain"><div class="fig ar-43 fig--zoom"><img src="assets/img/restaurant/cat-north-indian.jpg" alt="North Indian" data-photo data-label="North Indian" loading="lazy" decoding="async"></div></div>
        </div>
      </div>
      <div class="row g-5 align-items-center flex-lg-row-reverse mb-5 pb-lg-4">
        <div class="col-lg-6">
          <div data-reveal>
            <p class="panel__num">02</p>
            <h2 class="display-2 mb-3">South Indian</h2>
            <p class="lead">Batter ground here and left to ferment overnight — which is the whole difference.</p>
            <ul class="panel__list"><li>Masala Dosa</li><li>Mysore Masala Dosa</li><li>Rava Dosa</li><li>Paneer Dosa</li><li>Uttapam</li><li>Idli Sambar</li><li>Medu Vada</li><li>South Indian Thali</li><li>Uppma</li><li>Filter Coffee</li></ul>
            <p class="mt-4 mb-0"><a class="tlink" href="menu.php#south-indian">
              Open this section <i class="bi bi-arrow-right" aria-hidden="true"></i></a></p>
          </div>
        </div>
        <div class="col-lg-6">
          <div data-reveal="curtain"><div class="fig ar-43 fig--zoom"><img src="assets/img/restaurant/cat-south-indian.jpg" alt="South Indian" data-photo data-label="South Indian" loading="lazy" decoding="async"></div></div>
        </div>
      </div>
      <div class="row g-5 align-items-center mb-5 pb-lg-4">
        <div class="col-lg-6">
          <div data-reveal>
            <p class="panel__num">03</p>
            <h2 class="display-2 mb-3">Snacks &amp; Street Food</h2>
            <p class="lead">The counter at the front, assembled in front of you and usually eaten standing up.</p>
            <ul class="panel__list"><li>Golgappa</li><li>Samosa Chaat</li><li>Raj Kachori</li><li>Dahi Bhalla</li><li>Papdi Chaat</li><li>Aloo Tikki</li><li>Pav Bhaji</li><li>Vada Pav</li><li>Chole Bhature</li><li>Sandwiches</li><li>Burgers</li><li>Rolls</li></ul>
            <p class="mt-4 mb-0"><a class="tlink" href="menu.php#snacks-street-food">
              Open this section <i class="bi bi-arrow-right" aria-hidden="true"></i></a></p>
          </div>
        </div>
        <div class="col-lg-6">
          <div data-reveal="curtain"><div class="fig ar-43 fig--zoom"><img src="assets/img/restaurant/cat-snacks-street-food.jpg" alt="Snacks &amp; Street Food" data-photo data-label="Snacks &amp; Street Food" loading="lazy" decoding="async"></div></div>
        </div>
      </div>
      <div class="row g-5 align-items-center flex-lg-row-reverse mb-5 pb-lg-4">
        <div class="col-lg-6">
          <div data-reveal>
            <p class="panel__num">04</p>
            <h2 class="display-2 mb-3">Chinese</h2>
            <p class="lead">Cooked hot and fast in a wok. Dry or with gravy — the kitchen will do either.</p>
            <ul class="panel__list"><li>Veg Manchurian</li><li>Chilli Paneer</li><li>Chilli Potato</li><li>Honey Chilli Potato</li><li>Spring Rolls</li><li>Momos</li><li>Hakka Noodles</li><li>Chowmein</li><li>Fried Rice</li><li>Manchow Soup</li></ul>
            <p class="mt-4 mb-0"><a class="tlink" href="menu.php#chinese">
              Open this section <i class="bi bi-arrow-right" aria-hidden="true"></i></a></p>
          </div>
        </div>
        <div class="col-lg-6">
          <div data-reveal="curtain"><div class="fig ar-43 fig--zoom"><img src="assets/img/restaurant/cat-chinese.jpg" alt="Chinese" data-photo data-label="Chinese" loading="lazy" decoding="async"></div></div>
        </div>
      </div>
      <div class="row g-5 align-items-center mb-5 pb-lg-4">
        <div class="col-lg-6">
          <div data-reveal>
            <p class="panel__num">05</p>
            <h2 class="display-2 mb-3">Thali &amp; Combos</h2>
            <p class="lead">A full plate worked out for you. Good for one person in a hurry and a family that can't agree.</p>
            <ul class="panel__list"><li>Mithaas Special Thali</li><li>Regular Veg Thali</li><li>Paneer Thali</li><li>Mini Thali</li><li>Chole Bhature Combo</li><li>Family Pack</li><li>Party Orders</li></ul>
            <p class="mt-4 mb-0"><a class="tlink" href="menu.php#thali-combos">
              Open this section <i class="bi bi-arrow-right" aria-hidden="true"></i></a></p>
          </div>
        </div>
        <div class="col-lg-6">
          <div data-reveal="curtain"><div class="fig ar-43 fig--zoom"><img src="assets/img/restaurant/cat-thali-combos.jpg" alt="Thali &amp; Combos" data-photo data-label="Thali &amp; Combos" loading="lazy" decoding="async"></div></div>
        </div>
      </div>
      <div class="row g-5 align-items-center flex-lg-row-reverse mb-5 pb-lg-4">
        <div class="col-lg-6">
          <div data-reveal>
            <p class="panel__num">06</p>
            <h2 class="display-2 mb-3">Beverages &amp; Desserts</h2>
            <p class="lead">Chai boiled properly, lassi in a tall glass, and something cold to finish.</p>
            <ul class="panel__list"><li>Masala Chai</li><li>Filter Coffee</li><li>Cold Coffee</li><li>Lassi</li><li>Chaas</li><li>Milkshakes</li><li>Mojito</li><li>Falooda</li><li>Kulfi</li><li>Rabri Jalebi</li></ul>
            <p class="mt-4 mb-0"><a class="tlink" href="menu.php#beverages">
              Open this section <i class="bi bi-arrow-right" aria-hidden="true"></i></a></p>
          </div>
        </div>
        <div class="col-lg-6">
          <div data-reveal="curtain"><div class="fig ar-43 fig--zoom"><img src="assets/img/restaurant/cat-beverages.jpg" alt="Beverages &amp; Desserts" data-photo data-label="Beverages &amp; Desserts" loading="lazy" decoding="async"></div></div>
        </div>
      </div>
    </div>
  </section>

  <?php
$ctaTone    = 'wine';
$ctaEyebrow = 'Bookings &amp; Bulk Orders';
$ctaHeading = 'Family Meals, Functions<br>And Office Orders';
$ctaLead    = 'For a function, an office lunch or a large family order, call ahead so the kitchen can plan. Tell us the numbers and roughly what you have in mind.';
$ctaButtons = [
        ['label' => 'Call Us', 'action' => 'call'],
        ['label' => 'WhatsApp Us', 'action' => 'whatsapp', 'message' => 'Hello Mithaas, I\'d like to ask about a bulk or party order.'],
];
require __DIR__ . '/includes/cta-band.php';
?>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
