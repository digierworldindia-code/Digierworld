<?php

/**
 * DGR Office global view/controller helpers.
 * Autoloaded via Config\Autoload::$helpers.
 */

use App\Libraries\Dec;

if (! function_exists('can')) {
    /**
     * True if the logged-in user holds ANY of the given permissions.
     * Use only for UI decisions — routes/services enforce independently.
     *
     * @param list<string>|string $perms
     */
    function can(array|string $perms): bool
    {
        return service('authz')->canAny((array) $perms);
    }
}

if (! function_exists('current_user_id')) {
    function current_user_id(): ?int
    {
        return service('authz')->currentUserId();
    }
}

if (! function_exists('current_user_name')) {
    function current_user_name(): string
    {
        $id = current_user_id();
        if ($id === null) {
            return '';
        }
        static $names = [];
        if (! isset($names[$id])) {
            $row        = db_connect()->table('user_profiles')->select('full_name')->where('user_id', $id)->get()->getRowArray();
            $names[$id] = $row['full_name'] ?? (auth()->user()->username ?? 'User');
        }

        return $names[$id];
    }
}

if (! function_exists('inr')) {
    /** Indian-format currency, e.g. ₹1,23,456.00 (lakh grouping). */
    function inr(int|float|string|null $amount, bool $symbol = true): string
    {
        $value = Dec::round($amount ?? '0');
        $neg   = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$int, $frac] = explode('.', $value) + [1 => '00'];
        if (strlen($int) > 3) {
            $last3 = substr($int, -3);
            $rest  = substr($int, 0, -3);
            $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $int   = $rest . ',' . $last3;
        }

        return ($neg ? '-' : '') . ($symbol ? '₹' : '') . $int . '.' . $frac;
    }
}

if (! function_exists('dmy')) {
    /** Indian display date DD-MM-YYYY (optionally with time). */
    function dmy(?string $date, bool $withTime = false): string
    {
        if ($date === null || $date === '' || str_starts_with($date, '0000')) {
            return '—';
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return esc($date);
        }

        return date($withTime ? 'd-m-Y h:i A' : 'd-m-Y', $ts);
    }
}

if (! function_exists('ymd')) {
    /** Parse DD-MM-YYYY or YYYY-MM-DD input into YYYY-MM-DD (or null). */
    function ymd(?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }
        if (preg_match('/^(\d{2})[-\/.](\d{2})[-\/.](\d{4})$/', $input, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? "{$m[3]}-{$m[2]}-{$m[1]}" : null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $input, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }

        return null;
    }
}

if (! function_exists('normalize_phone')) {
    /** Normalise Indian mobile/phone to its last 10 digits for dedupe. */
    function normalize_phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '' || $digits === null) {
            return null;
        }
        if (strlen($digits) > 10 && (str_starts_with($digits, '91') || str_starts_with($digits, '0'))) {
            $digits = substr($digits, -10);
        }

        return $digits;
    }
}

if (! function_exists('mask_tail')) {
    /** Mask all but the last 4 characters: ••••1234 */
    function mask_tail(?string $value, int $keep = 4): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return '••••' . substr($value, -$keep);
    }
}

if (! function_exists('status_badge')) {
    function status_badge(?string $status): string
    {
        $map = [
            'draft' => 'secondary', 'finalized' => 'success', 'cancelled' => 'danger', 'converted' => 'info',
            'unpaid' => 'warning', 'partial' => 'info', 'paid' => 'success', 'overpaid' => 'primary', 'na' => 'light',
            'pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'done' => 'success',
            'failed' => 'danger', 'dead' => 'dark', 'processing' => 'info', 'active' => 'success', 'void' => 'dark',
            'unmatched' => 'warning', 'matched' => 'success', 'scheduled' => 'primary', 'completed' => 'success',
            'in_progress' => 'info', 'overdue' => 'danger', 'needs_review' => 'warning', 'auto_ok' => 'success',
            'resolved' => 'success', 'blocked' => 'danger', 'calculated' => 'info', 'locked' => 'dark',
            'proposed' => 'warning', 'included' => 'info', 'clawed_back' => 'danger', 'open' => 'success',
            'closed' => 'secondary', 'expired' => 'secondary', 'suggested' => 'warning', 'accountant_reviewed' => 'success',
        ];
        $status = (string) $status;
        $cls    = $map[$status] ?? 'secondary';
        $text   = $cls === 'light' || $cls === 'warning' ? ' text-dark' : '';

        return '<span class="badge bg-' . $cls . $text . '">' . esc(ucwords(str_replace('_', ' ', $status))) . '</span>';
    }
}

