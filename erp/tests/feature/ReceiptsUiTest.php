<?php

namespace Tests\Feature;

use Smalot\PdfParser\Parser;
use Tests\Support\DgrTestCase;

/** Receipt vouchers, advances, refunds and cheque handling over HTTP. */
final class ReceiptsUiTest extends DgrTestCase
{
    private \CodeIgniter\Shield\Entities\User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCompany('08');
        $this->owner = $this->makeUser('super_admin', 'Test Owner');
        $this->loginAs($this->owner);
    }

    private function receiptIdFrom(\CodeIgniter\Test\TestResponse $res): int
    {
        $res->assertRedirect();
        $this->assertMatchesRegularExpression('#finance/receipts/(\d+)$#', (string) $res->getRedirectUrl(), 'Expected redirect to the receipt; got ' . $res->getRedirectUrl() . ' / ' . json_encode(session('error')));
        preg_match('#finance/receipts/(\d+)$#', (string) $res->getRedirectUrl(), $m);

        return (int) $m[1];
    }

    private function doc(int $id): array
    {
        return db_connect()->table('billing_documents')->where('id', $id)->get()->getRowArray();
    }

    public function testPaymentWithOverpaymentAppliedThenAdvanceUsedAndRefunded(): void
    {
        $bank = $this->makeBankAccount();
        $c    = $this->makeContact(['name' => 'Advance Client']);
        [$inv1] = $this->issue('tax_invoice', $c, [$this->line('10000')], 'cgst_sgst', '08');
        [$inv2] = $this->issue('tax_invoice', $c, [$this->line('1000')], 'cgst_sgst', '08');

        $this->get('finance/receipts/new?document=' . $inv1)->assertSee('Record payment');
        $open = json_decode($this->get('finance/receipts/open-documents?contact=' . $c)->getJSON(), true);
        $this->assertCount(2, $open['documents']);

        // ₹15,000 received by UPI: ₹11,800 settles invoice 1, ₹3,200 stays as advance
        $rid = $this->receiptIdFrom($this->postForm('finance/receipts', [
            'contact_id' => $c, 'amount' => '15,000', 'receipt_date' => date('Y-m-d'), 'mode' => 'upi',
            'bank_cash_account_id' => $bank, 'reference_no' => 'UTR123456', 'alloc' => [$inv1 => '11800'],
        ]));
        $r = db_connect()->table('payment_receipts')->where('id', $rid)->get()->getRowArray();
        $this->assertSame('15000.00', $r['amount']);
        $this->assertSame('11800.00', $r['allocated_amount']);
        $this->assertSame('3200.00', $r['unapplied_amount']);
        $this->assertSame('paid', $this->doc($inv1)['payment_status']);
        $this->assertSame('0.00', $this->doc($inv1)['balance_due']);
        $this->assertSame('-3200.00', $this->balance('customer_advance', $c), 'Advance is a liability (credit balance)');

        $this->get('finance/receipts/' . $rid)->assertSee('Apply advance');
        $text = preg_replace('/\s+/u', ' ', (new Parser())->parseContent((string) $this->get('finance/receipts/' . $rid . '/pdf')->response()->getBody())->getText());
        $this->assertStringContainsString('RECEIPT VOUCHER', $text);
        $this->assertStringContainsString($r['receipt_number'], $text);
        $this->assertStringContainsString('15,000.00', $text);
        $this->assertStringContainsString($this->doc($inv1)['document_number'], $text);
        $this->assertStringContainsString('3,200.00', $text);
        $this->assertStringContainsString('Fifteen Thousand', $text);
        $this->assertStringNotContainsString('123456789012', $text, 'Vouchers never print account numbers');
        $this->assertStringNotContainsString('CIF998877', $text);

        // apply ₹1,180 of the advance to invoice 2
        $this->postForm('finance/receipts/' . $rid . '/apply', ['alloc' => [$inv2 => '1180']])->assertRedirect();
        $this->assertSame('paid', $this->doc($inv2)['payment_status']);
        $this->assertSame('2020.00', db_connect()->table('payment_receipts')->where('id', $rid)->get()->getRow()->unapplied_amount);

        // refund the remaining ₹2,020
        $this->get('finance/refunds/new?contact=' . $c . '&receipt=' . $rid)->assertSee('Refund to');
        $fid = $this->receiptIdFrom($this->postForm('finance/refunds', [
            'contact_id' => $c, 'from_receipt_id' => $rid, 'amount' => '2020', 'refund_date' => date('Y-m-d'), 'mode' => 'upi', 'bank_cash_account_id' => $bank,
        ]));
        $f = db_connect()->table('payment_receipts')->where('id', $fid)->get()->getRowArray();
        $this->assertSame('refund', $f['receipt_type']);
        $this->assertStringStartsWith('RF-', $f['receipt_number']);
        $this->assertSame('0.00', db_connect()->table('payment_receipts')->where('id', $rid)->get()->getRow()->unapplied_amount);
        // bank: +15,000 − 2,020
        $this->assertSame('12980.00', $this->bankBalance($bank));

        // the original receipt cannot be voided while its refund is active
        $this->postForm('finance/receipts/' . $rid . '/void', ['reason' => 'Entered twice by mistake'])->assertRedirect();
        $this->assertSame('active', db_connect()->table('payment_receipts')->where('id', $rid)->get()->getRow()->status);

        // lists and export
        $this->get('finance/receipts')->assertSee($r['receipt_number']);
        $x = $this->get('finance/receipts/export?format=csv');
        $this->assertStringContainsString($f['receipt_number'], (string) $x->response()->getBody());
    }

    public function testVoidReopensInvoiceAndCashMustGoToCashAccount(): void
    {
        $bank = $this->makeBankAccount();
        $c    = $this->makeContact();
        [$inv] = $this->issue('tax_invoice', $c, [$this->line('1000')], 'cgst_sgst', '08');

        // cash into a bank account is refused (deposit is a separate transfer)
        $res = $this->postForm('finance/receipts', ['contact_id' => $c, 'amount' => '1180', 'receipt_date' => date('Y-m-d'), 'mode' => 'cash', 'bank_cash_account_id' => $bank, 'alloc' => [$inv => '1180']]);
        $res->assertRedirect();
        $this->assertSame(0, db_connect()->table('payment_receipts')->where('contact_id', $c)->countAllResults());

        $rid = $this->receiptIdFrom($this->postForm('finance/receipts', ['contact_id' => $c, 'amount' => '1180', 'receipt_date' => date('Y-m-d'), 'mode' => 'cash', 'bank_cash_account_id' => $this->cashAccountId(), 'alloc' => [$inv => '1180']]));
        $this->assertSame('paid', $this->doc($inv)['payment_status']);

        // duplicate detection on the same day/amount/mode
        $dupe = $this->postForm('finance/receipts', ['contact_id' => $c, 'amount' => '1180', 'receipt_date' => date('Y-m-d'), 'mode' => 'cash', 'bank_cash_account_id' => $this->cashAccountId()]);
        $dupe->assertRedirect();
        $this->assertSame(1, db_connect()->table('payment_receipts')->where('contact_id', $c)->countAllResults());

        $this->postForm('finance/receipts/' . $rid . '/void', ['reason' => 'Customer payment reversed'])->assertRedirectTo(site_url('finance/receipts/' . $rid));
        $this->assertSame('void', db_connect()->table('payment_receipts')->where('id', $rid)->get()->getRow()->status);
        $this->assertSame('unpaid', $this->doc($inv)['payment_status']);
        $this->assertSame('1180.00', $this->doc($inv)['balance_due']);
        $this->assertSame('0.00', $this->bankBalance($this->cashAccountId()));
    }

    public function testBouncedChequeVoidsReceipt(): void
    {
        $bank = $this->makeBankAccount();
        $c    = $this->makeContact();
        [$inv] = $this->issue('tax_invoice', $c, [$this->line('5000')], 'cgst_sgst', '08');
        $rid = $this->receiptIdFrom($this->postForm('finance/receipts', ['contact_id' => $c, 'amount' => '5900', 'receipt_date' => date('Y-m-d'), 'mode' => 'cheque', 'reference_no' => '000123', 'bank_cash_account_id' => $bank, 'alloc' => [$inv => '5900']]));
        $this->assertSame('pending', db_connect()->table('payment_receipts')->where('id', $rid)->get()->getRow()->cheque_status);

        $this->postForm('finance/receipts/' . $rid . '/cheque', ['status' => 'bounced', 'note' => 'Insufficient funds'])->assertRedirect();
        $row = db_connect()->table('payment_receipts')->where('id', $rid)->get()->getRow();
        $this->assertSame('void', $row->status);
        $this->assertSame('bounced', $row->cheque_status);
        $this->assertSame('5900.00', $this->doc($inv)['balance_due']);
    }

    public function testFinanceScreensNeedFinancePermissions(): void
    {
        $op = $this->makeUser('billing_operator');
        $this->loginAs($op);
        $this->assertSame(403, $this->statusOf(fn () => $this->get('finance/receipts')));
        $this->assertSame(403, $this->statusOf(fn () => $this->get('finance/receipts/new')));
        $this->assertSame(403, $this->statusOf(fn () => $this->postForm('finance/receipts', ['amount' => '1'])));

        $acct = $this->makeUser('accountant');
        $this->loginAs($acct);
        $this->get('finance/receipts')->assertSee('Payments');
    }
}
