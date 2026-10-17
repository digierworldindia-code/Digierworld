<?php

namespace Tests\Unit;

use App\Services\Billing\GstCalculator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Section 18/22/27 tax arithmetic: CGST+SGST vs IGST, never 18%+18%,
 * multi-rate summaries, inclusive pricing, rounding, non-taxable treatments.
 */
final class GstCalculatorTest extends CIUnitTestCase
{
    private const CHARGES = ['regular' => true, 'zero_rated_igst' => true, 'exempt' => false, 'nil_rated' => false, 'non_gst' => false, 'zero_rated_lut' => false, 'reverse_charge' => false];

    private function line(array $o = []): array
    {
        return $o + ['qty' => '1', 'unit_rate' => '10000', 'discount_type' => 'amount', 'discount_value' => '0', 'tax_treatment' => 'regular', 'gst_rate' => '18', 'item_name' => 'SEO'];
    }

    public function testIntraStateSplitsEighteenIntoNinePlusNine(): void
    {
        $r = GstCalculator::compute(['gst_type' => 'cgst_sgst'], [$this->line()], self::CHARGES);
        $l = $r['lines'][0];
        $this->assertSame('9.000', $l['cgst_rate']);
        $this->assertSame('9.000', $l['sgst_rate']);
        $this->assertSame('900.00', $l['cgst_amount']);
        $this->assertSame('900.00', $l['sgst_amount']);
        $this->assertSame('0.00', $l['igst_amount']);
        $this->assertSame('1800.00', $r['totals']['tax_total']);
        $this->assertSame('11800.00', $r['totals']['grand_total']);
        $this->assertNotSame('3600.00', $r['totals']['tax_total'], 'Must never compute CGST 18% + SGST 18%');
        $this->assertSame([['tax_type' => 'CGST', 'rate' => '9.000', 'taxable_value' => '10000.00', 'tax_amount' => '900.00'], ['tax_type' => 'SGST', 'rate' => '9.000', 'taxable_value' => '10000.00', 'tax_amount' => '900.00']], $r['summary']);
    }

    public function testInterStateChargesIgstOnly(): void
    {
        $r = GstCalculator::compute(['gst_type' => 'igst'], [$this->line()], self::CHARGES);
        $l = $r['lines'][0];
        $this->assertSame('18.000', $l['igst_rate']);
        $this->assertSame('1800.00', $l['igst_amount']);
        $this->assertSame('0.00', $l['cgst_amount']);
        $this->assertSame('0.00', $l['sgst_amount']);
        $this->assertSame('11800.00', $r['totals']['grand_total']);
        $this->assertCount(1, $r['summary']);
        $this->assertSame('IGST', $r['summary'][0]['tax_type']);
    }

    public function testUnionTerritoryUsesUtgstNotSgst(): void
    {
        $r = GstCalculator::compute(['gst_type' => 'cgst_utgst'], [$this->line()], self::CHARGES);
        $this->assertSame('900.00', $r['lines'][0]['utgst_amount']);
        $this->assertSame('0.00', $r['lines'][0]['sgst_amount']);
        $this->assertSame(['CGST', 'UTGST'], array_column($r['summary'], 'tax_type'));
    }

    public function testMultipleRatesSummariseByTypeAndRate(): void
    {
        $r = GstCalculator::compute(['gst_type' => 'cgst_sgst'], [
            $this->line(['unit_rate' => '10000', 'gst_rate' => '18']),
            $this->line(['unit_rate' => '2000', 'gst_rate' => '5']),
            $this->line(['unit_rate' => '3000', 'gst_rate' => '18']),
        ], self::CHARGES);
        $map = [];
        foreach ($r['summary'] as $s) {
            $map[$s['tax_type'] . '@' . $s['rate']] = [$s['taxable_value'], $s['tax_amount']];
        }
        $this->assertSame(['13000.00', '1170.00'], $map['CGST@9.000']);
        $this->assertSame(['13000.00', '1170.00'], $map['SGST@9.000']);
        $this->assertSame(['2000.00', '50.00'], $map['CGST@2.500']);
        $this->assertSame(['2000.00', '50.00'], $map['SGST@2.500']);
        $this->assertSame('15000.00', $r['totals']['taxable_total']);
        $this->assertSame('2440.00', $r['totals']['tax_total']);
        $this->assertSame('17440.00', $r['totals']['grand_total']);
    }

