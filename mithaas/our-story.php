<?php
declare(strict_types=1);

$pageTitle       = 'Our Story | Mithaas Sweets, Bakery & Restaurant';
$metaDescription = 'How Mithaas grew from a single sweet counter into a bakery and a full vegetarian restaurant — and what we still do slowly.';
$breadcrumb      = ['Home' => 'index.php', 'Our Story' => 'our-story.php'];

require __DIR__ . '/includes/header.php';
?>


<?php
$heroImage      = 'assets/img/brand/story-hero.jpg';
$heroImageLabel = 'The Mithaas shopfront';
$heroCrumbs     = ['Home' => 'index.php', 'Our Story' => null];
$heroKicker     = 'Our Story';
$heroTitle      = 'A Little Sweetness.<br>A Lot of Memories.';
$heroSub        = 'How a sweet counter turned into a bakery, and then into somewhere people come for dinner.';
require __DIR__ . '/includes/page-hero.php';
?>

<main id="main">
  <section class="section">
    <div class="container container--narrow">
      <div class="row g-5">
        <div class="col-lg-7">
          <div data-reveal>
            <p class="lead dropcap">
              Nobody remembers the box. They remember who brought it, and what the house
              smelled like when it was opened.
            </p>
            <p>
              That is really the whole business. A card arrives, a result comes through, someone
              gets married, someone finally comes home — and before anybody says anything
              sensible, somebody is sent out for mithai. We have been on the receiving end of that
              errand for a while now, and we have never treated it as a small thing.
            </p>
            <h2 class="display-3 mt-5 mb-3">It started at one counter</h2>
            <p>
              In the beginning it was sweets and nothing else. A glass counter, a few trays,
              and a short list — laddoo, burfi, jalebi on the weekend. What we learned in
              those first years is the thing the shop still runs on: make less, make it
              through the day, and let people taste before they buy.
            </p>
            <p>
              People kept asking for a cake for the same birthday they were buying laddoo for.
              So we put in an oven. Then they started asking whether they could sit down and
              eat while they waited, and that turned into a kitchen, and the kitchen turned
              into a menu with eleven sections on it.
            </p>
            <p class="pullquote">
              We never planned a restaurant. Our customers talked us into one, order by order.
            </p>
            <h2 class="display-3 mt-5 mb-3">What we actually do differently</h2>
            <p>
              Very little of it is clever. The kadhai goes on early. Chhena is set fresh, khoya
              is stirred down rather than bought in, and the counter is refilled in small lots
              through the day instead of being stacked once at opening. A laddoo made at four
              in the afternoon is a different thing from one made at six in the morning, and
              anyone who eats sweets regularly can tell.
            </p>
            <p>
              The same idea runs through the rest of it. Dal makhani sits on a low flame
              overnight. Dosa batter is ground here and left to ferment. Bread is baked in
              batches so the four o'clock loaf is not the seven o'clock loaf. None of this is
              a secret. It is just slower, and we decided a long time ago that we would rather
              be slower than be bigger.
            </p>
            <h2 class="display-3 mt-5 mb-3">Somewhere to sit down</h2>
            <p>
              The dining room fills in a particular order. Dosa and idli in the morning.
              Families at lunch, mostly for thali. Around five the chaat counter at the front
              gets loud, the wok starts up, and somebody's cake comes out of the fridge with
              a candle already in it. On festival weeks the queue reaches the door and we
              stop trying to predict anything.
            </p>
            <p>
              If you have not been before, come at an odd hour first. Have chai and a samosa,
              look at what is on the counter, and ask what was made most recently. That is
              usually the right thing to order.
            </p>
          </div>
        </div>

        <div class="col-lg-5">
          <div class="row g-4" data-stagger>
            <div class="col-12" data-reveal><div class="fig ar-45 fig--zoom"><img src="assets/img/brand/story-kadhai.jpg" alt="Sweets being made in the Mithaas kitchen" data-photo data-label="Sweets being made in the Mithaas kitchen" loading="lazy" decoding="async"></div></div>
            <div class="col-6" data-reveal><div class="fig ar-1 fig--zoom"><img src="assets/img/brand/story-oven.jpg" alt="Bread coming out of the oven" data-photo data-label="Bread coming out of the oven" loading="lazy" decoding="async"></div></div>
            <div class="col-6" data-reveal><div class="fig ar-1 fig--zoom"><img src="assets/img/brand/story-dining.jpg" alt="The dining room at Mithaas" data-photo data-label="The dining room at Mithaas" loading="lazy" decoding="async"></div></div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="section section--cream">
    <div class="container">
      <div class="sechead text-center">
        <p class="eyebrow eyebrow--center" data-reveal>Why Mithaas</p>
        <h2 class="display-2" data-reveal>What We Pay Attention To</h2>
      </div>
      <div class="values" data-stagger>
        <div class="value" data-reveal><p class="value__n">01</p><h3>Freshness</h3>
          <p>Small batches through the day rather than one big lot in the morning.</p></div>
        <div class="value" data-reveal><p class="value__n">02</p><h3>Craftsmanship</h3>
          <p>Chhena set here, khoya reduced here, batter ground here. It takes longer.</p></div>
        <div class="value" data-reveal><p class="value__n">03</p><h3>Variety</h3>
          <p>Mithai, a birthday cake and dinner for four, without three separate trips.</p></div>
        <div class="value" data-reveal><p class="value__n">04</p><h3>Hospitality</h3>
          <p>The counter staff know most faces and remember most orders.</p></div>
      </div>
      <div class="text-center mt-5">
        <a class="btn" href="menu.php" data-reveal>See What We Make</a>
      </div>
    </div>
  </section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
