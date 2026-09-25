<?php

namespace App\Libraries;

/**
 * Page metadata and schema.org structured data for the public website.
 *
 * Every field an administrator can edit in the SEO screen (title, description,
 * canonical path, social image, robots, keywords) comes from seo_metadata, so
 * changing SEO copy is a content action, not a code change. The page's own
 * defaults apply only when nothing is stored.
 *
 * Titles are used exactly as stored — they are complete ("… | POLYFIX
 * MATTRESS") and must not have the brand appended a second time.
 */
final class Seo
{
    public const DEFAULT_IMAGE = '/images/og/polyfix-default.svg';

    /**
     * @return array{title:string, description:string, canonical:string, image:string, robots:string,
     *               keywords:list<string>, og_title:string, og_description:string, twitter_card:string, type:string}
     */
    public static function meta(string $scope, string $key, string $fallbackTitle, string $fallbackDescription, string $path, ?string $image = null, string $type = 'website'): array
    {
        $row = db_connect()->table('seo_metadata')->where(['scope' => $scope, 'entity_key' => $key])->get()->getRowArray();

        $title       = $row['title'] ?? $fallbackTitle;
        $description = $row['description'] ?? $fallbackDescription;
        $keywords    = $row !== null ? (json_decode((string) $row['keywords'], true) ?: []) : [];
        $index       = $row === null || (bool) $row['robots_index'];
        $follow      = $row === null || (bool) $row['robots_follow'];

        // Indexing can be switched off site-wide (a staging copy, a launch).
        if (! Settings::flag('seo.robots_allow_indexing', true)) {
            $index = false;
        }

        return [
            'title'          => $title,
            'description'    => $description,
            'canonical'      => self::absolute($row['canonical_path'] ?? $path),
            'image'          => self::absolute($row['og_image_url'] ?? $image ?? self::DEFAULT_IMAGE),
            'robots'         => ($index ? 'index' : 'noindex') . ', ' . ($follow ? 'follow' : 'nofollow')
                . ($index ? ', max-image-preview:large, max-snippet:-1' : ''),
            'keywords'       => $keywords,
            'og_title'       => $row['og_title'] ?? $title,
            'og_description' => $row['og_description'] ?? $description,
            'twitter_card'   => $row['twitter_card'] ?? 'summary_large_image',
            'type'           => $type,
        ];
    }

    public static function absolute(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : rtrim(config('App')->baseURL, '/') . '/' . ltrim($path, '/');
    }

    public static function siteDescription(): string
    {
        return brand('name') . ' manufactures premium mattresses in hybrid, memory foam, latex and orthopaedic ranges. '
            . 'Serial-number traceability and online warranty verification on every unit.';
    }

    /** JSON-LD, escaped so that no stored string can close the script element. */
    public static function jsonLd(array $schema): string
    {
        $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        return '<script type="application/ld+json">' . $json . '</script>';
    }

    public static function organization(): array
    {
        $root    = self::absolute('/');
        $phone   = Settings::get('company.support_phone');
        $email   = Settings::get('company.support_email');
        $socials = array_values(array_filter(array_map(
            static fn ($k) => Settings::get($k),
            ['social.instagram', 'social.facebook', 'social.linkedin', 'social.youtube'],
        ), static fn ($v) => is_string($v) && $v !== ''));
        $location = brand('location');

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Organization',
            '@id'         => rtrim($root, '/') . '/#organization',
            'name'        => brand('name'),
            'legalName'   => brand('legalName'),
            'url'         => $root,
            'description' => self::siteDescription(),
            'logo'        => ['@type' => 'ImageObject', 'url' => self::absolute('/images/polyfix-logo.svg'), 'width' => 512, 'height' => 512],
            'address'     => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => $location['line'],
                'addressLocality' => $location['locality'],
                'addressRegion'   => $location['region'],
                'addressCountry'  => $location['countryCode'],
            ],
        ];
        if ($socials !== []) {
            $schema['sameAs'] = $socials;
        }
        if ($phone || $email) {
            $schema['contactPoint'] = [array_filter([
                '@type' => 'ContactPoint', 'contactType' => 'customer support',
                'telephone' => $phone ?: null, 'email' => $email ?: null,
                'areaServed' => 'IN', 'availableLanguage' => ['en', 'hi'],
            ])];
        }

        return $schema;
    }

    public static function website(): array
    {
        $root = rtrim(self::absolute('/'), '/');

        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'WebSite',
            '@id'        => $root . '/#website',
            'url'        => $root . '/',
            'name'       => brand('name'),
            'publisher'  => ['@id' => $root . '/#organization'],
            'inLanguage' => 'en-IN',
        ];
    }

    /** @param list<array{0:string, 1:string}> $trail [name, path] */
    public static function breadcrumbs(array $trail): array
    {
        $items = [];
        foreach ($trail as $i => [$name, $path]) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => self::absolute($path)];
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /** @param list<array{question:string, answer:string}> $faqs */
    public static function faqPage(array $faqs): array
    {
        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => array_map(static fn ($f) => [
                '@type' => 'Question', 'name' => $f['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['answer']],
            ], $faqs),
        ];
    }

    public static function product(array $product, array $variants, array $gallery): array
    {
        $prices = array_values(array_filter(array_map(static fn ($v) => (float) $v['mrp'], $variants)));
        $specs  = json_decode((string) ($product['specifications'] ?? '{}'), true) ?: [];
        $url    = self::absolute('/mattresses/' . $product['slug']);

        $schema = [
            '@context'     => 'https://schema.org',
            '@type'        => 'Product',
            '@id'          => $url . '#product',
            'name'         => $product['name'],
            'description'  => $product['short_description'],
            'category'     => 'Mattresses > ' . $product['category'],
            'brand'        => ['@type' => 'Brand', 'name' => brand('name')],
            'manufacturer' => ['@id' => rtrim(self::absolute('/'), '/') . '/#organization'],
            'url'          => $url,
            'image'        => array_map(static fn ($g) => self::absolute($g['src']), $gallery),
            'additionalProperty' => array_merge([
                ['@type' => 'PropertyValue', 'name' => 'Comfort level', 'value' => $product['comfort_level']],
                ['@type' => 'PropertyValue', 'name' => 'Firmness (1 soft – 10 firm)', 'value' => (string) $product['firmness_score']],
                ['@type' => 'PropertyValue', 'name' => 'Warranty', 'value' => $product['warranty_years'] . ' years'],
            ], array_map(static fn ($k, $v) => ['@type' => 'PropertyValue', 'name' => $k, 'value' => (string) $v], array_keys($specs), $specs)),
        ];

        if ($prices !== []) {
            $schema['offers'] = [
                '@type'         => 'AggregateOffer',
                'priceCurrency' => 'INR',
                'lowPrice'      => number_format(min($prices), 2, '.', ''),
                'highPrice'     => number_format(max($prices), 2, '.', ''),
                'offerCount'    => count($prices),
                'availability'  => 'https://schema.org/InStock',
                'seller'        => ['@id' => rtrim(self::absolute('/'), '/') . '/#organization'],
            ];
        }

        return $schema;
    }

    /** @param list<array{name:string, slug:string}> $products */
    public static function itemList(array $products): array
    {
        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'itemListElement' => array_map(static fn ($p, $i) => [
                '@type' => 'ListItem', 'position' => $i + 1, 'name' => $p['name'], 'url' => self::absolute('/mattresses/' . $p['slug']),
            ], $products, array_keys($products)),
        ];
    }
}
