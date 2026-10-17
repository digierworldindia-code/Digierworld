<?php

namespace App\Services\Reports;

use App\Libraries\Dec;
use CodeIgniter\Database\BaseConnection;

/**
 * GST working papers built from finalized documents and approved expenses:
 * output tax, input tax credit, indicative payable with statutory set-off,
 * and GSTR-1 / GSTR-3B preparation sheets.
 *
 * These are preparation aids for the accountant, not files generated or
 * validated by the GST portal. Cash memos are taxable supplies and are
 * included exactly like tax invoices.
 */
class GstReturnService
{
    /** Unregistered inter-state invoices above this value go to B2CL (GSTR-1 Table 5). */
    public const B2CL_LIMIT = '100000.00';

    private const SALE_TYPES = ['tax_invoice', 'bill_of_supply', 'cash_memo', 'credit_note', 'debit_note'];

    /** @var array<string, string>|null */
    private ?array $stateNames = null;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    private function company(): array
    {
        return $this->db->table('company_settings')->where('id', 1)->get()->getRowArray() ?? [];
    }

    private function pos(?string $code): string
    {
        $this->stateNames ??= array_column($this->db->table('states')->get()->getResultArray(), 'name', 'code');

        return $code ? $code . '-' . ($this->stateNames[$code] ?? '') : '';
    }

    /**
     * Finalized sale-side line items in the period with document context and sign.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(string $from, string $to): array
    {
        return $this->db->query(
            "SELECT d.id AS doc_id, d.doc_type, d.document_number, d.issue_date, d.bill_to_name, d.bill_to_gstin, d.customer_type, d.supplier_state_code,
                    d.place_of_supply_state_code AS pos, d.gst_type, d.reverse_charge, d.is_sez_recipient, d.export_type, d.grand_total, d.original_document_id,
                    l.sac_code, l.item_name, l.unit, l.qty, l.tax_treatment, l.gst_rate, l.taxable_value, l.cgst_amount, l.sgst_amount, l.utgst_amount, l.igst_amount, l.cess_amount,
                    CASE WHEN d.doc_type = 'credit_note' THEN -1 ELSE 1 END AS sign
               FROM billing_documents d JOIN billing_line_items l ON l.document_id = d.id
              WHERE d.status = 'finalized' AND d.doc_type IN ('" . implode("','", self::SALE_TYPES) . "') AND d.issue_date BETWEEN ? AND ?
              ORDER BY d.issue_date, d.id, l.line_no",
            [$from, $to]
        )->getResultArray();
    }

    private static function signed(array $l, string $key): string
    {
        return (int) $l['sign'] < 0 ? Dec::neg($l[$key]) : Dec::round($l[$key]);
    }

    private static function isRegistered(array $l): bool
    {
        return ! empty($l['bill_to_gstin']);
    }

    private static function isExport(array $l): bool
    {
        return $l['customer_type'] === 'export' || ($l['export_type'] !== 'none' && empty($l['is_sez_recipient']) && $l['customer_type'] !== 'sez');
    }

    /** Output tax by GST type and rate. @return list<array<string, mixed>> */
    public function outputSummary(string $from, string $to): array
    {
        $out = [];
        foreach ($this->lines($from, $to) as $l) {
            $k = $l['gst_type'] . '|' . Dec::rateLabel($l['gst_rate']) . '|' . $l['tax_treatment'];
            $out[$k] ??= ['gst_type' => gst_type_label($l['gst_type']), 'treatment' => $l['tax_treatment'], 'rate' => Dec::round($l['gst_rate'], 3),
                'taxable' => '0', 'cgst' => '0', 'sgst' => '0', 'utgst' => '0', 'igst' => '0', 'cess' => '0', 'tax' => '0'];
            foreach (['taxable' => 'taxable_value', 'cgst' => 'cgst_amount', 'sgst' => 'sgst_amount', 'utgst' => 'utgst_amount', 'igst' => 'igst_amount', 'cess' => 'cess_amount'] as $dst => $src) {
                $out[$k][$dst] = Dec::add($out[$k][$dst], self::signed($l, $src));
            }
            $out[$k]['tax'] = Dec::add($out[$k]['cgst'], $out[$k]['sgst'], $out[$k]['utgst'], $out[$k]['igst'], $out[$k]['cess']);
        }
        ksort($out);

        return array_values($out);
    }

