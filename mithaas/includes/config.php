<?php
/**
 * Mithaas — site configuration and shared helpers.
 *
 * This holds the values the PHP layer needs: the public URL, the navigation,
 * and SEO defaults.
 *
 * NOTE ON BUSINESS DETAILS
 * Phone, WhatsApp, email, address, opening hours, social links and prices are
 * NOT duplicated here. They live in assets/js/config.js, which is already the
 * single source of truth for them and is read by the browser at runtime.
 * Copying them into PHP as well would create a second place to edit and let
 * the two drift apart. Edit assets/js/config.js to change any of them.
 */

declare(strict_types=1);

/** Public base URL, no trailing slash. Change this when the domain changes. */
const SITE_URL  = 'https://www.digierworld.com/mithaas';
const SITE_NAME = 'Mithaas';

/** Default social share image, relative to SITE_URL. */
const OG_IMAGE  = '/assets/img/brand/og-mithaas.jpg';

/** Primary navigation: file => label. Order is the order shown. */
const NAV = [
    'index.php'      => 'Home',
    'our-story.php'  => 'Our Story',
    'sweets.php'     => 'Sweets',
    'bakery.php'     => 'Bakery',
    'restaurant.php' => 'Restaurant',
    'menu.php'       => 'Menu',
    'gallery.php'    => 'Gallery',
    'contact.php'    => 'Contact',
];

/**
 * Escape for HTML output. ENT_COMPAT leaves single quotes alone, which keeps
 * the markup byte-identical to the hand-written HTML this was migrated from.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_COMPAT, 'UTF-8');
}

/**
 * The current page's filename, e.g. "menu.php".
 * A request for a directory ("/" or "/mithaas/") resolves to index.php.
 */
function current_page(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $file = basename($path);
    if ($file === '' || !str_contains($file, '.')) {
        return 'index.php';
    }
    return $file;
}

/** Absolute URL for a path relative to the site root. */
function site_url(string $path = ''): string
{
    return SITE_URL . ($path !== '' && $path[0] !== '/' ? '/' : '') . $path;
}

/**
 * BreadcrumbList JSON-LD from a trail of [label => file] pairs.
 * The final entry is the current page and carries no link, matching the
 * visible breadcrumb.
 */
function breadcrumb_jsonld(array $trail): string
{
    $items = [];
    $i = 0;
    foreach ($trail as $name => $file) {
        $items[] = [
            '@type'    => 'ListItem',
            'position' => ++$i,
            'name'     => $name,
            'item'     => site_url($file),
        ];
    }

    return json_encode(
        ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
}
