<?php

namespace App\Controllers\Site;

use App\Libraries\Seo;

/**
 * Content pages. why-polyfix and about come from the CMS; privacy and terms
 * are legal text kept in the codebase so they change through review, not
 * through the content editor.
 */
class Pages extends SiteController
{
    private const STATIC = [
        'privacy' => ['Privacy policy', 'What this website and the platform collect, why, and how long it is kept.'],
        'terms'   => ['Terms of use', 'The terms that apply to this website, product information, the warranty and the dealer portal.'],
    ];

    public function show(string $slug): string
    {
        $trail = [['Home', '/'], [ucfirst(str_replace('-', ' ', $slug)), '/' . $slug]];

        if (isset(self::STATIC[$slug])) {
            [$title, $description] = self::STATIC[$slug];
            $meta = Seo::meta('PAGE', $slug, $title . ' | ' . brand('name'), $description, '/' . $slug);

            return $this->render('site/legal/' . $slug, $meta, ['trail' => $trail], [Seo::breadcrumbs($trail)]);
        }

        $page = $this->cmsPage($slug);
        if ($slug === 'why-polyfix') {
            $trail[1][0] = 'Why ' . brand('shortName');
        }
        $meta = Seo::meta(
            'PAGE', $slug,
            ($page['hero']['heading'] ?? $page['title']) . ' | ' . brand('name'),
            (string) ($page['hero']['body'] ?? Seo::siteDescription()),
            '/' . $slug,
            $page['hero']['image']['src'] ?? null,
        );

        return $this->render('site/page', $meta, [
            'hero' => $page['hero'], 'sections' => $page['sections'], 'trail' => $trail, 'slots' => [],
        ], [Seo::breadcrumbs($trail)], $slug);
    }
}