    /** Approved expenses in the period with their input tax. @return list<array<string, mixed>> */
    public function itcRegister(string $from, string $to): array
    {
        $rows = $this->db->table('expenses e')
            ->select('e.expense_number, e.expense_date, e.vendor_invoice_no, e.vendor_invoice_date, e.vendor_gstin, v.name AS vendor_name, c.name AS category, e.gst_type, e.gst_rate,
                e.taxable_amount, e.cgst_amount, e.sgst_amount, e.utgst_amount, e.igst_amount, e.total_amount, e.itc_eligible_amount, e.itc_ineligible_amount, r.name AS itc_rule')
            ->join('contacts v', 'v.id = e.vendor_contact_id', 'left')->join('expense_categories c', 'c.id = e.category_id')->join('itc_rules r', 'r.id = e.itc_rule_id', 'left')
            ->where('e.approval_status', 'approved')->where('e.deleted_at', null)->where('e.expense_date >=', $from)->where('e.expense_date <=', $to)
            ->orderBy('e.expense_date')->orderBy('e.id')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['tax_total'] = Dec::add($r['cgst_amount'], $r['sgst_amount'], $r['utgst_amount'], $r['igst_amount']);
        }

        return $rows;
    }

    /**
     * Eligible ITC per head, split exactly as posted to the input-tax ledgers.
     *
     * @return array{igst: string, cgst: string, sgst: string}
     */
    public function itcByHead(string $from, string $to): array
    {
        $rows = $this->db->query(
            "SELECT la.sub_type, COALESCE(SUM(jl.debit - jl.credit),0) amt FROM journal_lines jl
               JOIN journal_entries je ON je.id = jl.journal_entry_id JOIN ledger_accounts la ON la.id = jl.ledger_account_id
              WHERE je.source_type = 'expense' AND je.entry_date BETWEEN ? AND ? AND la.sub_type IN ('input_igst','input_cgst','input_sgst','input_utgst')
              GROUP BY la.sub_type",
            [$from, $to]
        )->getResultArray();
        $m = array_column($rows, 'amt', 'sub_type');

        return [
            'igst' => Dec::round($m['input_igst'] ?? '0'),
            'cgst' => Dec::round($m['input_cgst'] ?? '0'),
            'sgst' => Dec::add($m['input_sgst'] ?? '0', $m['input_utgst'] ?? '0'),
        ];
    }

    /** Output tax per head (UTGST shown with SGST as on the return). @return array{igst: string, cgst: string, sgst: string, cess: string} */
    public function outputByHead(string $from, string $to): array
    {
        $t = ['igst' => '0', 'cgst' => '0', 'sgst' => '0', 'cess' => '0'];
        foreach ($this->lines($from, $to) as $l) {
            $t['igst'] = Dec::add($t['igst'], self::signed($l, 'igst_amount'));
            $t['cgst'] = Dec::add($t['cgst'], self::signed($l, 'cgst_amount'));
            $t['sgst'] = Dec::add($t['sgst'], self::signed($l, 'sgst_amount'), self::signed($l, 'utgst_amount'));
            $t['cess'] = Dec::add($t['cess'], self::signed($l, 'cess_amount'));
        }

        return array_map(static fn ($v) => Dec::round($v), $t);
    }

    /**
     * Indicative set-off (Sec. 49/49A/49B, Rule 88A): IGST credit first (IGST,
     * then CGST, then SGST); then CGST credit (CGST, then IGST); then SGST
     * credit (SGST, then IGST). CGST and SGST credit never cross-utilise.
     *
     * @return array{output: array<string, string>, itc: array<string, string>, used: list<array{from: string, to: string, amount: string}>, cash: array<string, string>, carry: array<string, string>}
     */
    public function payable(string $from, string $to): array
    {
        return self::setOff($this->outputByHead($from, $to), $this->itcByHead($from, $to));
    }

