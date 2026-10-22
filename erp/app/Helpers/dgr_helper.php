<?php

/**
 * DGR Office global view/controller helpers.
 * Autoloaded via Config\Autoload::$helpers.
 */

use App\Libraries\Dec;

if (! function_exists('asset')) {
    /**
     * URL of a file under public/assets with a content-hash version, so a
     * deployment that changes a stylesheet or script is picked up at once.
     * Only local, self-hosted files exist under public/assets (no CDNs).
     */
    function asset(string $path): string
    {
        static $versions = [];
        $path = ltrim($path, '/');
        if (! isset($versions[$path])) {
            $file              = FCPATH . 'assets/' . $path;
            $versions[$path]   = is_file($file) ? substr(hash_file('sha256', $file), 0, 10) : '0';
        }

        return base_url('assets/' . $path) . '?v=' . $versions[$path];
    }
}

if (! function_exists('base_url_mismatch')) {
    /**
     * When the page was opened at a different address than app.baseURL in
     * .env, returns ['configured' => …, 'detected' => …]; otherwise null (and
     * always null on the command line). The detected application root is the
     * leading path that the request and the front controller share, exactly
     * how CodeIgniter routes, so it is right both when the domain points at
     * public/ and when the whole folder sits in public_html/<folder>/.
     * A wrong baseURL breaks every link, stylesheet and post-sign-in redirect.
     *
     * @return array{configured: string, detected: string}|null
     */
    function base_url_mismatch(): ?array
    {
        $request = service('request');
        if (is_cli() || ! $request instanceof \CodeIgniter\HTTP\IncomingRequest) {
            return null;
        }
        $host   = strtolower((string) $request->getServer('HTTP_HOST'));
        $script = explode('/', (string) $request->getServer('SCRIPT_NAME'));
        $path   = explode('/', (string) parse_url('http://x' . $request->getServer('REQUEST_URI'), PHP_URL_PATH));
        if ($host === '' || count($script) < 2) {
            return null;
        }
        $root = [];
        foreach ($script as $i => $segment) {
            if ($i === 0) {
                continue;
            }
            if (str_ends_with($segment, '.php') || ! isset($path[$i]) || rawurldecode($path[$i]) !== $segment) {
                break;
            }
            $root[] = $segment;
        }
        $detected   = ($request->isSecure() ? 'https' : 'http') . '://' . $host . '/' . ($root === [] ? '' : implode('/', $root) . '/');
        $configured = rtrim(config('App')->baseURL, '/') . '/';
        $cfg        = parse_url($configured);
        $sameHost   = strtolower((string) ($cfg['host'] ?? '')) . (isset($cfg['port']) ? ':' . $cfg['port'] : '') === $host;
        $samePath   = rtrim((string) ($cfg['path'] ?? '/'), '/') . '/' === '/' . ($root === [] ? '' : implode('/', $root) . '/');
        // an https page with an http baseURL loads its own links and styles insecurely
        $sameScheme = ! $request->isSecure() || ($cfg['scheme'] ?? '') === 'https';

        return $sameHost && $samePath && $sameScheme ? null : ['configured' => $configured, 'detected' => $detected];
    }
}

if (! function_exists('brand_logo_state')) {
    /**
     * 'ok' when an active logo is uploaded and its file is on disk, 'missing'
     * when the database lists one whose file is gone (writable/uploads lost
     * on an upgrade or restore), 'none' when no logo was uploaded. Layouts
     * show the logo only for 'ok', so a lost file never shows a broken image.
     */
    function brand_logo_state(): string
    {
        try {
            $file = db_connect()->table('branding_assets b')->select('f.id, f.disk_path')->join('stored_files f', 'f.id = b.stored_file_id')
                ->where(['b.asset_type' => 'logo', 'b.is_active' => 1])->orderBy('b.version', 'DESC')->get()->getRowArray();
        } catch (\Throwable) {
            return 'none';
        }
        if ($file === null) {
            return 'none';
        }

        return service('files')->isOnDisk($file) ? 'ok' : 'missing';
    }
}

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
            'probation' => 'info', 'notice' => 'warning', 'exited' => 'secondary', 'present' => 'success', 'absent' => 'danger',
            'half_day' => 'info', 'leave' => 'primary', 'holiday' => 'light', 'week_off' => 'light', 'unmarked' => 'warning',
            'applied' => 'secondary', 'resume_screened' => 'info', 'hr_screening' => 'info', 'interview_scheduled' => 'primary',
            'interview_completed' => 'primary', 'assignment_gd' => 'primary', 'shortlisted' => 'success', 'on_hold' => 'warning',
            'offer_sent' => 'info', 'offer_accepted' => 'success', 'joined' => 'success', 'withdrawn' => 'secondary',
            'filled' => 'success', 'unavailable' => 'warning', 'accepted' => 'success', 'overridden' => 'info',
            'pass' => 'success', 'fail' => 'danger', 'hold' => 'warning', 'no_show' => 'dark', 'partially_paid' => 'info',
            'revoked' => 'dark', 'denied' => 'danger', 'allowed' => 'success', 'error' => 'danger', 'fallback' => 'warning',
        ];
        $status = (string) $status;
        // design-system tones (dgr-theme.css): success, warning, danger, info, gold, neutral
        $base   = $map[$status] ?? 'secondary';
        $tone   = ['primary' => 'gold', 'secondary' => 'neutral', 'light' => 'neutral', 'dark' => 'neutral'][$base] ?? $base;

        return '<span class="badge badge-' . $tone . '">' . esc(ucwords(str_replace('_', ' ', $status))) . '</span>';
    }
}

