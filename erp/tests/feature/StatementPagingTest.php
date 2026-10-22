<?php

namespace Tests\Feature;

use Tests\Support\DgrTestCase;

/** Long statements are paged, and every page carries the right balance forward. */
final class StatementPagingTest extends DgrTestCase
{
    public function testBalancesCarryAcrossPagesAndMatchTheWholePeriod(): void
    {
        $this->setupCompany('08');
        $owner = $this->makeUser('super_admin', 'Paging Owner');
        $this->loginAs($owner);
        $bank   = $this->makeBankAccount('Paging Current A/c');
        $ledger = (int) db_connect()->table('bank_cash_accounts')->where('id', $bank)->get()->getRow()->ledger_account_id;
        $from   = date('Y-m-d', strtotime('-20 days'));
        $db = db_connect();
        for ($i = 1; $i <= 23; $i++) {
            $db->transStart(); // journal numbers are allocated inside a transaction
            service('ledger')->post(date('Y-m-d', strtotime("-{$i} days")), 'manual', null, 'Paging entry ' . $i, [
                ['account' => $ledger, 'debit' => (string) (100 * $i), 'bank_cash_account_id' => $bank],
                ['account' => service('ledger')->account('opening_equity'), 'credit' => (string) (100 * $i)],
            ]);
            $db->transComplete();
        }
        // 3 entries fall before the period (opening 2,100 + 2,200 + 2,300), 20 inside (sum 100..2,000 = 21,000)
        $q = '?' . http_build_query(['from' => $from, 'to' => date('Y-m-d'), 'per_page' => 10]);

        $p1 = (string) $this->get('finance/accounts/' . $bank . $q)->response()->getBody();
        $this->assertStringContainsString('Opening balance', $p1);
        $this->assertStringContainsString('6,600.00', $p1, 'opening = entries before the period');
        $this->assertStringContainsString('Carried forward', $p1);
        $this->assertStringContainsString('27,600.00', $p1, 'period closing shown on every page');
        $this->assertStringContainsString('Showing 1–10 of 20', $p1);

        $p2 = (string) $this->get('finance/accounts/' . $bank . $q . '&page=2')->response()->getBody();
        $this->assertStringContainsString('Brought forward', $p2);
        // oldest first: page 1 holds days -20..-11 = 2,000+1,900+…+1,100 = 15,500 → brought forward 6,600 + 15,500
        $this->assertStringContainsString('22,100.00', $p2);
        $this->assertStringContainsString('Closing balance', $p2);
        $this->assertStringNotContainsString('Carried forward', $p2);

        $ledgerPage = (string) $this->get('finance/ledger/accounts/' . $ledger . $q . '&page=2')->response()->getBody();
        $this->assertStringContainsString('Brought forward', $ledgerPage);
        $this->assertStringContainsString('22,100.00', $ledgerPage);
    }
}
