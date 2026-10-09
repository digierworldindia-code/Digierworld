<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RiskAssessmentModel;
use App\Models\SupplierProfileModel;

/**
 * Evidence-based risk scoring with configurable weights and thresholds.
 * Each factor is scored 1 (low risk) – 5 (high risk) by a reviewer who must
 * record supporting evidence; the weighted average maps to LOW/MEDIUM/HIGH.
 */
class RiskService
{
    public const FACTORS = [
        'financial_risk'   => 'Financial risk',
        'operational_risk' => 'Operational risk',
        'compliance_risk'  => 'Compliance risk',
        'geographic_risk'  => 'Geographic risk',
        'supply_risk'      => 'Supply risk',
        'esg_risk'         => 'ESG risk',
    ];

    /**
     * @return array{score:float|null,level:string|null}
     */
    public function evaluate(array $scores): array
    {
        $weights = (array) policy('risk.weights', array_fill_keys(array_keys(self::FACTORS), 1));
        $sum     = 0.0;
        $wsum    = 0.0;
        foreach (self::FACTORS as $k => $_) {
            if (isset($scores[$k]) && $scores[$k] !== '' && $scores[$k] !== null) {
                $w = (float) ($weights[$k] ?? 1);
                $sum += $w * (int) $scores[$k];
                $wsum += $w;
            }
        }
        if ($wsum <= 0) {
            return ['score' => null, 'level' => null];
        }
        $score = round($sum / $wsum, 2);
        $t     = (array) policy('risk.thresholds', ['low_max' => 2.0, 'medium_max' => 3.5]);
        $level = $score <= (float) ($t['low_max'] ?? 2.0) ? 'LOW' : ($score <= (float) ($t['medium_max'] ?? 3.5) ? 'MEDIUM' : 'HIGH');

        return ['score' => $score, 'level' => $level];
    }

    public function record(int $companyId, ?int $applicationId, array $input, int $userId): array
    {
        $row = ['company_id' => $companyId, 'application_id' => $applicationId, 'evidence_notes' => trim((string) ($input['evidence_notes'] ?? '')), 'assessed_by' => $userId, 'assessed_at' => date('Y-m-d H:i:s')];
        foreach (self::FACTORS as $k => $_) {
            $v       = $input[$k] ?? '';
            $row[$k] = ($v === '' || $v === null) ? null : max(1, min(5, (int) $v));
        }
        if ($row['evidence_notes'] === '') {
            throw new \App\Exceptions\BusinessRuleException('Risk assessments must record the supporting evidence.');
        }
        $eval                  = $this->evaluate($row);
        $row['weighted_score'] = $eval['score'];
        $row['risk_level']     = $eval['level'];
        $row['id']             = (int) model(RiskAssessmentModel::class)->insert($row);

        model(SupplierProfileModel::class)->where('company_id', $companyId)->set(['risk_level' => $eval['level']])->update();
        service('audit')->log('risk.assessed', ['entity_type' => 'company', 'entity_id' => $companyId, 'company_id' => $companyId, 'description' => "Risk {$eval['level']} ({$eval['score']})"]);

        return $row;
    }
}
