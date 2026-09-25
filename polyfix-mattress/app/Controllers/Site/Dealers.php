<?php

namespace App\Controllers\Site;

use App\Libraries\Seo;
use App\Libraries\Settings;
use App\Services\PublicFormService;
use CodeIgniter\HTTP\RedirectResponse;

class Dealers extends SiteController
{
    public function index(): string
    {
        $page    = $this->cmsPage('dealers');
        $trail   = [['Home', '/'], ['Dealers', '/dealers']];
        $service = PublicFormService::instance();

        $filter = static function (mixed $v, string $pattern): ?string {
            return is_string($v) && preg_match($pattern, trim($v)) === 1 ? trim($v) : null;
        };
        $state   = $filter($this->request->getGet('state'), '/^[\p{L} .&-]{2,80}$/u');
        $city    = $filter($this->request->getGet('city'), '/^[\p{L} .&-]{2,80}$/u');
        $pincode = $filter($this->request->getGet('pincode'), '/^[1-9][0-9]{5}$/');

        $locatorOn = Settings::flag('website.dealer_locator_enabled');
        $sections  = [];
        foreach ($page['sections'] as $s) {
            $sections[($s['type'] ?? '')] = $s;
        }

        $meta = Seo::meta('PAGE', 'dealers', 'Find a ' . brand('shortName') . ' Dealer | ' . brand('name'), (string) ($page['hero']['body'] ?? ''), '/dealers');
        if ($state !== null || $city !== null || $pincode !== null) {
            $meta['canonical'] = Seo::absolute('/dealers'); // filtered views are the same page
        }
        $this->response->setHeader('Cache-Control', 'no-store'); // the form carries a CSRF token

        return $this->render('site/page', $meta, [
            'hero'     => $page['hero'],
            'sections' => $page['sections'],
            'trail'    => $trail,
            'slots'    => [
                'dealer-locator' => view('site/partials/dealer_locator', [
                    'section' => $sections['dealer-locator'] ?? [],
                    'enabled' => $locatorOn,
                    'regions' => $locatorOn ? $service->regions() : [],
                    'dealers' => $locatorOn ? $service->dealers($state, $city, $pincode) : [],
                    'filter'  => ['state' => $state, 'city' => $city, 'pincode' => $pincode],
                ]),
                'become-dealer' => view('site/partials/dealer_application', [
                    'section' => $sections['become-dealer'] ?? [],
                    'enabled' => Settings::flag('website.dealer_application_enabled'),
                ]),
            ],
        ], [Seo::breadcrumbs($trail)], 'dealers');
    }

    public function apply(): RedirectResponse
    {
        $in = $this->validated([
            'business_name'             => 'required|max_length[180]|safe_text',
            'owner_name'                => 'required|max_length[160]|safe_text',
            'mobile'                    => 'required|indian_mobile',
            'email'                     => 'required|max_length[255]|valid_email',
            'city'                      => 'required|max_length[80]|safe_text',
            'state'                     => 'required|max_length[80]|safe_text',
            'address'                   => 'required|min_length[10]|max_length[400]|safe_text',
            'gst_number'                => 'permit_empty|gstin',
            'has_existing_business'     => 'permit_empty|in_list[0,1]',
            'existing_business_details' => 'permit_empty|max_length[1000]|safe_text',
            'message'                   => 'permit_empty|max_length[2000]|safe_text',
        ]);
        if ($in instanceof RedirectResponse) {
            return $in;
        }
        $in = array_map(static fn ($v) => is_string($v) ? trim($v) : $v, $in);

        return $this->act(
            fn () => PublicFormService::instance()->submitApplication($in, trim((string) $this->request->getPost('website')) !== ''),
            'Thank you for your interest. Our dealer development team will review your application and contact you.',
            site_url('dealers') . '#apply',
        );
    }
}
