<?php

namespace App\Controllers\Finance;

use App\Controllers\BaseController;
use App\Exceptions\BusinessRuleException;
use App\Libraries\BankStatementParser;

/** Bank statement import (with column mapping) and reconciliation workspace. */
class ReconciliationController extends BaseController
{
    /** @return list<array<string, mixed>> */
    private function bankAccounts(): array
    {
        return $this->db->table('bank_cash_accounts')->select('id, display_name, account_kind, ownership')->where('is_active', 1)->where('account_kind !=', 'cash')
            ->orderBy('display_priority')->orderBy('display_name')->get()->getResultArray();
    }

    public function index()
    {
        $accounts = $this->bankAccounts();
        $accId    = (int) ($this->request->getGet('account') ?: ($accounts[0]['id'] ?? 0));
        $view     = in_array($this->request->getGet('view'), ['unmatched', 'book', 'matched', 'ignored', 'imports'], true) ? $this->request->getGet('view') : 'unmatched';
        $svc      = service('reconciliation');
        $data     = [
            'pageTitle' => 'Bank reconciliation', 'breadcrumbs' => [['Finance'], ['Bank reconciliation']],
            'accounts' => $accounts, 'accId' => $accId, 'view' => $view, 'summary' => null, 'lines' => [], 'book' => [], 'imports' => [],
            'canReconcile' => $this->can('finance.bank.reconcile'),
        ];
        if ($accId === 0) {
            return $this->render('finance/reconciliation/index', $data);
        }
        if (! in_array($accId, array_map('intval', array_column($accounts, 'id')), true)) {
            $this->notFound();
        }
        $data['summary'] = $svc->summary($accId);
        if (in_array($view, ['unmatched', 'matched', 'ignored'], true)) {
            [$page, $perPage, $offset] = $this->paging(25);
            $b = fn () => $this->db->table('bank_import_lines')->where(['bank_cash_account_id' => $accId, 'match_status' => $view]);
            $lines = $b()->orderBy('txn_date', $view === 'unmatched' ? 'ASC' : 'DESC')->orderBy('id')->limit($perPage, $offset)->get()->getResultArray();
            foreach ($lines as &$l) {
                if ($view === 'unmatched' && $data['canReconcile']) {
                    $l['suggestions'] = $svc->suggestions($l);
                }
                if ($view === 'matched') {
                    $l['matches'] = $this->db->table('reconciliation_matches')->where(['bank_import_line_id' => $l['id'], 'status' => 'confirmed'])->get()->getResultArray();
                }
            }
            unset($l);
            $data += ['total' => $b()->countAllResults(), 'page' => $page, 'perPage' => $perPage];
            $data['lines'] = $lines;
            if ($view === 'unmatched' && $data['canReconcile']) {
                $data['ledgers'] = $this->db->table('ledger_accounts')->select('id, code, name, account_group')->whereIn('account_group', ['income', 'expense'])
                    ->where('is_active', 1)->whereNotIn('sub_type', ['sales', 'sales_exempt', 'sales_zero_rated', 'sales_returns', 'salary_expense', 'incentive_expense', 'employer_statutory'])
                    ->orderBy('code')->get()->getResultArray();
            }
        } elseif ($view === 'book') {
            foreach (['receipt', 'refund', 'transfer_in', 'transfer_out', 'expense_payment', 'salary_payment'] as $t) {
                $data['book'] = array_merge($data['book'], $svc->openTargets($t, $accId));
            }
            usort($data['book'], static fn ($a, $b) => strcmp($a['date'], $b['date']));
        } else {
            $data['imports'] = $this->db->table('bank_imports i')->select('i.*, p.full_name')->join('user_profiles p', 'p.user_id = i.imported_by', 'left')
                ->where('i.bank_cash_account_id', $accId)->orderBy('i.id', 'DESC')->get()->getResultArray();
        }

        return $this->render('finance/reconciliation/index', $data);
    }

    public function importForm()
    {
        return $this->render('finance/reconciliation/import', [
            'pageTitle' => 'Import bank statement', 'breadcrumbs' => [['Finance'], ['Bank reconciliation', 'finance/reconciliation'], ['Import statement']],
            'accounts' => $this->bankAccounts(), 'accId' => (int) $this->request->getGet('account'), 'preview' => null,
        ]);
    }

