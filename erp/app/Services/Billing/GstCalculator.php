<?php

namespace App\Services\Billing;

use App\Libraries\Dec;

/**
 * Pure GST computation (no DB). Tax TYPE (document), TREATMENT (line) and
 * RATE (line) are independent inputs.
 *
 * Rounding policy (documented, applied consistently):
 *  1. gross = qty × unit rate → 2 dp HALF_UP
 *  2. discount (amount, or % of gross) → 2 dp, capped at gross
 *  3. taxable value: exclusive pricing = gross − discount; inclusive pricing =
 *     back-calculated so taxable + tax == entered net exactly (residual paise
 *     absorbed in taxable value)
 *  4. each tax component per line = taxable × component rate → 2 dp HALF_UP
 *     (CGST and SGST/UTGST each get half the GST rate; IGST the full rate)
 *  5. document totals = Σ line values; grand total optionally rounded to the
 *     nearest rupee with an explicit round-off line.
 *
 * Example: taxable 10,000 @ 18% → CGST 9% 900 + SGST 9% 900 = 11,800,
 * or IGST 18% 1,800 = 11,800. Never 18% + 18%.
 */
final class GstCalculator
{
    /**
     * @param array{gst_type: ?string, price_mode?: string, rounding?: string} $header
     * @param list<array<string, mixed>>                                       $lines
     * @param array<string, bool>                                              $chargesTax treatment code => supplier charges GST
     *
     * @return array{lines: list<array<string, mixed>>, totals: array<string, string>, summary: list<array<string, string>>}
     */
    public static function compute(array $header, array $lines, array $chargesTax): array
    {
        $gstType  = $header['gst_type'] ?? null;
        $inclusive = ($header['price_mode'] ?? 'exclusive') === 'inclusive';
        $rounding = $header['rounding'] ?? 'nearest_rupee';

        $out     = [];
        $totals  = ['subtotal' => '0', 'discount_total' => '0', 'taxable_total' => '0', 'cgst_total' => '0', 'sgst_total' => '0',
            'utgst_total' => '0', 'igst_total' => '0', 'cess_total' => '0'];
        $summary = [];

        foreach (array_values($lines) as $i => $l) {
            $qty      = Dec::round($l['qty'] ?? '1', 3);
            $rate     = Dec::round($l['unit_rate'] ?? '0', 4);
            $gross    = Dec::mul($qty, $rate, 2);
            $discType = ($l['discount_type'] ?? 'amount') === 'percent' ? 'percent' : 'amount';
            $discVal  = Dec::round($l['discount_value'] ?? '0', 4);
            $discount = $discType === 'percent' ? Dec::pct($gross, Dec::min($discVal, '100')) : Dec::round($discVal);
            $discount = Dec::min(Dec::max($discount, '0'), Dec::max($gross, '0'));
            $net      = Dec::sub($gross, $discount);

            $treatment = (string) ($l['tax_treatment'] ?? 'regular');
            $gstRate   = Dec::round($l['gst_rate'] ?? '0', 3);
            $cessRate  = Dec::round($l['cess_rate'] ?? '0', 3);
            $charges   = ($chargesTax[$treatment] ?? false) && $gstType !== null && $gstType !== 'none';
            $half      = (string) Dec::of($gstRate)->dividedBy('2', 3, \Brick\Math\RoundingMode::HalfUp);

            $comp = ['cgst_rate' => '0', 'sgst_rate' => '0', 'utgst_rate' => '0', 'igst_rate' => '0', 'cess_rate' => '0'];
            if ($charges) {
                match ($gstType) {
                    'cgst_sgst'  => $comp = ['cgst_rate' => $half, 'sgst_rate' => $half] + $comp,
                    'cgst_utgst' => $comp = ['cgst_rate' => $half, 'utgst_rate' => $half] + $comp,
                    'igst'       => $comp = ['igst_rate' => $gstRate] + $comp,
                    default      => null,
                };
                $comp['cess_rate'] = $cessRate;
            }
            $taxFor = static function (string $taxable) use ($comp): array {
                return [
                    'cgst_amount'  => Dec::pct($taxable, $comp['cgst_rate']),
                    'sgst_amount'  => Dec::pct($taxable, $comp['sgst_rate']),
                    'utgst_amount' => Dec::pct($taxable, $comp['utgst_rate']),
                    'igst_amount'  => Dec::pct($taxable, $comp['igst_rate']),
                    'cess_amount'  => Dec::pct($taxable, $comp['cess_rate']),
                ];
            };

            if ($inclusive && $charges) {
                $effRate = Dec::add($gstRate, $cessRate);
                $taxable = Dec::div(Dec::mul($net, '100', 6), Dec::add('100', $effRate), 2);
                $tax     = $taxFor($taxable);
                $residual = Dec::sub($net, Dec::add($taxable, ...array_values($tax)));
                $taxable  = Dec::add($taxable, $residual); // keep line total == entered amount
            } else {
                $taxable = $net;
                $tax     = $taxFor($taxable);
            }
            $taxTotal  = Dec::add(...array_values($tax));
            $lineTotal = Dec::add($taxable, $taxTotal);

            $row = [
                'line_no'        => $i + 1,
                'qty'            => $qty,
                'unit_rate'      => $rate,
                'discount_type'  => $discType,
                'discount_value' => $discVal,
                'gross_amount'   => $gross,
                'discount_amount'=> $discount,
                'taxable_value'  => $taxable,
                'tax_treatment'  => $treatment,
                'gst_rate'       => $gstRate,
                'cgst_rate'      => $comp['cgst_rate'], 'cgst_amount' => $tax['cgst_amount'],
                'sgst_rate'      => $comp['sgst_rate'], 'sgst_amount' => $tax['sgst_amount'],
                'utgst_rate'     => $comp['utgst_rate'], 'utgst_amount' => $tax['utgst_amount'],
                'igst_rate'      => $comp['igst_rate'], 'igst_amount' => $tax['igst_amount'],
                'cess_rate'      => $comp['cess_rate'], 'cess_amount' => $tax['cess_amount'],
                'line_total'     => $lineTotal,
                'charges_tax'    => $charges,
            ] + array_intersect_key($l, array_flip(['service_id', 'item_name', 'description', 'sac_code', 'unit', 'id']));
            $out[] = $row;

            $totals['subtotal']       = Dec::add($totals['subtotal'], $gross);
            $totals['discount_total'] = Dec::add($totals['discount_total'], $discount);
            $totals['taxable_total']  = Dec::add($totals['taxable_total'], $taxable);
            foreach (['cgst', 'sgst', 'utgst', 'igst', 'cess'] as $t) {
                $totals[$t . '_total'] = Dec::add($totals[$t . '_total'], $tax[$t . '_amount']);
                if (Dec::isPositive($comp[$t . '_rate'])) {
                    $key = strtoupper($t) . '|' . Dec::rateLabel($comp[$t . '_rate']);
                    $summary[$key] ??= ['tax_type' => strtoupper($t), 'rate' => Dec::round($comp[$t . '_rate'], 3), 'taxable_value' => '0.00', 'tax_amount' => '0.00'];
                    $summary[$key]['taxable_value'] = Dec::add($summary[$key]['taxable_value'], $taxable);
                    $summary[$key]['tax_amount']    = Dec::add($summary[$key]['tax_amount'], $tax[$t . '_amount']);
                }
            }
        }

        $totals            = array_map(static fn ($v) => Dec::round($v), $totals);
        $totals['tax_total'] = Dec::add($totals['cgst_total'], $totals['sgst_total'], $totals['utgst_total'], $totals['igst_total'], $totals['cess_total']);
        $exact             = Dec::add($totals['taxable_total'], $totals['tax_total']);
        $grand             = $rounding === 'none' ? $exact : Dec::roundRupee($exact);
        $totals['round_off']   = Dec::sub($grand, $exact);
        $totals['grand_total'] = $grand;

        $order = ['CGST' => 1, 'SGST' => 2, 'UTGST' => 3, 'IGST' => 4, 'CESS' => 5];
        $summary = array_values($summary);
        usort($summary, static fn ($a, $b) => [$order[$a['tax_type']], (float) $a['rate']] <=> [$order[$b['tax_type']], (float) $b['rate']]);

        return ['lines' => $out, 'totals' => $totals, 'summary' => $summary];
    }
}
