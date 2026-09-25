<?php

namespace App\Controllers\Site;

use App\Libraries\Seo;

class Home extends SiteController
{
    public function index(): string
    {
        $page     = $this->cmsPage('home');
        $products = $this->catalogue()->products();
        $featured = array_values(array_filter($products, static fn ($p) => (bool) $p['is_featured']));
        $showcase = array_slice($featured !== [] ? $featured : $products, 0, 3);
        $prices   = array_filter(array_map(static fn ($p) => $p['price_from'] === null ? null : (float) $p['price_from'], $products));
        $faqs     = array_slice(array_merge($this->catalogue()->faqs('buying'), $this->catalogue()->faqs('authenticity')), 0, 5);

        $meta = Seo::meta(
            'PAGE', 'home',
            brand('name') . ' — Premium Mattresses & Sleep Solutions',
            brand('name') . ' manufactures premium hybrid, memory foam, latex and orthopaedic mattresses. Every unit carries a serial number you can verify online.',
            '/', $page['hero']['image']['src'] ?? null,
        );

        return $this->render('site/home', $meta, [
            'hero'     => $page['hero'],
            'sections' => $page['sections'],
            'slots'    => ['products' => $showcase],
            'stats'    => [
                'ranges'    => count($products),
                'maxYears'  => $products === [] ? null : max(array_map(static fn ($p) => (int) $p['warranty_years'], $products)),
                'lowest'    => $prices === [] ? null : min($prices),
            ],
            'faqs' => $faqs,
        ], $faqs !== [] ? [Seo::faqPage($faqs)] : []);
    }
}
