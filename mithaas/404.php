<?php
declare(strict_types=1);

$pageTitle       = 'Page Not Found | Mithaas';
$metaDescription = 'The page you were looking for could not be found. Browse the Mithaas menu instead.';
$robots          = 'noindex, follow';

require __DIR__ . '/includes/header.php';
?>


<main id="main">
  <section class="section" style="padding-top:calc(var(--nav-h) + 5rem);min-height:70svh;display:grid;place-items:center">
    <div class="container text-center">
      <p class="eyebrow eyebrow--center">Error 404</p>
      <h1 class="display-1 mb-3">This page has been eaten</h1>
      <p class="lead" style="margin-inline:auto">
        We can't find what you were looking for. It may have moved, or the link may be
        out of date. The menu, at least, is exactly where you left it.
      </p>
      <svg class="ornament" viewBox="0 0 160 26" fill="none" aria-hidden="true" focusable="false"><path d="M4 13h52M104 13h52" stroke="currentColor" stroke-width="1"/><path d="M80 3c5.4 5.2 8.1 8.5 8.1 10S85.4 20.8 80 23c-5.4-2.2-8.1-8.5-8.1-10S74.6 8.2 80 3z" stroke="currentColor" stroke-width="1"/><path d="M80 8.5c2 2.2 3 3.6 3 4.5s-1 2.3-3 4.5c-2-2.2-3-3.6-3-4.5s1-2.3 3-4.5z" fill="currentColor" opacity=".55"/><circle cx="62" cy="13" r="2" fill="currentColor"/><circle cx="98" cy="13" r="2" fill="currentColor"/><circle cx="56" cy="13" r="1" fill="currentColor" opacity=".6"/><circle cx="104" cy="13" r="1" fill="currentColor" opacity=".6"/></svg>
      <div class="btn-row btn-row--center mt-5">
        <a class="btn" href="menu.php">Browse The Menu</a>
        <a class="btn btn-ghost" href="index.php">Back To Home</a>
      </div>
    </div>
  </section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