if (! function_exists('stage_tone')) {
    /**
     * Design-system tone for a CRM stage, from what the stage means (won,
     * lost, do-not-call, late or engaged) rather than a stored colour.
     */
    function stage_tone(?string $code, int|string|null $won = 0, int|string|null $lost = 0, int|string|null $dnd = 0): string
    {
        return match (true) {
            (int) $won === 1 || $code === 'won'                                  => 'success',
            (int) $dnd === 1 || $code === 'dnc'                                  => 'neutral',
            (int) $lost === 1                                                    => 'danger',
            in_array($code, ['proposal_sent', 'negotiation'], true)             => 'gold',
            in_array($code, ['connected', 'interested', 'followup', 'meeting_scheduled', 'visit_scheduled'], true) => 'info',
            default                                                              => 'neutral',
        };
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
        [$label, $url, $perms] = match ($type) {
            'billing_document'   => ['Billing document', $id ? site_url('billing/documents/' . $id) : null, ['billing.invoice.view_own', 'billing.invoice.view_all', 'billing.cash_memo.view_own', 'billing.cash_memo.view_all', 'billing.estimate.view_own', 'billing.estimate.view_all', 'billing.note.view_all']],
            'payment_receipt', 'refund', 'payment_allocation' => [$type === 'refund' ? 'Refund' : ($type === 'payment_allocation' ? 'Advance applied' : 'Receipt'), $id ? site_url('finance/receipts/' . $id) : null, ['finance.receipt.view']],
            'money_transfer'     => ['Transfer', $id ? site_url('finance/transfers/' . $id) : null, ['finance.transfer.view']],
            'expense'            => ['Expense', $id ? site_url('finance/expenses/' . $id) : null, ['finance.expense.view_own', 'finance.expense.view_all']],
            'expense_payment'    => ['Expense payment', $id ? site_url('finance/expenses/' . (int) (db_connect()->table('expense_payments')->select('expense_id')->where('id', $id)->get()->getRow()->expense_id ?? 0)) : null, ['finance.expense.view_own', 'finance.expense.view_all']],
            'opening_balance'    => ['Opening balance', $id ? site_url('finance/accounts/' . $id) : null, ['finance.bank.view']],
            'payroll_run'        => ['Payroll', $id ? site_url('hr/payroll/' . $id) : null, ['hr.payroll.view']],
            'payroll_payment', 'payroll_adjustment' => [$type === 'payroll_payment' ? 'Salary payment' : 'Payroll correction', $id ? site_url('hr/payroll/items/' . $id) : null, ['hr.payroll.view']],
            'employee_advance'   => ['Salary advance', site_url('hr/payroll'), ['hr.payroll.view']],
            'manual'             => ['Manual journal', $entryId ? site_url('finance/ledger/entries/' . $entryId) : null, ['finance.ledger.view']],
            'bank_statement'     => ['Booked from bank statement', $entryId ? site_url('finance/ledger/entries/' . $entryId) : null, ['finance.ledger.view']],
            default              => [ucwords(str_replace('_', ' ', $type)), null, []],
        };

        // only link to a source the viewer may open (e.g. an accountant sees "Payroll" as text)
        return [$label, $url !== null && can($perms) ? $url : null];
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

if (! function_exists('forget_secret_input')) {
    /**
     * After redirect()->withInput(): drops passwords, bank account numbers,
     * CIF and keys from the input kept in the session for refilling the
     * form (those fields are never refilled anyway).
     */
    function forget_secret_input(): void
    {
        $old = session()->getFlashdata('_ci_old_input');
        if (! is_array($old) || ! isset($old['post']) || ! is_array($old['post'])) {
            return;
        }
        $secret = ['password', 'password_confirm', 'pass_confirm', 'admin_password', 'account_number', 'cif', 'api_key', 'secret', 'client_secret', 'private_key', 'token', 'smtp_pass'];
        $old['post'] = array_diff_key($old['post'], array_flip($secret));
        session()->setFlashdata('_ci_old_input', $old);
    }
}
