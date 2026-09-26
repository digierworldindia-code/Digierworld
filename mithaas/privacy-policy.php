<?php
declare(strict_types=1);

$pageTitle       = 'Privacy Policy | Mithaas';
$metaDescription = 'How Mithaas handles the information you send through this website.';
$breadcrumb      = ['Home' => 'index.php', 'Privacy Policy' => 'privacy-policy.php'];

require __DIR__ . '/includes/header.php';
?>


<?php
$heroImage      = 'assets/img/brand/story-hero.jpg';
$heroImageLabel = 'Mithaas';
$heroCrumbs     = ['Home' => 'index.php', 'Privacy Policy' => null];
$heroKicker     = 'Legal';
$heroTitle      = 'Privacy Policy';
$heroSub        = 'What we collect when you contact us, and what we do with it.';
require __DIR__ . '/includes/page-hero.php';
?>

<main id="main">
  <section class="section">
    <div class="container">
      <div class="prose" data-reveal>
      <p class="lead">This policy explains what happens to the information you give us through
      this website.</p>

      <h2>What we collect</h2>
      <p>If you fill in the enquiry form on our contact page, we collect the name, phone number,
      email address and message you type into it. We do not ask for anything else, and there is
      no account to create.</p>

      <h2>How your enquiry reaches us</h2>
      <p>Depending on how the site is configured, submitting the form either opens WhatsApp or
      your email application with your message already written, or sends it to us through a form
      service. In the first two cases the message is sent by you, from your own account, and
      nothing is stored on this website.</p>

      <h2>What we do with it</h2>
      <p>We use your details to reply to your enquiry and, where relevant, to arrange your order.
      We do not sell your information, and we do not add you to a marketing list without you
      asking us to.</p>

      <h2>Cookies and analytics</h2>
      <p>This website does not set advertising cookies. Fonts, styles and scripts are loaded from
      Google Fonts and jsDelivr, which will see your IP address as part of serving those files.
      If website analytics are added later, this page will be updated to say so before they are
      switched on.</p>

      <h2>Third-party content</h2>
      <p>Our contact page embeds a Google Map, and our social links point to Instagram, Facebook
      and WhatsApp. Those services have their own privacy policies, and this one does not cover
      them.</p>

      <h2>How long we keep things</h2>
      <p>Enquiries are kept only as long as we need them to serve you and to keep an ordinary
      record of the order.</p>

      <h2>Your choices</h2>
      <p>You can ask us what we hold about you, ask us to correct it, or ask us to delete it.
      Get in touch through our <a href="contact.php">contact page</a> and we will sort it out.</p>

      <h2>Changes</h2>
      <p>If this policy changes we will update this page. Please check back occasionally.</p>
    </div>
    </div>
  </section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
