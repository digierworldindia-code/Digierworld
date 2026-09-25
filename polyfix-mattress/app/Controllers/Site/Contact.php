<?php

namespace App\Controllers\Site;

use App\Libraries\Seo;
use App\Libraries\Settings;
use App\Services\PublicFormService;
use CodeIgniter\HTTP\RedirectResponse;

class Contact extends SiteController
{
    public function index(): string
    {
        $page  = $this->cmsPage('contact');
        $trail = [['Home', '/'], ['Contact', '/contact']];
        $meta  = Seo::meta('PAGE', 'contact', 'Contact ' . brand('name'), (string) ($page['hero']['body'] ?? ''), '/contact');
        $this->response->setHeader('Cache-Control', 'no-store'); // the form carries a CSRF token

        return $this->render('site/page', $meta, [
            'hero'     => $page['hero'],
            'sections' => $page['sections'],
            'trail'    => $trail,
            'slots'    => [
                'contact-form'    => view('site/partials/contact_form', ['enabled' => Settings::flag('website.contact_form_enabled')]),
                'contact-details' => view('site/partials/contact_details'),
            ],
        ], [Seo::breadcrumbs($trail)], 'contact');
    }

    public function submit(): RedirectResponse
    {
        $in = $this->validated([
            'name'        => 'required|max_length[160]|safe_text',
            'phone'       => 'required|indian_mobile',
            'email'       => 'permit_empty|max_length[255]|valid_email',
            'city'        => 'permit_empty|max_length[80]|safe_text',
            'requirement' => 'required|in_list[' . implode(',', array_keys(PublicFormService::REQUIREMENTS)) . ']',
            'message'     => 'required|min_length[10]|max_length[2000]|safe_text',
        ], ['message' => ['min_length' => 'Tell us a little more so we can help.']]);
        if ($in instanceof RedirectResponse) {
            return $in;
        }
        $in = array_map(static fn ($v) => is_string($v) ? trim($v) : $v, $in);

        return $this->act(
            fn () => PublicFormService::instance()->submitLead($in, trim((string) $this->request->getPost('website')) !== ''),
            'Thank you. Our team will be in touch shortly.',
            site_url('contact') . '#enquiry',
        );
    }
}
