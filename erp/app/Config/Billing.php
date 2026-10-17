<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Billing document types, permission namespaces and default series.
 * Numbering (series) and tax treatment are independent concepts:
 * a cash memo has its own series but the same GST engine as a tax invoice.
 */
class Billing extends BaseConfig
{
    /**
     * perm:     permission namespace (billing.<perm>.*)
     * posts:    finalization posts to the ledger (sale / note)
     * sign:     +1 increases receivable, -1 decreases (credit note)
     * series:   sequence doc_type key
     * taxable:  can carry GST
     *
     * @var array<string, array{label: string, perm: string, posts: bool, sign: int, series: string, taxable: bool, statutory: bool}>
     */
    public array $docTypes = [
        'tax_invoice'    => ['label' => 'Tax Invoice', 'perm' => 'invoice', 'posts' => true, 'sign' => 1, 'series' => 'tax_invoice', 'taxable' => true, 'statutory' => true],
        'bill_of_supply' => ['label' => 'Bill of Supply', 'perm' => 'invoice', 'posts' => true, 'sign' => 1, 'series' => 'bill_of_supply', 'taxable' => false, 'statutory' => true],
        'cash_memo'      => ['label' => 'Cash Memo', 'perm' => 'cash_memo', 'posts' => true, 'sign' => 1, 'series' => 'cash_memo', 'taxable' => true, 'statutory' => true],
        'credit_note'    => ['label' => 'Credit Note', 'perm' => 'note', 'posts' => true, 'sign' => -1, 'series' => 'credit_note', 'taxable' => true, 'statutory' => true],
        'debit_note'     => ['label' => 'Debit Note', 'perm' => 'note', 'posts' => true, 'sign' => 1, 'series' => 'debit_note', 'taxable' => true, 'statutory' => true],
        'estimate'       => ['label' => 'Estimate', 'perm' => 'estimate', 'posts' => false, 'sign' => 0, 'series' => 'estimate', 'taxable' => true, 'statutory' => false],
        'quotation'      => ['label' => 'Quotation', 'perm' => 'estimate', 'posts' => false, 'sign' => 0, 'series' => 'quotation', 'taxable' => true, 'statutory' => false],
        'proforma'       => ['label' => 'Proforma Invoice', 'perm' => 'estimate', 'posts' => false, 'sign' => 0, 'series' => 'proforma', 'taxable' => true, 'statutory' => false],
    ];

    /**
     * Default series created for each financial year. {fy} → e.g. 26-27.
     * Statutory series are validated against GST Rule 46(b): ≤16 chars,
     * only letters, digits, '-' and '/', unique within the FY.
     *
     * @var array<string, array{code: string, name: string, prefix: string, padding: int, statutory: bool}>
     */
    public array $defaultSeries = [
        'tax_invoice'    => ['code' => 'GST', 'name' => 'GST Tax Invoice', 'prefix' => 'GST-{fy}-', 'padding' => 3, 'statutory' => true],
        'bill_of_supply' => ['code' => 'BOS', 'name' => 'Bill of Supply', 'prefix' => 'BOS-{fy}-', 'padding' => 4, 'statutory' => true],
        'cash_memo'      => ['code' => 'CM', 'name' => 'Cash Memo', 'prefix' => 'CM-{fy}-', 'padding' => 4, 'statutory' => true],
        'credit_note'    => ['code' => 'CN', 'name' => 'Credit Note', 'prefix' => 'CN-{fy}-', 'padding' => 4, 'statutory' => true],
        'debit_note'     => ['code' => 'DN', 'name' => 'Debit Note', 'prefix' => 'DN-{fy}-', 'padding' => 4, 'statutory' => true],
        'receipt'        => ['code' => 'RC', 'name' => 'Receipt Voucher', 'prefix' => 'RC-{fy}-', 'padding' => 4, 'statutory' => true],
        'refund'         => ['code' => 'RF', 'name' => 'Refund Voucher', 'prefix' => 'RF-{fy}-', 'padding' => 4, 'statutory' => true],
        'estimate'       => ['code' => 'EST', 'name' => 'Estimate', 'prefix' => 'EST-{fy}-', 'padding' => 4, 'statutory' => false],
        'quotation'      => ['code' => 'QT', 'name' => 'Quotation', 'prefix' => 'QT-{fy}-', 'padding' => 4, 'statutory' => false],
        'proforma'       => ['code' => 'PI', 'name' => 'Proforma Invoice', 'prefix' => 'PI-{fy}-', 'padding' => 4, 'statutory' => false],
        'transfer'       => ['code' => 'TR', 'name' => 'Fund Transfer', 'prefix' => 'TR-{fy}-', 'padding' => 4, 'statutory' => false],
        'expense'        => ['code' => 'EXP', 'name' => 'Expense', 'prefix' => 'EXP-{fy}-', 'padding' => 4, 'statutory' => false],
        'journal'        => ['code' => 'JV', 'name' => 'Journal Voucher', 'prefix' => 'JV-{fy}-', 'padding' => 5, 'statutory' => false],
    ];

    /** Payment modes shown on receipts. */
    public array $paymentModes = [
        'cash' => 'Cash', 'upi' => 'UPI', 'bank_transfer' => 'Bank Transfer', 'neft' => 'NEFT', 'rtgs' => 'RTGS',
        'imps' => 'IMPS', 'cheque' => 'Cheque', 'card' => 'Card', 'other' => 'Other',
    ];

    /** Max upload for statement imports etc. lives in FileStorage profiles. */
    public int $draftAutosaveSeconds = 20;
}
