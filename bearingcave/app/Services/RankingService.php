<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompanyModel;
use App\Models\SupplierProfileModel;

/**
 * Marketplace ranking score = performance score (0–100, if any data)
 * + plan priority boost (entitlement "priority_ranking", limit = points).
 * Free/unverified suppliers therefore rank lower by default.
 */
class RankingService
{
    public function refresh(int $companyId): void
    {
        $company = model(CompanyModel::class)->find($companyId);
        if (! $company || $company['company_type'] !== 'supplier') {
            return;
        }
        service('entitlements')->flush();
        $ent     = service('entitlements');
        $profile = model(SupplierProfileModel::class)->where('company_id', $companyId)->first();
        $boost   = $ent->has($company, 'priority_ranking') ? (float) ($ent->limit($company, 'priority_ranking') ?? 50) : 0.0;
        $score   = round((float) ($profile['performance_score'] ?? 0) * (float) policy('ranking.performance_weight', 1) + $boost, 2);
        model(SupplierProfileModel::class)->where('company_id', $companyId)->set(['ranking_score' => $score])->update();
    }
}
