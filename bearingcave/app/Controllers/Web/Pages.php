<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\CmsPageModel;
use App\Models\MembershipPlanModel;
use App\Models\PlanEntitlementModel;
use App\Services\EntitlementService;

class Pages extends BaseController
{
    private const PAGES = [
        'how-it-works'  => ['How BearingCave works', 'From surplus stock to secure B2B transactions: listing, verification, RFQs, inspection, logistics and payment.'],
        'for-buyers'    => ['For buyers', 'Source genuine branded automotive parts from verified manufacturers and suppliers at competitive surplus prices.'],
        'for-suppliers' => ['For suppliers & manufacturers', 'Turn surplus, obsolete and slow-moving automotive inventory into working capital — confidentially.'],
        'inspection'    => ['Inspection services', 'Optional in-house and third-party inspection: quantity, packaging, physical and batch authenticity checks.'],
        'logistics'     => ['Logistics services', 'Supplier-direct, BearingCave-managed, confidential and consolidated shipping for B2B orders.'],
        'trust'         => ['Trust & verification', 'How BearingCave verifies suppliers: KYC/KYB, compliance, financial, quality, site audit and sanctions screening.'],
        'about'         => ['About BearingCave', 'BearingCave connects holders of surplus automotive inventory with professional buyers worldwide.'],
    ];

    public function show(string $page): string
    {
        if (! isset(self::PAGES[$page])) {
            $this->notFound();
        }
        [$title, $desc] = self::PAGES[$page];
        $path = ['inspection' => 'services/inspection', 'logistics' => 'services/logistics', 'trust' => 'trust-and-verification'][$page] ?? $page;

        return view('web/pages/' . $page, ['title' => $title, 'metaDescription' => $desc, 'canonical' => site_url($path)]);
    }

    public function membership(): string
    {
        $plans = model(MembershipPlanModel::class)->where('audience', 'supplier')->where('is_active', 1)->orderBy('sort_order')->findAll();
        $ents  = [];
        foreach ($plans as $p) {
            $ents[$p['id']] = array_column(model(PlanEntitlementModel::class)->where('plan_id', $p['id'])->findAll(), null, 'feature_key');
        }

        return view('web/membership', [
            'title' => 'Supplier membership plans', 'canonical' => site_url('membership'),
            'metaDescription' => 'Compare the free Unverified Supplier plan with the Verified Supplier plan on BearingCave.',
            'plans' => $plans, 'ents' => $ents, 'features' => array_filter(EntitlementService::FEATURES, static fn ($f) => $f[1] === 'supplier'),
        ]);
    }

    public function cms(string $slug): string
    {
        $page = model(CmsPageModel::class)->where('slug', $slug)->where('status', 'published')->first();
        if (! $page) {
            $this->notFound();
        }

        return view('web/cms', ['title' => $page['meta_title'] ? preg_replace('/ \| BearingCave$/', '', $page['meta_title']) : $page['title'], 'metaDescription' => $page['meta_description'], 'canonical' => site_url('page/' . $slug), 'page' => $page]);
    }
}
