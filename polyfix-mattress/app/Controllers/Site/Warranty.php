<?php

namespace App\Controllers\Site;

use App\Libraries\Seo;
use App\Services\AuthService;
use App\Services\VerificationService;

/**
 * The warranty page and the public verification lookup.
 *
 * /warranty/verify is the address printed in every QR label, so it must keep
 * working for as long as labels exist. It answers with the warranty page and
 * the result in place — no JavaScript involved, so it works on any phone.
 * The URL identifies one physical mattress, so it is noindex and never cached.
 */
class Warranty extends SiteController
{
    public function index(): string
    {
        return $this->renderPage(null, null, null);
    }

    public function verify(): string
    {
        $token  = $this->request->getGet('q');
        $serial = $this->request->getGet('serial');
        $token  = is_string($token) && $token !== '' ? mb_substr($token, 0, 80) : null;
        $serial = is_string($serial) && trim($serial) !== '' ? mb_substr(trim($serial), 0, 20) : null;

        if ($token === null && $serial === null) {
            return $this->renderPage(null, null, null);
        }

        $result = VerificationService::instance()->verify($token === null ? $serial : null, $token);
        $this->response->setHeader('Cache-Control', 'no-store');

        // A signed-in dealer scanning a label with the phone camera lands here.
        // Offer the portal's view of the same unit; the portal re-checks that
        // the unit is theirs before showing anything more.
        $shortcut = null;
        $user     = AuthService::instance()->resolve(session());
        if ($user !== null && $user['dealer_id'] !== null && $result['found']) {
            $shortcut = site_url('dealer/scan') . '?serial=' . rawurlencode($result['serial']);
        }

        return $this->renderPage($result, $serial, $shortcut);
    }

    private function renderPage(?array $result, ?string $serial, ?string $shortcut): string
    {
        $page  = $this->cmsPage('warranty');
        $trail = [['Home', '/'], ['Warranty', '/warranty']];
        $faqs  = array_merge($this->catalogue()->faqs('warranty'), $this->catalogue()->faqs('authenticity'));

        $meta = Seo::meta(
            'PAGE', 'warranty',
            'Mattress Warranty & Serial Number Verification | ' . brand('name'),
            'Verify a ' . brand('shortName') . ' mattress by QR code or serial number. See the product, manufacturing date and warranty status, and learn how to raise a warranty claim.',
            '/warranty',
        );
        if ($result !== null) {
            $meta['robots'] = 'noindex, nofollow';
        }

        $form = view('site/partials/verify_form', [
            'section'  => $this->sectionOf($page['sections'], 'verify-form'),
            'result'   => $result,
            'serial'   => $serial,
            'shortcut' => $shortcut,
        ]);

        return $this->render('site/warranty', $meta, [
            'hero'     => $page['hero'],
            'sections' => $page['sections'],
            'slots'    => ['verify-form' => $form],
            'trail'    => $trail,
            'faqs'     => $faqs,
        ], array_merge([Seo::breadcrumbs($trail)], $faqs !== [] ? [Seo::faqPage($faqs)] : []), 'warranty');
    }

    private function sectionOf(array $sections, string $type): array
    {
        foreach ($sections as $s) {
            if (($s['type'] ?? null) === $type) {
                return $s;
            }
        }

        return [];
    }
}
