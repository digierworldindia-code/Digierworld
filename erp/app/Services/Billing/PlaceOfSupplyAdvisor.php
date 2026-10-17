<?php

namespace App\Services\Billing;

/**
 * Suggests place of supply and GST type for SERVICES and validates the
 * user's choice. It encodes the default rule (IGST Act s.12(2): registered
 * recipient → recipient's location; otherwise recipient's address on record,
 * else supplier's location) and flags everything else for human review —
 * it never silently guesses special-rule cases.
 *
 * Outcome levels: ok · review (finalization needs an authorised resolution
 * with reason) · block (finalization impossible until fixed).
 */
final class PlaceOfSupplyAdvisor
{
    /**
     * @param array{
     *   doc_type: string, supplier_state: ?string, supplier_registration: string, supplier_has_gstin: bool,
     *   customer_gstin: ?string, customer_registration: ?string, customer_state: ?string,
     *   pos_state: ?string, gst_type: ?string, is_sez: bool, export_type: string, reverse_charge: bool,
     *   lines: list<array{tax_treatment: string, pos_rule?: string, gst_rate?: string}>,
     *   treatments: array<string, array{charges_tax: bool, requires_reason: bool, label: string}>,
     *   states: array<string, array{name: string, uses_utgst: bool, is_special: bool}>,
     *   lut_valid: bool, einvoice_applicable: bool, taxable_doc: bool
     * } $c
     *
     * @return array{suggested_pos: ?string, suggested_type: ?string, allowed_types: list<string>, status: string, messages: list<array{level: string, text: string}>, explanation: string}
     */
    public static function advise(array $c): array
    {
        $msgs  = [];
        $add   = static function (string $level, string $text) use (&$msgs): void {
            $msgs[] = ['level' => $level, 'text' => $text];
        };
        $states   = $c['states'];
        $supplier = $c['supplier_state'];
        $pos      = $c['pos_state'];
        $type     = $c['gst_type'];
        $custReg  = $c['customer_registration'] ?? 'unregistered';
        $gstinState = $c['customer_gstin'] ? substr((string) $c['customer_gstin'], 0, 2) : null;

        // ---- suggestion -------------------------------------------------
        $explain = '';
        if ($custReg === 'overseas' || $c['export_type'] !== 'none') {
            $suggestedPos = '96';
            $explain = 'Recipient is outside India → export of services; place of supply is outside India (code 96) if export conditions are met.';
        } elseif ($gstinState !== null && $custReg !== 'uin') {
            $suggestedPos = $gstinState;
            $explain = 'Registered recipient → default place of supply is the recipient\'s registered location (GSTIN state ' . $gstinState . ').';
        } elseif ($c['customer_state']) {
            $suggestedPos = $c['customer_state'];
            $explain = 'Unregistered recipient with address on record → place of supply is that address (state ' . $c['customer_state'] . ').';
        } else {
            $suggestedPos = $supplier;
            $explain = 'No recipient address on record → place of supply defaults to the supplier\'s location.';
            if ($supplier) {
                $add('warn', 'Recipient address/state is unknown, so the supplier\'s state is used as place of supply. Add the customer\'s address if available.');
            }
        }

        $allowedFor = static function (?string $p) use ($supplier, $states, $c): array {
            if ($p === null || $supplier === null) {
                return [];
            }
            if (($states[$p]['is_special'] ?? false) || $c['is_sez'] || $c['export_type'] !== 'none') {
                return ['igst', 'none'];
            }
            if ($p === $supplier) {
                return ($states[$p]['uses_utgst'] ?? false) ? ['cgst_utgst', 'none'] : ['cgst_sgst', 'none'];
            }

            return ['igst', 'none'];
        };
        $suggestedType = null;
        if ($c['taxable_doc']) {
            $sugAllowed    = $allowedFor($suggestedPos);
            $suggestedType = $sugAllowed[0] ?? null;
        } else {
            $suggestedType = 'none';
        }

        // ---- validation of the user's choice ----------------------------
        if (! $c['taxable_doc']) {
            if ($c['doc_type'] === 'bill_of_supply' && $type !== null && $type !== 'none') {
                $add('block', 'A bill of supply cannot charge GST. Use a tax invoice for taxable supplies.');
            }
        }
        if (in_array($c['doc_type'], ['tax_invoice', 'cash_memo', 'debit_note', 'credit_note'], true)) {
            if ($c['supplier_registration'] !== 'regular') {
                $add('block', 'Your company is set up as ' . $c['supplier_registration'] . '. Such suppliers cannot charge GST on a tax invoice/cash memo — issue a Bill of Supply and confirm with your accountant.');
            } elseif (! $c['supplier_has_gstin']) {
                $add('block', 'Supplier GSTIN is missing in Company Profile. Tax documents cannot be finalized without it.');
            }
        }
        if ($supplier === null) {
            $add('block', 'Supplier state is not set in Company Profile.');
        }
        if ($pos === null || $pos === '') {
            $add('block', 'Place of supply is required before finalization.');
        } elseif (! isset($states[$pos])) {
            $add('block', 'Unknown place-of-supply state code.');
        }

        $allowed = $allowedFor($pos);
        $taxableLines = array_filter($c['lines'], static fn ($l) => $c['treatments'][$l['tax_treatment']]['charges_tax'] ?? false);
        if ($type === null || $type === '') {
            if ($c['taxable_doc'] && $taxableLines !== []) {
                $add('block', 'Choose the GST type (CGST + SGST, CGST + UTGST or IGST).');
            }
        } elseif ($pos !== null && $allowed !== [] && ! in_array($type, $allowed, true)) {
            $label = ['cgst_sgst' => 'CGST + SGST', 'cgst_utgst' => 'CGST + UTGST', 'igst' => 'IGST', 'none' => 'No GST'][$type] ?? $type;
            $why = $pos === $supplier
                ? 'place of supply (' . $pos . ') is the same as your state — this is an intra-state supply' . (($states[$pos]['uses_utgst'] ?? false) ? ' in a Union Territory (CGST + UTGST)' : ' (CGST + SGST)')
                : 'place of supply (' . $pos . ') differs from your state (' . $supplier . ') — this is an inter-state supply (IGST)';
            if ($c['is_sez'] || $c['export_type'] !== 'none') {
                $why = 'supplies to SEZ units and exports are inter-state (IGST) by law';
            }
            $add('block', "{$label} is not legally possible here: {$why}.");
        }
        if ($type === 'none' && $taxableLines !== [] && $c['taxable_doc']) {
            $add('block', 'Lines with a taxable treatment need a GST type. Use "No GST" only when every line is exempt, nil-rated, non-GST, zero-rated under LUT or reverse charge.');
        }

        if ($pos !== null && $suggestedPos !== null && $pos !== $suggestedPos) {
            $add('review', 'Place of supply ' . $pos . ' differs from the suggested ' . $suggestedPos . '. ' . $explain . ' An authorised user must confirm this with a reason.');
        }
        if ($c['customer_state'] && $gstinState && $c['customer_state'] !== $gstinState) {
            $add('warn', 'Customer address state (' . $c['customer_state'] . ') differs from their GSTIN state (' . $gstinState . '). For registered recipients the GSTIN location normally governs.');
        }
        foreach ($c['lines'] as $l) {
            if (($l['pos_rule'] ?? 'default') === 'review_required') {
                $add('review', 'A service on this document is flagged for special place-of-supply rules (e.g. immovable property, events, performance-based services). Confirm the place of supply with your accountant.');
                break;
            }
        }
        $reasonTreatments = [];
        foreach ($c['lines'] as $l) {
            $t = $c['treatments'][$l['tax_treatment']] ?? null;
            if ($t === null) {
                $add('block', 'Unknown or disabled tax treatment: ' . $l['tax_treatment']);
            } elseif ($t['requires_reason']) {
                $reasonTreatments[$l['tax_treatment']] = $t['label'];
            }
            if (($t['charges_tax'] ?? false) && isset($l['gst_rate']) && (float) $l['gst_rate'] <= 0 && $l['tax_treatment'] === 'regular') {
                $add('warn', 'A regular taxable line has a 0% GST rate. If the service is nil-rated or exempt, choose that treatment instead.');
            }
        }
        if ($reasonTreatments !== []) {
            $add('review', 'Non-standard tax treatment used (' . implode(', ', $reasonTreatments) . '). Requires an authorised reviewer\'s reason, based on accountant-reviewed rules.');
        }
        if (isset($reasonTreatments['zero_rated_lut']) && ! $c['lut_valid']) {
            $add('block', 'Zero-rated supply without IGST requires a valid LUT in Company Profile (number and validity date covering the invoice date).');
        }
        if ($c['reverse_charge'] || isset($reasonTreatments['reverse_charge'])) {
            $add('warn', 'Reverse charge: tax is payable by the recipient; the invoice will state "Tax payable on reverse charge: Yes".');
        }
        if ($c['einvoice_applicable'] && $gstinState !== null && in_array($c['doc_type'], ['tax_invoice', 'credit_note', 'debit_note'], true)) {
            $add('warn', 'Your company is marked e-invoice applicable. No IRP integration is configured: generate the IRN on the e-invoice portal and record it — this app will not fabricate an IRN/QR.');
        }

        $levels = array_column($msgs, 'level');
        $status = in_array('block', $levels, true) ? 'block' : (in_array('review', $levels, true) ? 'review' : 'ok');

        return [
            'suggested_pos'  => $suggestedPos,
            'suggested_type' => $suggestedType,
            'allowed_types'  => $allowed,
            'status'         => $status,
            'messages'       => $msgs,
            'explanation'    => $explain,
        ];
    }
}
