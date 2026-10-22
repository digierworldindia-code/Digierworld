<?php

namespace App\Controllers\Finance;

use App\Controllers\BaseController;
use App\Exceptions\BusinessRuleException;
use App\Libraries\Dec;
use App\Libraries\StatementPage;

/** Journal, chart of accounts, account ledgers, trial balance and manual journals. */
class LedgerController extends BaseController
{
    private const GROUPS = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expenses'];

    /** @return array{0: string, 1: string} */
    private function range(): array
    {
        $fy   = fy_for_date(date('Y-m-d'));
        $from = ymd((string) $this->request->getGet('from')) ?? $fy['start_date'];
        $to   = ymd((string) $this->request->getGet('to')) ?? date('Y-m-d');

        return $from > $to ? [$to, $from] : [$from, $to];
    }

    public function index()
    {
        [$from, $to] = $this->range();
        $src = (string) $this->request->getGet('source');
        $q   = trim((string) $this->request->getGet('q'));
        [$page, $perPage, $offset] = $this->paging(50);
        $b = fn () => $this->db->table('journal_entries je')->where('je.entry_date >=', $from)->where('je.entry_date <=', $to)
            ->when($src !== '', static fn ($b) => $b->where('je.source_type', $src))
            ->when($q !== '', static fn ($b) => $b->groupStart()->like('je.entry_no', $q)->orLike('je.narration', $q)->orLike('je.reference', $q)->groupEnd());

        return $this->render('finance/ledger/index', [
            'pageTitle' => 'Journal', 'breadcrumbs' => [['Finance'], ['Journal']],
            'rows' => $b()->select('je.*, p.full_name AS poster')->join('user_profiles p', 'p.user_id = je.posted_by', 'left')
                ->orderBy('je.entry_date', 'DESC')->orderBy('je.id', 'DESC')->limit($perPage, $offset)->get()->getResultArray(),
            'total' => $b()->countAllResults(), 'page' => $page, 'perPage' => $perPage,
            'from' => $from, 'to' => $to, 'source' => $src, 'q' => $q,
            'sources' => array_column($this->db->table('journal_entries')->distinct()->select('source_type')->orderBy('source_type')->get()->getResultArray(), 'source_type'),
        ]);
    }

    public function entry($id)
    {
        $e = $this->db->table('journal_entries je')->select('je.*, p.full_name AS poster')->join('user_profiles p', 'p.user_id = je.posted_by', 'left')
            ->where('je.id', $this->idOr404($id))->get()->getRowArray();
        if ($e === null) {
            $this->notFound();
        }

        return $this->render('finance/ledger/entry', [
            'pageTitle' => 'Journal ' . $e['entry_no'], 'breadcrumbs' => [['Finance'], ['Journal', 'finance/ledger'], [$e['entry_no']]],
            'e' => $e,
            'lines' => $this->db->table('journal_lines jl')->select('jl.*, la.code, la.name AS account_name, c.name AS contact_name, b.display_name AS bank_name')
                ->join('ledger_accounts la', 'la.id = jl.ledger_account_id')->join('contacts c', 'c.id = jl.contact_id', 'left')
                ->join('bank_cash_accounts b', 'b.id = jl.bank_cash_account_id', 'left')->where('jl.journal_entry_id', $e['id'])->orderBy('jl.id')->get()->getResultArray(),
            'reversal' => $e['reversed_by_id'] ? $this->db->table('journal_entries')->select('id, entry_no')->where('id', $e['reversed_by_id'])->get()->getRowArray() : null,
            'original' => $e['reversal_of_id'] ? $this->db->table('journal_entries')->select('id, entry_no')->where('id', $e['reversal_of_id'])->get()->getRowArray() : null,
        ]);
    }

    public function accounts()
    {
        [$from, $to] = $this->range();
        $rows = $this->balances($from, $to);

        return $this->render('finance/ledger/accounts', [
            'pageTitle' => 'Chart of accounts', 'breadcrumbs' => [['Finance'], ['Journal', 'finance/ledger'], ['Chart of accounts']],
            'rows' => $rows, 'groups' => self::GROUPS, 'from' => $from, 'to' => $to,
        ]);
    }

    public function account($id)
    {
        $acc = $this->db->table('ledger_accounts')->where('id', $this->idOr404($id))->get()->getRowArray();
        if ($acc === null) {
            $this->notFound();
        }
        [$from, $to] = $this->range();
        $contact = (int) $this->request->getGet('contact');
        [$page, $perPage] = $this->paging(100);
        $st = StatementPage::build($this->db, (int) $acc['id'], $from, $to, $contact ?: null, $page, $perPage);

        return $this->render('finance/ledger/account', $st + [
            'pageTitle' => $acc['code'] . ' ' . $acc['name'], 'breadcrumbs' => [['Finance'], ['Chart of accounts', 'finance/ledger/accounts'], [$acc['name']]],
            'acc' => $acc, 'from' => $from, 'to' => $to,
            'contact' => $contact ? $this->db->table('contacts')->select('id, name')->where('id', $contact)->get()->getRowArray() : null,
        ]);
    }

