<?php

namespace Tests\Unit;

use App\Services\Reports\GstReturnService;
use CodeIgniter\Test\CIUnitTestCase;

final class GstSetOffTest extends CIUnitTestCase
{
    public function testIgstCreditFirstThenCgstAndSgstNeverCrossUtilised(): void
    {
        $r = GstReturnService::setOff(['igst' => '1000.00', 'cgst' => '500.00', 'sgst' => '500.00'], ['igst' => '1200.00', 'cgst' => '100.00', 'sgst' => '700.00']);
        $this->assertSame([
            ['from' => 'IGST', 'to' => 'IGST', 'amount' => '1000.00'],
            ['from' => 'IGST', 'to' => 'CGST', 'amount' => '200.00'],
            ['from' => 'CGST', 'to' => 'CGST', 'amount' => '100.00'],
            ['from' => 'SGST', 'to' => 'SGST', 'amount' => '500.00'],
        ], $r['used']);
        $this->assertSame('0.00', $r['cash']['igst']);
        $this->assertSame('200.00', $r['cash']['cgst'], 'SGST credit can never pay CGST');
        $this->assertSame('0.00', $r['cash']['sgst']);
        $this->assertSame('200.00', $r['carry']['sgst']);
    }

    public function testCgstCreditPaysIgstWhenCgstIsCovered(): void
    {
        $r = GstReturnService::setOff(['igst' => '900.00', 'cgst' => '0.00', 'sgst' => '0.00'], ['igst' => '0.00', 'cgst' => '400.00', 'sgst' => '300.00']);
        $this->assertSame('200.00', $r['cash']['igst']);
        $this->assertSame('0.00', $r['carry']['cgst']);
        $this->assertSame('0.00', $r['carry']['sgst']);
    }

    public function testNegativeOutputFromCreditNotesIsNotARefund(): void
    {
        $r = GstReturnService::setOff(['igst' => '-50.00', 'cgst' => '10.00', 'sgst' => '10.00'], ['igst' => '0', 'cgst' => '0', 'sgst' => '0']);
        $this->assertSame('0.00', $r['cash']['igst']);
        $this->assertSame('10.00', $r['cash']['cgst']);
    }
}
