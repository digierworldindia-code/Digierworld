<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\BCTestCase;

/**
 * Configurable business-policy calculations read from platform settings.
 */
final class PolicyCalculationsTest extends BCTestCase
{
    public function testRiskLevelsFollowConfiguredThresholds(): void
    {
        $risk = service('risk');
        $this->assertSame('LOW', $risk->evaluate(['financial_risk' => 1, 'operational_risk' => 2, 'compliance_risk' => 2])['level']);
        $this->assertSame('MEDIUM', $risk->evaluate(['financial_risk' => 3, 'operational_risk' => 3])['level']);
        $this->assertSame('HIGH', $risk->evaluate(['financial_risk' => 5, 'operational_risk' => 4])['level']);
        $this->assertNull($risk->evaluate([])['level'], 'no evidence → no level');

        service('settings_store')->set('risk.thresholds', ['low_max' => 1.0, 'medium_max' => 2.0]);
        $this->assertSame('HIGH', $risk->evaluate(['financial_risk' => 3])['level']);
    }

    public function testInspectionFeeIsTenPercentWithOptionalMinimum(): void
    {
        $e = service('inspections')->estimate('in_house', 100000.0);
        $this->assertSame(10000.0, $e['fee']);
        $this->assertNull(service('inspections')->estimate('third_party', 100000.0)['fee'], 'third-party is always quoted');

        service('settings_store')->set('inspection.in_house_min_fee', '15000');
        $this->assertSame(15000.0, service('inspections')->estimate('in_house', 100000.0)['fee']);
    }

    public function testLogisticsChargeIsNotAssumedUntilBaseIsApproved(): void
    {
        $this->assertNull(service('logistics')->estimate('platform_managed', 50000.0)['fee'], 'default base is manual quote');
        service('settings_store')->set('logistics.platform_fee_basis', 'goods_value');
        service('settings_store')->flush();
        $this->assertSame(5000.0, service('logistics')->estimate('platform_managed', 50000.0)['fee']);
    }
}
