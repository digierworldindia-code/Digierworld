<?php

namespace App\Controllers\Site;

use App\Libraries\Seo;

class Mattresses extends SiteController
{
    public function index(): string
    {
        $products = $this->catalogue()->products();
        $grouped  = [];
        foreach ($products as $product) {
            $grouped[$product['category']][] = $product;
        }
        $trail = [['Home', '/'], ['Mattresses', '/mattresses']];

        $meta = Seo::meta(
            'PAGE', 'mattresses',
            'Mattresses — Hybrid, Memory Foam, Latex & Orthopaedic | ' . brand('name'),
            'Compare the ' . brand('shortName') . ' mattress range by construction, firmness rating, materials and warranty term. Five sizes, sold through appointed dealers.',
            '/mattresses',
        );

        return $this->render('site/mattresses', $meta, ['grouped' => $grouped, 'trail' => $trail], [
            Seo::breadcrumbs($trail),
            [
                '@context'    => 'https://schema.org',
                '@type'       => 'CollectionPage',
                'name'        => brand('shortName') . ' mattresses',
                'url'         => Seo::absolute('/mattresses'),
                'description' => 'The ' . brand('shortName') . ' mattress range: hybrid, memory foam, natural latex, orthopaedic and dual firmness.',
            ],
            Seo::itemList($products),
        ], 'mattresses');
    }

    public function show(string $slug): string
    {
        // A slug that does not resolve is a real 404, so a withdrawn product
        // drops out of search results instead of becoming thin content.
        $product = $this->catalogue()->product($slug) ?? $this->notFound();
        $related = array_slice(array_values(array_filter($this->catalogue()->products(), static fn ($p) => $p['slug'] !== $slug)), 0, 3);
        $trail   = [['Home', '/'], ['Mattresses', '/mattresses'], [$product['name'], '/mattresses/' . $slug]];
        $prices  = array_map(static fn ($s) => (float) $s['mrp'], $product['sizes']);

        $meta = Seo::meta(
            'PRODUCT', $slug,
            $product['name'] . ' — ' . $product['category'] . ' Mattress | ' . brand('name'),
            (string) $product['short_description'],
            '/mattresses/' . $slug,
            $product['images'][0]['src'] ?? null,
            'product',
        );

        $schemas = [Seo::breadcrumbs($trail), Seo::product($product, $product['sizes'], $product['images'])];
        if ($product['faqs'] !== []) {
            $schemas[] = Seo::faqPage($product['faqs']);
        }

        return $this->render('site/product', $meta, [
            'p'       => $product,
            'related' => $related,
            'trail'   => $trail,
            'lowest'  => $prices === [] ? null : min($prices),
        ], $schemas, 'mattresses');
    }
}
