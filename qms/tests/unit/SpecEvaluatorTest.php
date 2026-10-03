<?php

namespace Tests\Unit;

use App\Exceptions\ValidationException;
use App\Services\Inspections\SpecEvaluator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * PASS / FAIL rules of docs/architecture/04-database-schema.md §4.5.
 *
 * @internal
 */
final class SpecEvaluatorTest extends CIUnitTestCase
{
    private SpecEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new SpecEvaluator();
    }

    /**
     * @return array<string, mixed>
     */
    private function numeric(?string $lsl = '6.450000', ?string $usl = '6.550000', int $places = 3): array
    {
        return ['observation_type' => 'NUMERIC', 'lsl' => $lsl, 'usl' => $usl, 'decimal_places' => $places, 'date_rule' => 'NONE'];
    }

    public function testLimitsAreInclusive(): void
    {
        $p = $this->numeric();
        $this->assertSame('PASS', $this->evaluator->evaluate($p, '6.450', '2026-10-03')['result']);
        $this->assertSame('PASS', $this->evaluator->evaluate($p, '6.55', '2026-10-03')['result']);
        $this->assertSame('FAIL', $this->evaluator->evaluate($p, '6.449', '2026-10-03')['result']);
        $this->assertSame('FAIL', $this->evaluator->evaluate($p, '6.551', '2026-10-03')['result']);
    }

    public function testStoresNormalisedValueAndAcceptsDecimalComma(): void
    {
        $out = $this->evaluator->evaluate($this->numeric(), '6,5', '2026-10-03');
        $this->assertSame('6.500000', $out['value_numeric']);
        $this->assertSame('PASS', $out['result']);
        $this->assertNull($out['value_choice']);
    }

    public function testOneSidedAndMissingLimits(): void
    {
        $this->assertSame('PASS', $this->evaluator->evaluate($this->numeric('5.000000', null), '99', '2026-10-03')['result']);
        $this->assertSame('FAIL', $this->evaluator->evaluate($this->numeric(null, '8.000000'), '8.001', '2026-10-03')['result']);
        $this->assertSame('NOT_APPLICABLE', $this->evaluator->evaluate($this->numeric(null, null), '3', '2026-10-03')['result']);
    }

    public function testTooManyDecimalsIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('at most 3 decimals');
        $this->evaluator->evaluate($this->numeric(), '6.4501', '2026-10-03');
    }

    public function testRejectsNonNumbers(): void
    {
        foreach (['6.4.5', 'abc', '1e2', '=1+1'] as $raw) {
            try {
                $this->evaluator->evaluate($this->numeric(), $raw, '2026-10-03');
                $this->fail("Accepted {$raw}");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('number', $e->getMessage());
            }
        }
    }

    public function testPercentageRange(): void
    {
        $p = ['observation_type' => 'PERCENTAGE', 'lsl' => '5.000000', 'usl' => '8.000000', 'decimal_places' => 1, 'date_rule' => 'NONE'];
        $this->assertSame('PASS', $this->evaluator->evaluate($p, '6.5', '2026-10-03')['result']);
        $this->expectException(ValidationException::class);
        $this->evaluator->evaluate($p, '100.5', '2026-10-03');
    }

    public function testChoices(): void
    {
        $ok = ['observation_type' => 'OK_NOT_OK'];
        $go = ['observation_type' => 'GO_NO_GO'];
        $this->assertSame('PASS', $this->evaluator->evaluate($ok, 'OK', '2026-10-03')['result']);
        $this->assertSame('FAIL', $this->evaluator->evaluate(['observation_type' => 'VISUAL'], 'NOT_OK', '2026-10-03')['result']);
        $this->assertSame('PASS', $this->evaluator->evaluate($go, 'GO', '2026-10-03')['result']);
        $this->assertSame('FAIL', $this->evaluator->evaluate($go, 'NO_GO', '2026-10-03')['result']);
        $this->expectException(ValidationException::class);
        $this->evaluator->evaluate($go, 'OK', '2026-10-03');
    }

    public function testDateRuleAndOtherTypes(): void
    {
        $due = ['observation_type' => 'DATE', 'date_rule' => 'ON_OR_AFTER_INSPECTION_DATE'];
        $this->assertSame('PASS', $this->evaluator->evaluate($due, '2026-10-03', '2026-10-03')['result']);
        $this->assertSame('FAIL', $this->evaluator->evaluate($due, '2026-10-02', '2026-10-03')['result']);
        $this->assertSame('NOT_APPLICABLE', $this->evaluator->evaluate(['observation_type' => 'TIME'], '07:30', '2026-10-03')['result']);
        $this->assertSame('07:30:00', $this->evaluator->evaluate(['observation_type' => 'TIME'], '07:30', '2026-10-03')['value_time']);
        $this->assertSame('NOT_APPLICABLE', $this->evaluator->evaluate(['observation_type' => 'TEXT'], 'Batch 7', '2026-10-03')['result']);
    }

    public function testEmptyValueClearsTheReading(): void
    {
        $out = $this->evaluator->evaluate($this->numeric(), '  ', '2026-10-03');
        $this->assertNull($out['result']);
        $this->assertNull($out['value_numeric']);
    }

    public function testAggregationAndOverallVerdict(): void
    {
        $this->assertSame('FAIL', $this->evaluator->aggregate([1 => 'PASS', 2 => 'FAIL'], 2, true));
        $this->assertSame('INCOMPLETE', $this->evaluator->aggregate([1 => 'PASS', 2 => null], 2, true));
        $this->assertSame('PASS', $this->evaluator->aggregate([1 => 'PASS', 2 => null], 2, false));
        $this->assertSame('NOT_APPLICABLE', $this->evaluator->aggregate([1 => null], 1, false));
        $this->assertSame('FAIL', $this->evaluator->aggregate([1 => 'FAIL', 2 => null], 2, true), 'a failure is reported even while incomplete');

        $this->assertSame('FAIL', $this->evaluator->overall(['PASS', 'FAIL', 'INCOMPLETE']));
        $this->assertSame('INCOMPLETE', $this->evaluator->overall(['PASS', 'INCOMPLETE']));
        $this->assertSame('PASS', $this->evaluator->overall(['PASS', 'NOT_APPLICABLE']));
    }

    public function testStoredValuesAreReEvaluatedWithTheSameRules(): void
    {
        $p = $this->numeric();
        $this->assertSame('FAIL', $this->evaluator->resultForStored($p, ['value_numeric' => '6.600000', 'value_choice' => null, 'value_date' => null, 'value_time' => null, 'value_text' => null], '6.450000', '6.550000', '2026-10-03'));
        $this->assertNull($this->evaluator->resultForStored($p, ['value_numeric' => null, 'value_choice' => null, 'value_date' => null, 'value_time' => null, 'value_text' => null], '6.45', '6.55', '2026-10-03'));
    }
}