    /**
     * @param array{igst: string, cgst: string, sgst: string, cess?: string} $output
     * @param array{igst: string, cgst: string, sgst: string}                $itc
     *
     * @return array{output: array<string, string>, itc: array<string, string>, used: list<array{from: string, to: string, amount: string}>, cash: array<string, string>, carry: array<string, string>}
     */
    public static function setOff(array $output, array $itc): array
    {
        $output += ['cess' => '0'];
        $liab   = ['igst' => Dec::max($output['igst'], '0'), 'cgst' => Dec::max($output['cgst'], '0'), 'sgst' => Dec::max($output['sgst'], '0')];
        $credit = ['igst' => Dec::round($itc['igst']), 'cgst' => Dec::round($itc['cgst']), 'sgst' => Dec::round($itc['sgst'])];
        $used   = [];
        foreach ([['igst', 'igst'], ['igst', 'cgst'], ['igst', 'sgst'], ['cgst', 'cgst'], ['cgst', 'igst'], ['sgst', 'sgst'], ['sgst', 'igst']] as [$c, $l]) {
            $amt = Dec::min($credit[$c], $liab[$l]);
            if (Dec::isPositive($amt)) {
                $credit[$c] = Dec::sub($credit[$c], $amt);
                $liab[$l]   = Dec::sub($liab[$l], $amt);
                $used[]     = ['from' => strtoupper($c), 'to' => strtoupper($l), 'amount' => $amt];
            }
        }

        return ['output' => $output, 'itc' => $itc, 'used' => $used, 'cash' => $liab + ['cess' => Dec::max($output['cess'], '0')], 'carry' => $credit];
    }

    // ---- GSTR-1 ------------------------------------------------------------

