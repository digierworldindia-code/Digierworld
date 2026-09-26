<?php
/**
 * Mithaas — site footer, then the scripts that power every page.
 * Included at the end of every page. Nothing to configure.
 */
declare(strict_types=1);
?>
<footer class="footer">
  <div class="container">
    <div class="row g-5">

      <div class="col-lg-4">
        <img class="footer__logo" data-logo="footer" src="assets/img/brand/mithaas-logo.png"
             alt="Mithaas — Sweets, Bakery, Restaurant" width="240" height="150" loading="lazy">
        <p class="footer__tagline">Sweets &middot; Bakery &middot; Restaurant</p>
        <p class="footer__about">
          Sweets made through the day, bread out of the oven each morning, and a kitchen
          that cooks everything from a masala dosa to a full thali. One address, whatever
          the occasion.
        </p>
        <div class="socials">
          <a data-action="instagram" href="#" aria-label="Mithaas on Instagram"><i class="bi bi-instagram" aria-hidden="true"></i></a>
          <a data-action="facebook" href="#" aria-label="Mithaas on Facebook"><i class="bi bi-facebook" aria-hidden="true"></i></a>
          <a data-action="whatsapp" href="#" aria-label="Message Mithaas on WhatsApp"><i class="bi bi-whatsapp" aria-hidden="true"></i></a>
        </div>
      </div>

      <div class="col-6 col-lg-2">
        <h3>Explore</h3>
        <ul class="footer__links">
          <li><a href="our-story.php">Our Story</a></li>
          <li><a href="sweets.php">Sweets</a></li>
          <li><a href="bakery.php">Bakery</a></li>
          <li><a href="restaurant.php">Restaurant</a></li>
          <li><a href="gallery.php">Gallery</a></li>
          <li><a href="contact.php">Contact</a></li>
        </ul>
      </div>

      <div class="col-6 col-lg-2">
        <h3>The Menu</h3>
        <ul class="footer__links">
          <li><a href="menu.php#traditional-mithai">Traditional Mithai</a></li>
          <li><a href="menu.php#premium-sweets">Premium Sweets</a></li>
          <li><a href="menu.php#bakery">Bakery</a></li>
          <li><a href="menu.php#north-indian">North Indian</a></li>
          <li><a href="menu.php#south-indian">South Indian</a></li>
          <li><a href="menu.php#chinese">Chinese</a></li>
          <li><a href="menu.php#beverages">Beverages</a></li>
        </ul>
      </div>

      <div class="col-lg-4">
        <h3>Find Us</h3>
        <ul class="footer__links">
          <li><span data-field="address" data-pending="Address to be confirmed" style="white-space:pre-line"></span></li>
          <li><a data-action="call" href="#"><span data-field="phone" data-pending="Phone number to be confirmed"></span></a></li>
          <li><a data-action="email" href="#"><span data-field="email" data-pending="Email to be confirmed"></span></a></li>
        </ul>
        <h3 class="mt-4">Opening Hours</h3>
        <ul class="hours" data-hours><li><span>Opening hours</span><span class="pending">To be confirmed</span></li></ul>
      </div>

    </div>

    <div class="footer__bar">
      <p class="mb-0">&copy; <span data-year>2026</span> Mithaas. All rights reserved.</p>
      <nav aria-label="Legal">
        <a href="privacy-policy.php">Privacy Policy</a>
        <a href="terms.php">Terms &amp; Conditions</a>
        <a href="menu.php">Menu</a>
      </nav>
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
  integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
<script src="assets/js/config.js" defer></script>
<script src="assets/js/menu-data.js" defer></script>
<script src="assets/js/mithaas.js" defer></script>
</body>
</html>
