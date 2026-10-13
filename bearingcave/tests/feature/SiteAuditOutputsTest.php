<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\BCTestCase;

/**
 * Workbook "Site Audit & Factory Assessment — Outputs" and the document types
 * named under Supplier Verification / Document Management.
 */
final class SiteAuditOutputsTest extends BCTestCase
{
    public function testAuditScoreIsDerivedAndOutputsAreStored(): void
    {
        $s       = $this->registerCompany('supplier');
        $app     = service('verification')->openApplication($s['company']);
        $officer = $this->staff('verification_officer');
        $ratings = ['manufacturing_capability' => '4', 'production_capacity' => '4', 'machinery_condition' => '5', 'warehouse_management' => '3',
            'calibration' => '4', 'workforce_capability' => '4', 'quality_processes' => '4'];

        $this->postAs($officer, "admin/verifications/{$app['id']}/audit", $ratings + ['result' => 'conditional', 'conducted_on' => date('Y-m-d'),
            'findings' => 'Calibration records incomplete.', 'corrective_actions' => 'Complete calibration log by month end.', 'recommendation' => 'approve_with_conditions',
            'audit_score' => '99'])->assertRedirect();

        $row = db_connect()->table('site_audits')->where('company_id', $s['company']['id'])->get()->getRowArray();
        $this->assertSame(80.0, (float) $row['audit_score'], 'average 4/5 = 80; a typed-in score is ignored');
        $this->assertSame('approve_with_conditions', $row['recommendation']);
        $this->assertSame('Complete calibration log by month end.', $row['corrective_actions']);
        $this->getAs($officer, 'admin/verifications/' . $app['id'])->assertSee('80.00/100');

        $page = $this->getAs($s['user'], 'supplier/verification');
        foreach (['MSME certificate', 'Import Export Code (IEC)', 'NDA', 'Test report', 'Certificate of Incorporation'] as $type) {
            $page->assertSee($type);
        }
    }
}