    /** Upload → detect header & mapping → preview (nothing is imported yet). */
    public function preview()
    {
        $accId = (int) $this->request->getPost('account_id');
        $file  = $this->request->getFile('file');
        if ($file === null || ! $file->isValid()) {
            return redirect()->back()->with('error', 'Choose the statement file (.csv or .xlsx).');
        }

        try {
            $fid = service('files')->storeUpload($file, 'sheet', 'bank_statement', 'bank_cash_account', $accId);
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('finance/reconciliation/map?' . http_build_query(['file' => $fid, 'account' => $accId])));
    }

    public function map()
    {
        $fid   = (int) $this->request->getGet('file');
        $accId = (int) $this->request->getGet('account');
        [$rows, $name] = $this->rows($fid);
        $header = $this->request->getGet('header') !== null ? (int) $this->request->getGet('header') : BankStatementParser::detectHeader($rows);
        $map    = [];
        if ($header >= 0 && isset($rows[$header])) {
            $map = BankStatementParser::guessMapping($rows[$header]);
            foreach (array_keys(BankStatementParser::FIELDS) as $f) {
                $v = $this->request->getGet('map_' . $f);
                if ($v !== null) {
                    $map[$f] = $v === '' ? null : (int) $v;
                }
            }
        }
        $parsed = $header >= 0 ? BankStatementParser::parse($rows, $header, $map) : ['lines' => [], 'errors' => ['Could not find the transaction table header. Choose the header row below.']];

        return $this->render('finance/reconciliation/import', [
            'pageTitle' => 'Import bank statement', 'breadcrumbs' => [['Finance'], ['Bank reconciliation', 'finance/reconciliation'], ['Import statement']],
            'accounts' => $this->bankAccounts(), 'accId' => $accId,
            'preview' => ['file' => $fid, 'name' => $name, 'rows' => array_slice($rows, 0, 40), 'header' => $header, 'map' => $map, 'parsed' => $parsed],
        ]);
    }

    public function commit()
    {
        $fid   = (int) $this->request->getPost('file');
        $accId = (int) $this->request->getPost('account_id');
        [$rows, $name] = $this->rows($fid);
        $header = (int) $this->request->getPost('header');
        $map    = [];
        foreach (array_keys(BankStatementParser::FIELDS) as $f) {
            $v = $this->request->getPost('map_' . $f);
            $map[$f] = $v === null || $v === '' ? null : (int) $v;
        }
        $parsed = BankStatementParser::parse($rows, $header, $map);

        try {
            $res = service('reconciliation')->import($accId, $fid, $name, $parsed['lines'], count($parsed['errors']));
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('finance/reconciliation?account=' . $accId))
            ->with('success', "Imported {$res['imported']} new statement lines" . ($res['duplicates'] ? ", skipped {$res['duplicates']} already imported" : '') . (count($parsed['errors']) ? ', ' . count($parsed['errors']) . ' rows had errors' : '') . '.');
    }

    /** @return array{0: list<list<string>>, 1: string} */
    private function rows(int $fid): array
    {
        $file = service('files')->find($fid);
        if ($file === null || $file['category'] !== 'bank_statement') {
            $this->notFound();
        }

        try {
            return [BankStatementParser::readRaw(service('files')->path($file), $file['original_name']), $file['original_name']];
        } catch (\Throwable $e) {
            throw new BusinessRuleException('The file could not be read as a spreadsheet: ' . $e->getMessage());
        }
    }

    public function confirm($lineId)
    {
        $targets = [];
        foreach ((array) $this->request->getPost('targets') as $t) {
            if (is_string($t) && preg_match('/^([a-z_]+):(\d+)$/', $t, $m)) {
                $targets[] = ['type' => $m[1], 'id' => (int) $m[2]];
            }
        }

        try {
            service('reconciliation')->confirm($this->idOr404($lineId), $targets);
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Matched.');
    }

    public function unmatch($lineId)
    {
        service('reconciliation')->unmatch($this->idOr404($lineId));

        return redirect()->back()->with('success', 'Match removed. The line and its book entries are unmatched again.');
    }

    public function ignore($lineId)
    {
        try {
            service('reconciliation')->ignore($this->idOr404($lineId), (string) $this->request->getPost('reason'));
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Line marked as needing no entry.');
    }

    public function book($lineId)
    {
        $this->authorize('finance.ledger.journal');

        try {
            service('reconciliation')->bookFromLine($this->idOr404($lineId), (int) $this->request->getPost('ledger_account_id'), trim((string) $this->request->getPost('narration')));
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Entry booked and matched.');
    }
}
