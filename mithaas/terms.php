<?php
declare(strict_types=1);

$pageTitle       = 'Terms & Conditions | Mithaas';
$metaDescription = 'Terms covering orders, availability, prices and the use of the Mithaas website.';
$breadcrumb      = ['Home' => 'index.php', 'Terms &amp; Conditions' => 'terms.php'];

require __DIR__ . '/includes/header.php';
?>


<?php
$heroImage      = 'assets/img/brand/story-hero.jpg';
$heroImageLabel = 'Mithaas';
$heroCrumbs     = ['Home' => 'index.php', 'Terms & Conditions' => null];
$heroKicker     = 'Legal';
$heroTitle      = 'Terms &amp; Conditions';
$heroSub        = 'The basics of ordering from us and using this website.';
require __DIR__ . '/includes/page-hero.php';
?>

<main id="main">
  <section class="section">
    <div class="container">
      <div class="prose" data-reveal>
      <p class="lead">These terms cover the use of this website and orders placed through it.</p>

      <h2>About this website</h2>
      <p>This site describes what Mithaas makes and sells. It is a menu and an introduction — it
      is not an online shop, and no payment is taken here.</p>

      <h2>Menu, availability and prices</h2>
      <p>Our menu changes with the season and with what has been made that day. Items shown here
      may not be available at every hour, and seasonal sweets are made only while the season
      lasts. Prices are confirmed at the counter or when you place your order. Any prices shown
      on this website are indicative and may change without notice.</p>

      <h2>Photographs</h2>
      <p>Photographs on this site show our food as it is served. Serving sizes, garnishes and
      presentation can vary from one plate to the next.</p>

      <h2>Orders</h2>
      <p>An enquiry sent through this website is a request, not a confirmed order. An order is
      confirmed only when we have spoken to you and agreed the items, the quantity, the price and
      the collection time. Bulk sweet orders, cakes to order and catering usually need at least a
      day's notice, and more during festival weeks.</p>

      <h2>Cancellations</h2>
      <p>Please tell us as early as you can if you need to cancel or change an order. Where we
      have already begun preparing a custom order, we may not be able to cancel it.</p>

      <h2>Allergies and dietary requirements</h2>
      <p>Our kitchen is fully vegetarian. It handles milk, nuts, wheat and other common
      allergens, and dishes are prepared in shared spaces, so we cannot guarantee that any item
      is free from traces of them. If you have an allergy, please tell us before ordering so we
      can advise you honestly.</p>

      <h2>This website's content</h2>
      <p>The Mithaas name, logo, photographs and written content on this site belong to Mithaas.
      Please do not reuse them without asking us first.</p>

      <h2>Links to other sites</h2>
      <p>Where we link to Instagram, Facebook, WhatsApp or Google Maps, we are not responsible
      for the content of those services.</p>

      <h2>Getting in touch</h2>
      <p>Questions about these terms are best answered directly. Please use our
      <a href="contact.php">contact page</a>.</p>
    </div>
    </div>
  </section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