    /** @return list<array<string, mixed>> SheetIO sheet definitions */
    public function gstr1Sheets(string $from, string $to): array
    {
        $lines = $this->lines($from, $to);
        $taxed = static fn ($l) => ! in_array($l['tax_treatment'], ['exempt', 'nil_rated', 'non_gst'], true);
        $docs  = [];
        foreach ($lines as $l) {
            $docs[$l['doc_id']][] = $l;
        }
        $origB2cl = [];
        foreach ($this->db->table('billing_documents')->select('id, bill_to_gstin, place_of_supply_state_code, supplier_state_code, grand_total')->whereIn('doc_type', ['tax_invoice', 'cash_memo'])->get()->getResultArray() as $o) {
            $origB2cl[$o['id']] = empty($o['bill_to_gstin']) && $o['place_of_supply_state_code'] !== $o['supplier_state_code'] && Dec::cmp($o['grand_total'], self::B2CL_LIMIT) > 0;
        }

        $b2b = $b2cl = $b2cs = $cdnr = $cdnur = $exp = $nil = [];
        $hsn = ['B2B' => [], 'B2C' => []];
        foreach ($docs as $ls) {
            $h       = $ls[0];
            $isNote  = in_array($h['doc_type'], ['credit_note', 'debit_note'], true);
            $reg     = self::isRegistered($h);
            $inter   = $h['pos'] !== $h['supplier_state_code'];
            $byRate  = [];
            foreach ($ls as $l) {
                // HSN summary (all lines, signed), split B2B/B2C
                $hk = ($l['sac_code'] ?: 'NA') . '|' . Dec::rateLabel($l['gst_rate']);
                $bucket = $reg ? 'B2B' : 'B2C';
                $hsn[$bucket][$hk] ??= ['hsn' => $l['sac_code'] ?: '', 'description' => mb_substr((string) $l['item_name'], 0, 30), 'uqc' => 'OTH-OTHERS', 'qty' => '0', 'value' => '0',
                    'rate' => Dec::round($l['gst_rate'], 3), 'taxable' => '0', 'igst' => '0', 'cgst' => '0', 'sgst' => '0', 'cess' => '0'];
                $g = &$hsn[$bucket][$hk];
                $g['qty']     = Dec::add($g['qty'], (int) $l['sign'] < 0 ? Dec::neg($l['qty']) : $l['qty']);
                $g['taxable'] = Dec::add($g['taxable'], self::signed($l, 'taxable_value'));
                $g['igst']    = Dec::add($g['igst'], self::signed($l, 'igst_amount'));
                $g['cgst']    = Dec::add($g['cgst'], self::signed($l, 'cgst_amount'));
                $g['sgst']    = Dec::add($g['sgst'], self::signed($l, 'sgst_amount'), self::signed($l, 'utgst_amount'));
                $g['cess']    = Dec::add($g['cess'], self::signed($l, 'cess_amount'));
                $g['value']   = Dec::add($g['taxable'], $g['igst'], $g['cgst'], $g['sgst'], $g['cess']);
                unset($g);

                if (! $taxed($l)) {
                    $desc = ($inter ? 'Inter-State' : 'Intra-State') . ' supplies to ' . ($reg ? 'registered' : 'unregistered') . ' persons';
                    $nil[$desc] ??= ['description' => $desc, 'nil' => '0', 'exempt' => '0', 'non_gst' => '0'];
                    $col = ['nil_rated' => 'nil', 'exempt' => 'exempt', 'non_gst' => 'non_gst'][$l['tax_treatment']];
                    $nil[$desc][$col] = Dec::add($nil[$desc][$col], self::signed($l, 'taxable_value'));

                    continue;
                }
                $rk = Dec::rateLabel($l['gst_rate']);
                $byRate[$rk] ??= ['rate' => Dec::round($l['gst_rate'], 3), 'taxable' => '0', 'igst' => '0', 'cgst' => '0', 'sgst' => '0', 'cess' => '0', 'rc' => $l['tax_treatment'] === 'reverse_charge'];
                $byRate[$rk]['taxable'] = Dec::add($byRate[$rk]['taxable'], $l['taxable_value']);
                $byRate[$rk]['igst']    = Dec::add($byRate[$rk]['igst'], $l['igst_amount']);
                $byRate[$rk]['cgst']    = Dec::add($byRate[$rk]['cgst'], $l['cgst_amount']);
                $byRate[$rk]['sgst']    = Dec::add($byRate[$rk]['sgst'], $l['sgst_amount'], $l['utgst_amount']);
                $byRate[$rk]['cess']    = Dec::add($byRate[$rk]['cess'], $l['cess_amount']);
            }
            foreach ($byRate as $r) {
                $base = ['number' => $h['document_number'], 'date' => $h['issue_date'], 'value' => $h['grand_total'], 'pos' => $this->pos($h['pos']),
                    'rate' => $r['rate'], 'taxable' => $r['taxable'], 'igst' => $r['igst'], 'cgst' => $r['cgst'], 'sgst' => $r['sgst'], 'cess' => $r['cess']];
                if ($isNote) {
                    $note = $base + ['note_type' => $h['doc_type'] === 'credit_note' ? 'C' : 'D'];
                    if ($reg) {
                        $cdnr[] = $note + ['gstin' => $h['bill_to_gstin'], 'name' => $h['bill_to_name'], 'rc' => ! empty($h['reverse_charge']) || $r['rc'] ? 'Y' : 'N', 'supply_type' => 'Regular B2B'];
                    } elseif (self::isExport($h)) {
                        $cdnur[] = $note + ['ur_type' => $h['export_type'] === 'lut' ? 'EXPWOP' : 'EXPWP'];
                    } elseif (! empty($origB2cl[(int) $h['original_document_id']])) {
                        $cdnur[] = $note + ['ur_type' => 'B2CL'];
                    } else {
                        $this->addB2cs($b2cs, $h, $r, -1 * ($h['doc_type'] === 'credit_note' ? 1 : -1));
                    }

                    continue;
                }
                if (self::isExport($h)) {
                    $exp[] = $base + ['export_type' => $h['export_type'] === 'lut' ? 'WOPAY' : 'WPAY'];
                } elseif ($reg) {
                    $type = ! empty($h['is_sez_recipient']) || $h['customer_type'] === 'sez'
                        ? ($h['export_type'] === 'lut' ? 'SEZ supplies without payment' : 'SEZ supplies with payment') : 'Regular B2B';
                    $b2b[] = $base + ['gstin' => $h['bill_to_gstin'], 'name' => $h['bill_to_name'], 'rc' => ! empty($h['reverse_charge']) || $r['rc'] ? 'Y' : 'N', 'invoice_type' => $type];
                } elseif ($inter && Dec::cmp($h['grand_total'], self::B2CL_LIMIT) > 0) {
                    $b2cl[] = $base;
                } else {
                    $this->addB2cs($b2cs, $h, $r, 1);
                }
            }
        }

        $m  = ['type' => 'money'];
        $rt = ['key' => 'rate', 'label' => 'Rate', 'type' => 'percent'];
        $amounts = [['key' => 'taxable', 'label' => 'Taxable Value'] + $m, ['key' => 'cess', 'label' => 'Cess Amount'] + $m,
            ['key' => 'igst', 'label' => 'IGST (check)'] + $m, ['key' => 'cgst', 'label' => 'CGST (check)'] + $m, ['key' => 'sgst', 'label' => 'SGST/UTGST (check)'] + $m];
        $tot = ['taxable', 'cess', 'igst', 'cgst', 'sgst'];

        return [
            ['title' => 'b2b', 'rows' => $b2b, 'totals' => $tot, 'columns' => array_merge([['key' => 'gstin', 'label' => 'GSTIN/UIN of Recipient'], ['key' => 'name', 'label' => 'Receiver Name', 'width' => 30],
                ['key' => 'number', 'label' => 'Invoice Number'], ['key' => 'date', 'label' => 'Invoice date', 'type' => 'date'], ['key' => 'value', 'label' => 'Invoice Value'] + $m,
                ['key' => 'pos', 'label' => 'Place Of Supply'], ['key' => 'rc', 'label' => 'Reverse Charge'], ['key' => 'invoice_type', 'label' => 'Invoice Type'], $rt], $amounts),
                'notes' => ['Table 4: supplies to registered persons, one row per invoice per rate. Includes cash memos issued to GSTIN holders.']],
            ['title' => 'b2cl', 'rows' => $b2cl, 'totals' => $tot, 'columns' => array_merge([['key' => 'number', 'label' => 'Invoice Number'], ['key' => 'date', 'label' => 'Invoice date', 'type' => 'date'],
                ['key' => 'value', 'label' => 'Invoice Value'] + $m, ['key' => 'pos', 'label' => 'Place Of Supply'], $rt], $amounts),
                'notes' => ['Table 5: inter-state supplies to unregistered persons with invoice value above ₹' . number_format((float) self::B2CL_LIMIT) . '.']],
            ['title' => 'b2cs', 'rows' => array_values($b2cs), 'totals' => $tot, 'columns' => array_merge([['key' => 'type', 'label' => 'Type'], ['key' => 'pos', 'label' => 'Place Of Supply'], $rt], $amounts),
                'notes' => ['Table 7: other supplies to unregistered persons, net of credit/debit notes, by place of supply and rate.']],
            ['title' => 'cdnr', 'rows' => $cdnr, 'totals' => $tot, 'columns' => array_merge([['key' => 'gstin', 'label' => 'GSTIN/UIN of Recipient'], ['key' => 'name', 'label' => 'Receiver Name', 'width' => 30],
                ['key' => 'number', 'label' => 'Note Number'], ['key' => 'date', 'label' => 'Note Date', 'type' => 'date'], ['key' => 'note_type', 'label' => 'Note Type'], ['key' => 'pos', 'label' => 'Place Of Supply'],
                ['key' => 'rc', 'label' => 'Reverse Charge'], ['key' => 'supply_type', 'label' => 'Note Supply Type'], ['key' => 'value', 'label' => 'Note Value'] + $m, $rt], $amounts)],
            ['title' => 'cdnur', 'rows' => $cdnur, 'totals' => $tot, 'columns' => array_merge([['key' => 'ur_type', 'label' => 'UR Type'], ['key' => 'number', 'label' => 'Note Number'],
                ['key' => 'date', 'label' => 'Note Date', 'type' => 'date'], ['key' => 'note_type', 'label' => 'Note Type'], ['key' => 'pos', 'label' => 'Place Of Supply'], ['key' => 'value', 'label' => 'Note Value'] + $m, $rt], $amounts)],
            ['title' => 'exp', 'rows' => $exp, 'totals' => $tot, 'columns' => array_merge([['key' => 'export_type', 'label' => 'Export Type'], ['key' => 'number', 'label' => 'Invoice Number'],
                ['key' => 'date', 'label' => 'Invoice date', 'type' => 'date'], ['key' => 'value', 'label' => 'Invoice Value'] + $m, $rt], $amounts),
                'notes' => ['Exports of services: port code and shipping bill are not applicable.']],
            ['title' => 'exemp', 'rows' => array_values($nil), 'totals' => ['nil', 'exempt', 'non_gst'], 'columns' => [['key' => 'description', 'label' => 'Description', 'width' => 45],
                ['key' => 'nil', 'label' => 'Nil Rated Supplies'] + $m, ['key' => 'exempt', 'label' => 'Exempted (other than nil rated/non GST supply)'] + $m, ['key' => 'non_gst', 'label' => 'Non-GST supplies'] + $m]],
            ['title' => 'hsn(b2b)', 'rows' => array_values($hsn['B2B']), 'totals' => ['value', 'taxable', 'igst', 'cgst', 'sgst', 'cess'], 'columns' => $this->hsnCols()],
            ['title' => 'hsn(b2c)', 'rows' => array_values($hsn['B2C']), 'totals' => ['value', 'taxable', 'igst', 'cgst', 'sgst', 'cess'], 'columns' => $this->hsnCols()],
            ['title' => 'docs', 'rows' => $this->docsIssued($from, $to), 'totals' => ['total', 'cancelled'], 'columns' => [['key' => 'nature', 'label' => 'Nature of Document', 'width' => 36],
                ['key' => 'from', 'label' => 'Sr. No. From'], ['key' => 'to', 'label' => 'Sr. No. To'], ['key' => 'total', 'label' => 'Total Number', 'type' => 'int'], ['key' => 'cancelled', 'label' => 'Cancelled', 'type' => 'int']]],
            ['title' => 'at (review)', 'rows' => $this->advances($to), 'totals' => ['amount', 'taxable', 'tax'], 'columns' => [['key' => 'receipt_number', 'label' => 'Receipt'],
                ['key' => 'receipt_date', 'label' => 'Date', 'type' => 'date'], ['key' => 'customer', 'label' => 'Customer', 'width' => 28], ['key' => 'pos', 'label' => 'Place Of Supply'],
                ['key' => 'amount', 'label' => 'Unadjusted advance'] + $m, ['key' => 'rate', 'label' => 'Assumed rate', 'type' => 'percent'], ['key' => 'taxable', 'label' => 'Taxable (back-calculated)'] + $m, ['key' => 'tax', 'label' => 'Tax on advance'] + $m],
                'notes' => ['GST is payable on advances received for services (Table 11A). Rate assumed 18% where not known — the accountant must confirm rate and place of supply before reporting.']],
        ];
    }

