<?php

namespace Tests\Unit;

use App\Libraries\BankStatementParser as P;
use CodeIgniter\Test\CIUnitTestCase;

final class BankStatementParserTest extends CIUnitTestCase
{
    public function testDetectsHeaderBelowPreambleAndParsesDebitCreditColumns(): void
    {
        $rows = [
            ['DEMO BANK LTD', '', '', '', '', '', ''],
            ['Account No: XXXXXXXX9012', '', '', '', '', '', ''],
            ['Statement From : 01/10/2026 To : 15/10/2026', '', '', '', '', '', ''],
            ['Date', 'Narration', 'Chq./Ref.No.', 'Value Dt', 'Withdrawal Amt.', 'Deposit Amt.', 'Closing Balance'],
            ['01/10/26', 'UPI/CR/628812345678/ACME HEALTH', 'UPI-628812345678', '01/10/26', '', '11,800.00', '61,800.00'],
            ['03/10/26', 'NEFT DR-HDFC0000001-CLOUD HOSTING', 'N276261234', '03/10/26', '2,360.00', '', '59,440.00'],
            ['05-Oct-2026', 'CASH DEPOSIT BHIWADI', '', '05-Oct-2026', '', '5,000.00', '64,440.00'],
            ['', '', '', '', '', '', ''],
            ['*** End of statement ***', '', '', '', '', '', ''],
        ];
        $h = P::detectHeader($rows);
        $this->assertSame(3, $h);
        $map = P::guessMapping($rows[$h]);
        $this->assertSame(0, $map['txn_date']);
        $this->assertSame(3, $map['value_date']);
        $this->assertSame(1, $map['description']);
        $this->assertSame(2, $map['reference']);
        $this->assertSame(4, $map['debit']);
        $this->assertSame(5, $map['credit']);
        $this->assertSame(6, $map['balance']);

        $out = P::parse($rows, $h, $map);
        $this->assertSame([], $out['errors']);
        $this->assertCount(3, $out['lines']);
        $this->assertSame(['2026-10-01', '0.00', '11800.00', '61800.00'], [$out['lines'][0]['txn_date'], $out['lines'][0]['debit'], $out['lines'][0]['credit'], $out['lines'][0]['balance']]);
        $this->assertSame(['2026-10-03', '2360.00', '0.00'], [$out['lines'][1]['txn_date'], $out['lines'][1]['debit'], $out['lines'][1]['credit']]);
        $this->assertSame('2026-10-05', $out['lines'][2]['txn_date']);
    }

    public function testSingleAmountColumnWithDrCrIndicator(): void
    {
        $rows = [
            ['Txn Date', 'Description', 'Amount', 'Dr/Cr', 'Balance'],
            ['2026-10-01', 'IMPS IN', '1,000.00', 'CR', '1000.00 Cr'],
            ['2026-10-02', 'CHARGES', '17.70', 'DR', '982.30 Cr'],
        ];
        $out = P::parse($rows, P::detectHeader($rows), P::guessMapping($rows[0]));
        $this->assertSame('1000.00', $out['lines'][0]['credit']);
        $this->assertSame('17.70', $out['lines'][1]['debit']);
        $this->assertSame('982.30', $out['lines'][1]['balance']);
    }

    public function testAmountsAndDates(): void
    {
        $this->assertSame('123456.70', P::amount('1,23,456.70'));
        $this->assertSame('-250.00', P::amount('(250.00)'));
        $this->assertSame('-99.00', P::amount('99.00 Dr'));
        $this->assertNull(P::amount('abc'));
        $this->assertSame('2026-04-05', P::date('05/04/2026'));
        $this->assertSame('2026-04-05', P::date('5-Apr-26'));
        $this->assertNull(P::date('31/02/2026'));
    }
}