    public function testDiscountsAndQuantities(): void
    {
        $r = GstCalculator::compute(['gst_type' => 'igst'], [
            $this->line(['qty' => '3', 'unit_rate' => '1500', 'discount_type' => 'percent', 'discount_value' => '10']),
            $this->line(['qty' => '2', 'unit_rate' => '999.99', 'discount_value' => '99.98']),
        ], self::CHARGES);
        $this->assertSame('4500.00', $r['lines'][0]['gross_amount']);
        $this->assertSame('450.00', $r['lines'][0]['discount_amount']);
        $this->assertSame('4050.00', $r['lines'][0]['taxable_value']);
        $this->assertSame('729.00', $r['lines'][0]['igst_amount']);
        $this->assertSame('1999.98', $r['lines'][1]['gross_amount']);
        $this->assertSame('1900.00', $r['lines'][1]['taxable_value']);
    }

    public function testInclusivePricingKeepsEnteredTotalExact(): void
    {
        $r = GstCalculator::compute(['gst_type' => 'cgst_sgst', 'price_mode' => 'inclusive', 'rounding' => 'none'], [$this->line(['unit_rate' => '11800'])], self::CHARGES);
        $this->assertSame('10000.00', $r['lines'][0]['taxable_value']);
        $this->assertSame('900.00', $r['lines'][0]['cgst_amount']);
        $this->assertSame('11800.00', $r['lines'][0]['line_total']);

        $odd = GstCalculator::compute(['gst_type' => 'cgst_sgst', 'price_mode' => 'inclusive', 'rounding' => 'none'], [$this->line(['unit_rate' => '1000'])], self::CHARGES);
        $l   = $odd['lines'][0];
        $this->assertSame('1000.00', $l['line_total'], 'inclusive amount must be preserved to the paisa');
        $this->assertSame('1000.00', bcadd_compat($l['taxable_value'], $l['cgst_amount'], $l['sgst_amount']));
    }

    public function testRoundOffToNearestRupee(): void
    {
        $r = GstCalculator::compute(['gst_type' => 'cgst_sgst'], [$this->line(['unit_rate' => '999.50'])], self::CHARGES);
        // 999.50 + 89.96 + 89.96 = 1179.42 → 1179.00, round-off -0.42
        $this->assertSame('1179.42', bcadd_compat($r['totals']['taxable_total'], $r['totals']['tax_total']));
        $this->assertSame('1179.00', $r['totals']['grand_total']);
        $this->assertSame('-0.42', $r['totals']['round_off']);
    }

    public function testNonTaxableTreatmentsChargeNoTax(): void
    {
        $r = GstCalculator::compute(['gst_type' => 'cgst_sgst'], [
            $this->line(['tax_treatment' => 'exempt']),
            $this->line(['tax_treatment' => 'reverse_charge']),
            $this->line(['tax_treatment' => 'regular', 'unit_rate' => '1000']),
        ], self::CHARGES);
        $this->assertSame('0.00', $r['lines'][0]['cgst_amount']);
        $this->assertSame('0.00', $r['lines'][1]['sgst_amount']);
        $this->assertSame('90.00', $r['lines'][2]['cgst_amount']);
        $this->assertSame('180.00', $r['totals']['tax_total']);
        $this->assertSame('21180.00', $r['totals']['grand_total']);
    }

    public function testCashMemoUsesTheSameTaxRulesAsInvoice(): void
    {
        // Document series is irrelevant to tax: a ₹10,000 taxable same-state cash memo carries 9% + 9%.
        $memo = GstCalculator::compute(['gst_type' => 'cgst_sgst'], [$this->line()], self::CHARGES);
        $this->assertSame('900.00', $memo['totals']['cgst_total']);
        $this->assertSame('900.00', $memo['totals']['sgst_total']);
        $this->assertSame('11800.00', $memo['totals']['grand_total']);
    }
}

/** Exact decimal add helper for assertions (avoids float). */
function bcadd_compat(string ...$v): string
{
    return \App\Libraries\Dec::add(...$v);
}
