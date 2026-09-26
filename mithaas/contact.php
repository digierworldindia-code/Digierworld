<?php
declare(strict_types=1);

$pageTitle       = 'Contact & Location | Mithaas Sweets, Bakery & Restaurant';
$metaDescription = 'Call, WhatsApp, email or visit Mithaas. Address, opening hours, directions and an enquiry form for orders, cakes and catering.';
$breadcrumb      = ['Home' => 'index.php', 'Contact' => 'contact.php'];

require __DIR__ . '/includes/header.php';
?>


<?php
$heroImage      = 'assets/img/gallery/shopfront.jpg';
$heroImageLabel = 'The Mithaas shopfront';
$heroCrumbs     = ['Home' => 'index.php', 'Contact' => null];
$heroKicker     = 'Contact';
$heroTitle      = 'Come And <em>See Us</em>';
$heroSub        = 'Call, message, or simply walk in. We are easiest to find at the sweet counter.';
require __DIR__ . '/includes/page-hero.php';
?>

<main id="main">
  <section class="section" data-schema-localbusiness>
    <div class="container">
      <div class="row g-5">

        <div class="col-lg-5">
          <div data-reveal>
            <p class="eyebrow">Get In Touch</p>
            <h2 class="display-2 mb-4">Mithaas</h2>

            <div class="infoitem">
              <span class="infoitem__k">Call Us</span>
              <span class="infoitem__v">
                <a data-action="call" href="#"><span data-field="phone" data-pending="Phone number to be confirmed"></span></a>
              </span>
            </div>
            <div class="infoitem">
              <span class="infoitem__k">WhatsApp</span>
              <span class="infoitem__v">
                <a data-action="whatsapp" data-message="Hello Mithaas, I have an enquiry." href="#">Message us on WhatsApp</a>
              </span>
            </div>
            <div class="infoitem">
              <span class="infoitem__k">Email</span>
              <span class="infoitem__v">
                <a data-action="email" href="#"><span data-field="email" data-pending="Email to be confirmed"></span></a>
              </span>
            </div>
            <div class="infoitem">
              <span class="infoitem__k">Visit Us</span>
              <span class="infoitem__v" data-field="address" data-pending="Address to be confirmed" style="white-space:pre-line"></span>
            </div>
            <div class="infoitem">
              <span class="infoitem__k">Landmark</span>
              <span class="infoitem__v" data-field="landmark" data-pending="Landmark to be confirmed"></span>
            </div>

            <h3 class="h4 mt-5 mb-3">Opening Hours</h3>
            <ul class="hours" data-hours></ul>
            <p class="form-note mt-2" data-field="hoursNote" data-pending=""></p>

            <div class="btn-row mt-4">
              <a class="btn" data-action="directions" href="#">Get Directions</a>
              <a class="btn btn-ghost" data-action="call" href="#">Call Now</a>
            </div>

            <div class="socials">
              <a data-action="instagram" href="#" aria-label="Mithaas on Instagram"><i class="bi bi-instagram" aria-hidden="true"></i></a>
              <a data-action="facebook" href="#" aria-label="Mithaas on Facebook"><i class="bi bi-facebook" aria-hidden="true"></i></a>
              <a data-action="whatsapp" href="#" aria-label="Message Mithaas on WhatsApp"><i class="bi bi-whatsapp" aria-hidden="true"></i></a>
            </div>
          </div>
        </div>

        <div class="col-lg-7">
          <div data-reveal>
            <h2 class="display-3 mb-2">Send An Enquiry</h2>
            <p class="lead mb-4">
              Orders, cakes, catering or anything else — tell us what you need and we will
              get back to you.
            </p>

            <form class="form" data-enquiry novalidate>
              <div class="row g-4">
                <div class="col-md-6">
                  <label class="form-label" for="f-name">Name <span class="req">*</span></label>
                  <input class="form-control" id="f-name" name="name" type="text"
                         autocomplete="name" required>
                  <p class="field-err" data-err="name" role="alert"></p>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="f-phone">Phone <span class="req">*</span></label>
                  <input class="form-control" id="f-phone" name="phone" type="tel"
                         autocomplete="tel" inputmode="tel" required>
                  <p class="field-err" data-err="phone" role="alert"></p>
                </div>
                <div class="col-12">
                  <label class="form-label" for="f-email">Email</label>
                  <input class="form-control" id="f-email" name="email" type="email"
                         autocomplete="email" inputmode="email">
                  <p class="field-err" data-err="email" role="alert"></p>
                </div>
                <div class="col-12">
                  <label class="form-label" for="f-message">Message <span class="req">*</span></label>
                  <textarea class="form-control" id="f-message" name="message" rows="6" required
                    placeholder="For example: 3kg assorted sweets for Diwali, needed on the 18th."></textarea>
                  <p class="field-err" data-err="message" role="alert"></p>
                </div>
              </div>

              <p class="form-status mt-4" data-form-status tabindex="-1" role="status" hidden></p>

              <div class="btn-row mt-4">
                <button class="btn" type="submit">Send Enquiry</button>
                <a class="btn btn-ghost" data-action="whatsapp" href="#">Or WhatsApp Us</a>
              </div>
              <p class="form-note mt-3 mb-0">
                We use your details only to reply to this enquiry.
                See our <a href="privacy-policy.php">privacy policy</a>.
              </p>
            </form>
          </div>
        </div>
      </div>

      <div class="mt-5 pt-4">
        <div class="mapframe" data-map data-reveal="fade"></div>
      </div>
    </div>
  </section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