if (! function_exists('doc_type_label')) {
    function doc_type_label(string $type): string
    {
        return [
            'tax_invoice' => 'Tax Invoice', 'bill_of_supply' => 'Bill of Supply', 'cash_memo' => 'Cash Memo',
            'estimate' => 'Estimate', 'quotation' => 'Quotation', 'proforma' => 'Proforma Invoice',
            'credit_note' => 'Credit Note', 'debit_note' => 'Debit Note', 'receipt' => 'Receipt Voucher',
        ][$type] ?? ucwords(str_replace('_', ' ', $type));
    }
}

if (! function_exists('gst_type_label')) {
    function gst_type_label(?string $type): string
    {
        return [
            'cgst_sgst' => 'CGST + SGST (intra-state)', 'cgst_utgst' => 'CGST + UTGST (intra-UT)',
            'igst' => 'IGST (inter-state)', 'none' => 'No GST charged',
        ][(string) $type] ?? 'Not selected';
    }
}

if (! function_exists('flash_errors')) {
    /** @return array<string, string> */
    function flash_errors(): array
    {
        return (array) (session()->getFlashdata('errors') ?? []);
    }
}

if (! function_exists('field_error')) {
    function field_error(string $field): string
    {
        $errors = flash_errors();

        return isset($errors[$field]) ? '<div class="invalid-feedback d-block">' . esc($errors[$field]) . '</div>' : '';
    }
}

if (! function_exists('is_invalid')) {
    function is_invalid(string $field): string
    {
        return isset(flash_errors()[$field]) ? ' is-invalid' : '';
    }
}

if (! function_exists('fy_for_date')) {
    /** Financial year code for a date given the FY start month (default April). */
    function fy_for_date(string $date, int $startMonth = 4): array
    {
        $ts    = strtotime($date);
        $year  = (int) date('Y', $ts);
        $month = (int) date('n', $ts);
        $start = $month >= $startMonth ? $year : $year - 1;
        $end   = $start + 1;

        return [
            'code'       => $start . '-' . substr((string) $end, 2),
            'short_code' => substr((string) $start, 2) . '-' . substr((string) $end, 2),
            'start_date' => sprintf('%04d-%02d-01', $start, $startMonth),
            'end_date'   => date('Y-m-d', strtotime(sprintf('%04d-%02d-01', $end, $startMonth) . ' -1 day')),
        ];
    }
}

if (! function_exists('journal_source')) {
    /**
     * Human label and link for a journal entry's source record.
     *
     * @return array{0: string, 1: string|null} [label, url]
     */
    function journal_source(string $type, ?int $id, ?int $entryId = null): array
    {
        return match ($type) {
            'billing_document'   => ['Billing document', $id ? site_url('billing/documents/' . $id) : null],
            'payment_receipt', 'refund', 'payment_allocation' => [$type === 'refund' ? 'Refund' : ($type === 'payment_allocation' ? 'Advance applied' : 'Receipt'), $id ? site_url('finance/receipts/' . $id) : null],
            'money_transfer'     => ['Transfer', $id ? site_url('finance/transfers/' . $id) : null],
            'expense'            => ['Expense', $id ? site_url('finance/expenses/' . $id) : null],
            'expense_payment'    => ['Expense payment', $id ? site_url('finance/expenses/' . (int) (db_connect()->table('expense_payments')->select('expense_id')->where('id', $id)->get()->getRow()->expense_id ?? 0)) : null],
            'opening_balance'    => ['Opening balance', $id ? site_url('finance/accounts/' . $id) : null],
            'payroll_run'        => ['Payroll', $id ? site_url('hr/payroll/' . $id) : null],
            'manual'             => ['Manual journal', $entryId ? site_url('finance/ledger/entries/' . $entryId) : null],
            default              => [ucwords(str_replace('_', ' ', $type)), null],
        };
    }
}

if (! function_exists('dr_cr')) {
    /** Signed ledger balance as "₹1,234.00 Dr" / "₹1,234.00 Cr" ("—" for zero). */
    function dr_cr(int|float|string|null $balance): string
    {
        $b = \App\Libraries\Dec::round((string) ($balance ?? '0'));
        if (\App\Libraries\Dec::isZero($b)) {
            return '—';
        }

        return inr(\App\Libraries\Dec::abs($b)) . (\App\Libraries\Dec::isPositive($b) ? ' Dr' : ' Cr');
    }
}