    /**
     * Opening, period movement and closing per ledger account.
     *
     * @return list<array<string, mixed>>
     */
    private function balances(string $from, string $to): array
    {
        $sql = "SELECT la.id, la.code, la.name, la.account_group, la.sub_type, la.is_system, la.is_active,
                    COALESCE(SUM(CASE WHEN je.entry_date < ? THEN jl.debit - jl.credit END),0) AS opening,
                    COALESCE(SUM(CASE WHEN je.entry_date BETWEEN ? AND ? THEN jl.debit END),0) AS debit,
                    COALESCE(SUM(CASE WHEN je.entry_date BETWEEN ? AND ? THEN jl.credit END),0) AS credit
                FROM ledger_accounts la
                LEFT JOIN journal_lines jl ON jl.ledger_account_id = la.id
                LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.entry_date <= ?
                GROUP BY la.id, la.code, la.name, la.account_group, la.sub_type, la.is_system, la.is_active
                ORDER BY FIELD(la.account_group,'asset','liability','equity','income','expense'), la.code";
        $rows = $this->db->query($sql, [$from, $from, $to, $from, $to, $to])->getResultArray();
        foreach ($rows as &$r) {
            $r['opening'] = Dec::round($r['opening']);
            $r['debit']   = Dec::round($r['debit']);
            $r['credit']  = Dec::round($r['credit']);
            $r['closing'] = Dec::add($r['opening'], Dec::sub($r['debit'], $r['credit']));
        }

        return $rows;
    }

    public function trialBalance()
    {
        [$from, $to] = $this->range();
        $rows = array_values(array_filter($this->balances($from, $to), static fn ($r) => ! Dec::isZero($r['closing']) || ! Dec::isZero($r['debit']) || ! Dec::isZero($r['credit'])));
        $tot  = ['dr' => '0', 'cr' => '0', 'pd' => '0', 'pc' => '0'];
        foreach ($rows as $r) {
            $tot['pd'] = Dec::add($tot['pd'], $r['debit']);
            $tot['pc'] = Dec::add($tot['pc'], $r['credit']);
            if (Dec::isPositive($r['closing'])) {
                $tot['dr'] = Dec::add($tot['dr'], $r['closing']);
            } else {
                $tot['cr'] = Dec::add($tot['cr'], Dec::abs($r['closing']));
            }
        }
        if ($this->request->getGet('format')) {
            $this->authorize('reports.export');
            $out = array_map(static fn ($r) => $r + ['dr_bal' => Dec::isPositive($r['closing']) ? $r['closing'] : null, 'cr_bal' => Dec::isNegative($r['closing']) ? Dec::abs($r['closing']) : null, 'group' => self::GROUPS[$r['account_group']]], $rows);
            $cols = [['key' => 'code', 'label' => 'Code'], ['key' => 'name', 'label' => 'Ledger', 'width' => 40], ['key' => 'group', 'label' => 'Group'],
                ['key' => 'debit', 'label' => 'Period debit', 'type' => 'money'], ['key' => 'credit', 'label' => 'Period credit', 'type' => 'money'],
                ['key' => 'dr_bal', 'label' => 'Closing Dr', 'type' => 'money'], ['key' => 'cr_bal', 'label' => 'Closing Cr', 'type' => 'money']];

            return service('exports')->download('trial_balance', (string) $this->request->getGet('format'), 'Trial_balance', [['title' => 'Trial balance', 'columns' => $cols, 'rows' => $out]], ['from' => $from, 'to' => $to], ['Report' => 'Trial balance']);
        }

        return $this->render('finance/ledger/trial_balance', [
            'pageTitle' => 'Trial balance', 'breadcrumbs' => [['Finance'], ['Journal', 'finance/ledger'], ['Trial balance']],
            'rows' => $rows, 'tot' => $tot, 'groups' => self::GROUPS, 'from' => $from, 'to' => $to,
        ]);
    }

    // ---- manual journals ------------------------------------------------

    public function newJournal()
    {
        return $this->render('finance/ledger/journal_form', [
            'pageTitle' => 'Manual journal', 'breadcrumbs' => [['Finance'], ['Journal', 'finance/ledger'], ['Manual journal']],
            'ledgers' => $this->db->table('ledger_accounts')->select('id, code, name, account_group, sub_type')->where('is_active', 1)->orderBy('code')->get()->getResultArray(),
        ]);
    }

