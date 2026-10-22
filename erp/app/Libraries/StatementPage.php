<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * One page of a ledger statement with correct running balances: the
 * period's opening and closing, money in/out for the whole period (one
 * aggregate query), and the balance brought forward to this page. Pages
 * keep large accounts (cash, receivables) fast; a year of entries used to
 * be rendered as one multi-megabyte page.
 */
final class StatementPage
{
    /**
     * @return array{opening: string, closing: string, inflow: string, outflow: string, broughtForward: string, carriedForward: string, lines: list<array<string, mixed>>, total: int, page: int, perPage: int, lastPage: bool}
     */
    public static function build(BaseConnection $db, int $ledgerId, string $from, string $to, ?int $contactId, int $page, int $perPage): array
    {
        $opening = service('ledger')->balance($ledgerId, date('Y-m-d', strtotime($from . ' -1 day')), $contactId);
        $base    = static function () use ($db, $ledgerId, $from, $to, $contactId): BaseBuilder {
            return $db->table('journal_lines jl')->join('journal_entries je', 'je.id = jl.journal_entry_id')
                ->where('jl.ledger_account_id', $ledgerId)->where('je.entry_date >=', $from)->where('je.entry_date <=', $to)
                ->when($contactId !== null && $contactId > 0, static fn ($b) => $b->where('jl.contact_id', $contactId));
        };
        $sum   = $base()->select('COUNT(*) n, COALESCE(SUM(jl.debit),0) dr, COALESCE(SUM(jl.credit),0) cr', false)->get()->getRow();
        $total = (int) $sum->n;
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min(max(1, $page), $pages);
        $skip  = ($page - 1) * $perPage;

        $bf = $opening;
        if ($skip > 0) {
            $before = $base()->select('jl.debit - jl.credit AS amt', false)->orderBy('je.entry_date')->orderBy('je.id')->orderBy('jl.id')->limit($skip)->getCompiledSelect();
            $bf     = Dec::add($opening, (string) $db->query('SELECT COALESCE(SUM(amt),0) s FROM (' . $before . ') t')->getRow()->s);
        }
        $lines = $base()->select('jl.id, jl.debit, jl.credit, jl.memo, je.id AS entry_id, je.entry_no, je.entry_date, je.narration, je.reference, je.source_type, je.source_id, c.name AS contact_name')
            ->join('contacts c', 'c.id = jl.contact_id', 'left')
            ->orderBy('je.entry_date')->orderBy('je.id')->orderBy('jl.id')->limit($perPage, $skip)->get()->getResultArray();
        $run = $bf;
        foreach ($lines as &$l) {
            $run          = Dec::add($run, Dec::sub($l['debit'], $l['credit']));
            $l['running'] = $run;
        }
        unset($l);

        return [
            'opening' => $opening, 'closing' => Dec::add($opening, Dec::sub((string) $sum->dr, (string) $sum->cr)),
            'inflow' => Dec::round((string) $sum->dr), 'outflow' => Dec::round((string) $sum->cr),
            'broughtForward' => $bf, 'carriedForward' => $run, 'lines' => $lines,
            'total' => $total, 'page' => $page, 'perPage' => $perPage, 'lastPage' => $page >= $pages,
        ];
    }
}
