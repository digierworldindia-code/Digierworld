<?php

namespace App\Controllers\Site;

use App\Controllers\BaseController;
use App\Libraries\Catalogue;
use App\Libraries\Seo;

/**
 * Shared by the public website. Every page gets the organisation and website
 * schema; each page adds its own.
 */
abstract class SiteController extends BaseController
{
    protected ?Catalogue $catalogue = null;

    protected function catalogue(): Catalogue
    {
        return $this->catalogue ??= new Catalogue();
    }

    /**
     * @param array       $meta    from Seo::meta()
     * @param list<array> $schemas page-specific JSON-LD
     */
    protected function render(string $view, array $meta, array $data = [], array $schemas = [], string $active = ''): string
    {
        // Private: every response carries a session cookie and may carry a
        // flash message, so no shared cache may store it. The browser
        // revalidates. Pages with a form or a lookup result send no-store.
        if (! $this->response->hasHeader('Cache-Control')) {
            $this->response->setHeader('Cache-Control', 'private, no-cache');
        }

        return view($view, $data + [
            'meta'    => $meta,
            'schemas' => array_merge([Seo::organization(), Seo::website()], $schemas),
            'active'  => $active,
        ]);
    }

    /** A CMS page, or a 404 when it is unpublished. */
    protected function cmsPage(string $slug): array
    {
        return $this->catalogue()->page($slug) ?? $this->notFound();
    }
}