    public function createJournal()
    {
        $date      = ymd((string) $this->request->getPost('entry_date')) ?? date('Y-m-d');
        $narration = trim((string) $this->request->getPost('narration'));
        if (mb_strlen($narration) < 10) {
            return redirect()->back()->withInput()->with('error', 'Describe the purpose of this journal (at least 10 characters).');
        }
        $bankByLedger = array_column($this->db->table('bank_cash_accounts')->select('id, ledger_account_id')->get()->getResultArray(), 'id', 'ledger_account_id');
        $lines = [];
        foreach ((array) $this->request->getPost('lines') as $l) {
            $acc = (int) ($l['account'] ?? 0);
            $dr  = Dec::round(str_replace(',', '', (string) ($l['debit'] ?? '') ?: '0'));
            $cr  = Dec::round(str_replace(',', '', (string) ($l['credit'] ?? '') ?: '0'));
            if ($acc <= 0 || (Dec::isZero($dr) && Dec::isZero($cr))) {
                continue;
            }
            if (Dec::isNegative($dr) || Dec::isNegative($cr) || (! Dec::isZero($dr) && ! Dec::isZero($cr))) {
                return redirect()->back()->withInput()->with('error', 'Each line needs either a debit or a credit (positive amounts).');
            }
            $lines[] = ['account' => $acc, 'debit' => $dr, 'credit' => $cr, 'contact_id' => ((int) ($l['contact_id'] ?? 0)) ?: null,
                'bank_cash_account_id' => $bankByLedger[$acc] ?? null, 'memo' => trim((string) ($l['memo'] ?? '')) ?: null];
        }
        if (count($lines) < 2) {
            return redirect()->back()->withInput()->with('error', 'A journal needs at least two lines.');
        }

        $this->db->transBegin();
        try {
            $id = service('ledger')->post($date, 'manual', null, $narration, $lines, trim((string) $this->request->getPost('reference')) ?: null);
            service('audit')->log('journal.manual_posted', 'journal_entry', $id, null, ['lines' => $lines, 'date' => $date], 'Manual journal: ' . mb_strimwidth($narration, 0, 80, '…'));
            $this->db->transCommit();
        } catch (BusinessRuleException $e) {
            $this->db->transRollback();

            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return redirect()->to(site_url('finance/ledger/entries/' . $id))->with('success', 'Journal posted.');
    }

    /** Only manual journals are reversed here; documents are cancelled/voided from their own screens. */
    public function reverse($id)
    {
        $e = $this->db->table('journal_entries')->where('id', $this->idOr404($id))->get()->getRowArray();
        if ($e === null) {
            $this->notFound();
        }
        if ($e['source_type'] !== 'manual' || $e['reversal_of_id'] !== null) {
            return redirect()->back()->with('error', 'Only manual journals can be reversed here. Cancel or void the source document instead.');
        }
        $reason = trim((string) $this->request->getPost('reason'));
        if (mb_strlen($reason) < 5) {
            return redirect()->back()->with('error', 'Give a reason for the reversal.');
        }
        $this->db->transBegin();
        try {
            $rev = service('ledger')->reverse((int) $e['id'], date('Y-m-d'), $reason);
            service('audit')->log('journal.reversed', 'journal_entry', (int) $e['id'], null, ['reversal' => $rev, 'reason' => $reason], 'Reversed ' . $e['entry_no']);
            $this->db->transCommit();
        } catch (BusinessRuleException $ex) {
            $this->db->transRollback();

            return redirect()->back()->with('error', $ex->getMessage());
        } catch (\Throwable $ex) {
            $this->db->transRollback();

            throw $ex;
        }

        return redirect()->to(site_url('finance/ledger/entries/' . $rev))->with('success', 'Reversal posted.');
    }

    public function createAccount()
    {
        $name  = trim((string) $this->request->getPost('name'));
        $group = (string) $this->request->getPost('account_group');
        if ($name === '' || ! isset(self::GROUPS[$group])) {
            return redirect()->back()->with('error', 'Enter a name and choose the group.');
        }
        if ($this->db->table('ledger_accounts')->where('name', $name)->countAllResults() > 0) {
            return redirect()->back()->with('error', 'A ledger with this name already exists.');
        }
        $base = ['asset' => 1500, 'liability' => 2500, 'equity' => 3500, 'income' => 4500, 'expense' => 5500][$group];
        $next = (int) ($this->db->query('SELECT COALESCE(MAX(CAST(code AS UNSIGNED)), ?) m FROM ledger_accounts WHERE code REGEXP \'^[0-9]+$\' AND CAST(code AS UNSIGNED) BETWEEN ? AND ?', [$base - 1, $base, $base + 499])->getRow()->m) + 1;
        $this->db->table('ledger_accounts')->insert([
            'code' => (string) $next, 'name' => mb_substr($name, 0, 150), 'account_group' => $group,
            'sub_type' => $group === 'expense' ? 'expense' : 'other_' . $group, 'is_system' => 0, 'created_by' => $this->userId,
        ]);
        $id = (int) $this->db->insertID();
        service('audit')->log('ledger_account.created', 'ledger_account', $id, null, ['name' => $name, 'group' => $group, 'code' => $next], 'Ledger created: ' . $name);

        return redirect()->to(site_url('finance/ledger/accounts'))->with('success', 'Ledger ' . $next . ' ' . $name . ' created.');
    }
}