    /** @param array<string, array<string, mixed>> $b2cs */
    private function addB2cs(array &$b2cs, array $h, array $r, int $sign): void
    {
        $k = $h['pos'] . '|' . Dec::rateLabel($r['rate']);
        $b2cs[$k] ??= ['type' => 'OE', 'pos' => $this->pos($h['pos']), 'rate' => $r['rate'], 'taxable' => '0', 'igst' => '0', 'cgst' => '0', 'sgst' => '0', 'cess' => '0'];
        foreach (['taxable', 'igst', 'cgst', 'sgst', 'cess'] as $f) {
            $b2cs[$k][$f] = Dec::add($b2cs[$k][$f], $sign < 0 ? Dec::neg($r[$f]) : $r[$f]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function hsnCols(): array
    {
        $m = ['type' => 'money'];

        return [['key' => 'hsn', 'label' => 'HSN'], ['key' => 'description', 'label' => 'Description', 'width' => 30], ['key' => 'uqc', 'label' => 'UQC'],
            ['key' => 'qty', 'label' => 'Total Quantity', 'type' => 'number'], ['key' => 'value', 'label' => 'Total Value'] + $m, ['key' => 'rate', 'label' => 'Rate', 'type' => 'percent'],
            ['key' => 'taxable', 'label' => 'Taxable Value'] + $m, ['key' => 'igst', 'label' => 'Integrated Tax Amount'] + $m, ['key' => 'cgst', 'label' => 'Central Tax Amount'] + $m,
            ['key' => 'sgst', 'label' => 'State/UT Tax Amount'] + $m, ['key' => 'cess', 'label' => 'Cess Amount'] + $m];
    }

    /** Table 13: documents issued per series (finalized and cancelled numbers). @return list<array<string, mixed>> */
    public function docsIssued(string $from, string $to): array
    {
        $nature = ['tax_invoice' => 'Invoices for outward supply', 'cash_memo' => 'Invoices for outward supply', 'bill_of_supply' => 'Invoices for outward supply',
            'credit_note' => 'Credit Note', 'debit_note' => 'Debit Note'];
        $out = [];
        $rows = $this->db->query(
            "SELECT doc_type, sequence_id, MIN(sequence_number) mn, MAX(sequence_number) mx, COUNT(*) total, SUM(status = 'cancelled') cancelled
               FROM billing_documents WHERE document_number IS NOT NULL AND status IN ('finalized','cancelled') AND issue_date BETWEEN ? AND ?
              GROUP BY doc_type, sequence_id ORDER BY doc_type, sequence_id",
            [$from, $to]
        )->getResultArray();
        foreach ($rows as $r) {
            if (! isset($nature[$r['doc_type']])) {
                continue;
            }
            $first = $this->db->table('billing_documents')->select('document_number')->where(['sequence_id' => $r['sequence_id'], 'sequence_number' => $r['mn']])->get()->getRow()->document_number;
            $last  = $this->db->table('billing_documents')->select('document_number')->where(['sequence_id' => $r['sequence_id'], 'sequence_number' => $r['mx']])->get()->getRow()->document_number;
            $out[] = ['nature' => $nature[$r['doc_type']], 'from' => $first, 'to' => $last, 'total' => (int) $r['total'], 'cancelled' => (int) $r['cancelled']];
        }
        foreach (['receipt' => 'Receipt voucher', 'refund' => 'Refund voucher'] as $type => $label) {
            $r = $this->db->query("SELECT MIN(receipt_number) mn, MAX(receipt_number) mx, COUNT(*) total, SUM(status = 'void') cancelled FROM payment_receipts WHERE receipt_type = ? AND receipt_date BETWEEN ? AND ?", [$type, $from, $to])->getRowArray();
            if ((int) $r['total'] > 0) {
                $out[] = ['nature' => $label, 'from' => $r['mn'], 'to' => $r['mx'], 'total' => (int) $r['total'], 'cancelled' => (int) $r['cancelled']];
            }
        }

        return $out;
    }

    /** Unadjusted advances at period end, tax back-calculated at an assumed 18%. @return list<array<string, mixed>> */
    public function advances(string $asOn): array
    {
        $company = $this->company();
        $rows = $this->db->table('payment_receipts r')->select('r.receipt_number, r.receipt_date, r.unapplied_amount AS amount, c.name AS customer, c.state_code')
            ->join('contacts c', 'c.id = r.contact_id')->where(['r.status' => 'active', 'r.receipt_type' => 'receipt'])->where('r.unapplied_amount >', 0)
            ->where('r.receipt_date <=', $asOn)->orderBy('r.receipt_date')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['rate']    = '18.000';
            $r['taxable'] = Dec::div(Dec::mul($r['amount'], '100', 6), '118', 2);
            $r['tax']     = Dec::sub($r['amount'], $r['taxable']);
            $r['pos']     = $this->pos($r['state_code'] ?: ($company['state_code'] ?? null));
        }

        return $rows;
    }

    // ---- GSTR-3B -------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function gstr3bSheets(string $from, string $to): array
    {
        $lines   = $this->lines($from, $to);
        $t31     = ['a' => [], 'b' => [], 'c' => [], 'e' => []];
        $blank   = ['taxable' => '0', 'igst' => '0', 'cgst' => '0', 'sgst' => '0', 'cess' => '0'];
        foreach (array_keys($t31) as $k) {
            $t31[$k] = $blank;
        }
        $t32 = [];
        foreach ($lines as $l) {
            $row = match ($l['tax_treatment']) {
                'zero_rated_igst', 'zero_rated_lut' => 'b',
                'nil_rated', 'exempt' => 'c',
                'non_gst' => 'e',
                'reverse_charge' => null, // tax payable by the recipient; nothing in 3.1 for the supplier
                default => 'a',
            };
            if ($row === null) {
                continue;
            }
            $t31[$row]['taxable'] = Dec::add($t31[$row]['taxable'], self::signed($l, 'taxable_value'));
            $t31[$row]['igst']    = Dec::add($t31[$row]['igst'], self::signed($l, 'igst_amount'));
            $t31[$row]['cgst']    = Dec::add($t31[$row]['cgst'], self::signed($l, 'cgst_amount'));
            $t31[$row]['sgst']    = Dec::add($t31[$row]['sgst'], self::signed($l, 'sgst_amount'), self::signed($l, 'utgst_amount'));
            $t31[$row]['cess']    = Dec::add($t31[$row]['cess'], self::signed($l, 'cess_amount'));
            if ($row === 'a' && ! self::isRegistered($l) && $l['pos'] !== $l['supplier_state_code']) {
                $p = $this->pos($l['pos']);
                $t32[$p] ??= ['pos' => $p, 'taxable' => '0', 'igst' => '0'];
                $t32[$p]['taxable'] = Dec::add($t32[$p]['taxable'], self::signed($l, 'taxable_value'));
                $t32[$p]['igst']    = Dec::add($t32[$p]['igst'], self::signed($l, 'igst_amount'));
            }
        }
        $sec31 = [
            ['row' => '3.1(a) Outward taxable supplies (other than zero rated, nil rated and exempted)'] + $t31['a'],
            ['row' => '3.1(b) Outward taxable supplies (zero rated)'] + $t31['b'],
            ['row' => '3.1(c) Other outward supplies (nil rated, exempted)'] + $t31['c'],
            ['row' => '3.1(d) Inward supplies (liable to reverse charge)'] + $blank,
            ['row' => '3.1(e) Non-GST outward supplies'] + $t31['e'],
        ];

        // Table 4: ITC on approved expenses with a vendor GSTIN; ineligible part reversed in 4(B)(1).
        $avail = ['igst' => '0', 'cgst' => '0', 'sgst' => '0'];
        $inel  = ['igst' => '0', 'cgst' => '0', 'sgst' => '0'];
        foreach ($this->itcRegister($from, $to) as $e) {
            if (empty($e['vendor_gstin'])) {
                continue;
            }
            $heads = ['igst' => $e['igst_amount'], 'cgst' => $e['cgst_amount'], 'sgst' => Dec::add($e['sgst_amount'], $e['utgst_amount'])];
            foreach ($heads as $k => $v) {
                $avail[$k] = Dec::add($avail[$k], $v);
                if (Dec::isPositive($e['tax_total'])) {
                    $inel[$k] = Dec::add($inel[$k], Dec::div(Dec::mul($e['itc_ineligible_amount'], $v, 6), $e['tax_total'], 2));
                }
            }
        }
        $net  = ['igst' => Dec::sub($avail['igst'], $inel['igst']), 'cgst' => Dec::sub($avail['cgst'], $inel['cgst']), 'sgst' => Dec::sub($avail['sgst'], $inel['sgst'])];
        $sec4 = [
            ['row' => '4(A)(5) All other ITC'] + $avail + ['cess' => '0'],
            ['row' => '4(B)(1) ITC reversed — Rules 38/42/43 and Section 17(5)'] + $inel + ['cess' => '0'],
            ['row' => '4(C) Net ITC available (A) − (B)'] + $net + ['cess' => '0'],
        ];
        $p    = $this->payable($from, $to);
        $sec6 = [];
        foreach (['igst' => 'Integrated tax', 'cgst' => 'Central tax', 'sgst' => 'State/UT tax', 'cess' => 'Cess'] as $k => $label) {
            $paidItc = '0';
            foreach ($p['used'] as $u) {
                if (strtolower($u['to']) === $k) {
                    $paidItc = Dec::add($paidItc, $u['amount']);
                }
            }
            $sec6[] = ['head' => $label, 'payable' => Dec::max($p['output'][$k], '0'), 'itc' => $paidItc, 'cash' => $p['cash'][$k] ?? '0', 'carry' => $p['carry'][$k] ?? '0'];
        }
        $m = ['type' => 'money'];
        $taxCols = [['key' => 'taxable', 'label' => 'Total taxable value'] + $m, ['key' => 'igst', 'label' => 'Integrated tax'] + $m, ['key' => 'cgst', 'label' => 'Central tax'] + $m,
            ['key' => 'sgst', 'label' => 'State/UT tax'] + $m, ['key' => 'cess', 'label' => 'Cess'] + $m];

        return [
            ['title' => '3.1 Outward supplies', 'rows' => $sec31, 'columns' => array_merge([['key' => 'row', 'label' => 'Nature of supplies', 'width' => 70]], $taxCols),
                'notes' => ['Net of credit and debit notes. 3.1(d) is not tracked in DGR Office — enter reverse-charge purchases manually.']],
            ['title' => '3.2 Inter-state B2C', 'rows' => array_values($t32), 'totals' => ['taxable', 'igst'], 'columns' => [['key' => 'pos', 'label' => 'Place of supply', 'width' => 30],
                ['key' => 'taxable', 'label' => 'Total taxable value'] + $m, ['key' => 'igst', 'label' => 'Integrated tax'] + $m]],
            ['title' => '4 Eligible ITC', 'rows' => $sec4, 'columns' => array_merge([['key' => 'row', 'label' => 'Details', 'width' => 60]], array_slice($taxCols, 1)),
                'notes' => ['From approved expenses with a vendor GSTIN, using the accountant-reviewed ITC rule on each expense. Match against GSTR-2B before filing.']],
            ['title' => '6.1 Payment (indicative)', 'rows' => $sec6, 'columns' => [['key' => 'head', 'label' => 'Description', 'width' => 20], ['key' => 'payable', 'label' => 'Tax payable'] + $m,
                ['key' => 'itc', 'label' => 'Paid through ITC'] + $m, ['key' => 'cash', 'label' => 'Paid in cash'] + $m, ['key' => 'carry', 'label' => 'ITC carried forward'] + $m],
                'notes' => ['Set-off order per Sections 49/49A/49B and Rule 88A: IGST credit → IGST, CGST, SGST; then CGST credit → CGST, IGST; then SGST credit → SGST, IGST.', 'Indicative only — the GST portal computes the final set-off; electronic cash/credit ledger balances are not considered.']],
        ];
    }
}
