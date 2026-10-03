<?php

namespace Tests\Unit;

use App\Services\Inspections\DecimalComparator;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * @internal
 */
final class DecimalComparatorTest extends CIUnitTestCase
{
    public function testRecognisesDecimals(): void
    {
        foreach (['0', '6.45', '-0.050', '+12', '123456789012.123456'] as $value) {
            $this->assertTrue(DecimalComparator::isDecimal($value), $value);
        }
        foreach (['', '.5', '6.', '1e3', '6,45', '1.1234567', '1234567890123', 'abc', '6.45 '] as $value) {
            $this->assertFalse(DecimalComparator::isDecimal($value), $value);
        }
    }

    public function testComparesExactlyWithoutFloatErrors(): void
    {
        // 0.1 + 0.2 style problems cannot happen: values are compared as integers.
        $this->assertSame(0, DecimalComparator::compare('6.550', '6.55'));
        $this->assertSame(1, DecimalComparator::compare('6.550001', '6.55'));
        $this->assertSame(-1, DecimalComparator::compare('-0.000001', '0'));
        $this->assertSame(1, DecimalComparator::compare('100000000000.000001', '100000000000'));
    }

    public function testCountsDecimalsAndNormalises(): void
    {
        $this->assertSame(3, DecimalComparator::decimals('6.450'));
        $this->assertSame(0, DecimalComparator::decimals('6'));
        $this->assertSame('6.500000', DecimalComparator::normalise('6.5'));
        $this->assertSame('-0.050000', DecimalComparator::normalise('-0.05'));
        $this->assertSame('12.000000', DecimalComparator::normalise('+12'));
    }

    public function testRejectsInvalidInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DecimalComparator::scaled('1e5');
    }
}
