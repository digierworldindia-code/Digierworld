<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessRuleException;
use App\Libraries\Dec;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Effective-dated salary structures. A revision is a new row with a new
 * effective date; history is never overwritten, so past payroll stays
 * explainable. Statutory deductions are configured separately and are
 * all disabled until HR/an accountant enables them with their own rates.
 */
class SalaryService
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** @return list<array<string, mixed>> newest first, each with 'components' */
    public function structures(int $employeeId): array
    {
        $rows = $this->db->table('salary_structures')->where('employee_id', $employeeId)->orderBy('effective_from', 'DESC')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['components'] = $this->db->table('salary_structure_components')->where('salary_structure_id', $r['id'])->orderBy('component_type')->orderBy('id')->get()->getResultArray();
        }

        return $rows;
    }

    /** Structure in force on $date (latest effective_from ≤ date), with components. */
    public function effective(int $employeeId, string $date): ?array
    {
        $s = $this->db->table('salary_structures')->where('employee_id', $employeeId)->where('effective_from <=', $date)->orderBy('effective_from', 'DESC')->get(1)->getRowArray();
        if ($s !== null) {
            $s['components'] = $this->db->table('salary_structure_components')->where('salary_structure_id', $s['id'])->get()->getResultArray();
        }

        return $s;
    }

    private static function money(mixed $v): ?string
    {
        $v = str_replace([',', '₹', ' '], '', trim((string) $v));

        return $v === '' ? null : (is_numeric($v) ? Dec::round($v) : 'invalid');
    }

    /** @param array<string, mixed> $in */
    public function save(int $employeeId, array $in): int
    {
        service('authz')->authorize('hr.salary.manage');
        $from   = ymd((string) ($in['effective_from'] ?? ''));
        $gross  = self::money($in['monthly_gross'] ?? '');
        $errors = [];
        if ($from === null) {
            $errors['effective_from'] = 'Choose the effective date.';
        }
        if ($gross === null || $gross === 'invalid' || ! Dec::isPositive($gross)) {
            $errors['monthly_gross'] = 'Enter the monthly gross (or daily rate) in rupees.';
        }
        foreach (['ctc_annual', 'overtime_rate_per_hour', 'tds_monthly'] as $k) {
            if (self::money($in[$k] ?? '') === 'invalid') {
                $errors[$k] = 'Enter a number.';
            }
        }
        $components = [];
        foreach ((array) ($in['components'] ?? []) as $c) {
            $code = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string) ($c['code'] ?? '')));
            $amt  = self::money($c['amount'] ?? '');
            if ($amt === null || ($code === '' && trim((string) ($c['label'] ?? '')) === '')) {
                continue;
            }
            if ($amt === 'invalid' || Dec::isNegative($amt) || $code === '') {
                $errors['components'] = 'Each breakdown row needs a code and a non-negative amount.';
                break;
            }
            $components[$code] = [
                'code' => mb_substr($code, 0, 20), 'label' => mb_substr(trim((string) ($c['label'] ?? $code)) ?: $code, 0, 80),
                'component_type' => ($c['component_type'] ?? '') === 'deduction' ? 'deduction' : 'earning', 'amount' => $amt, 'is_prorated' => empty($c['is_prorated']) ? 0 : 1,
            ];
        }
        $earnings = Dec::add('0', ...array_column(array_filter($components, static fn ($c) => $c['component_type'] === 'earning'), 'amount'));
        if (! isset($errors['monthly_gross']) && $components !== [] && Dec::isPositive($earnings) && Dec::cmp($earnings, $gross) !== 0 && ($in['salary_type'] ?? 'monthly') === 'monthly') {
            $errors['components'] = 'Earnings in the breakdown (' . inr($earnings) . ') must add up to the monthly gross (' . inr($gross) . ').';
        }
        if ($from !== null && $this->db->table('salary_structures')->where(['employee_id' => $employeeId, 'effective_from' => $from])->countAllResults() > 0) {
            $errors['effective_from'] = 'A structure with this effective date already exists — choose a new date for a revision.';
        }
        if ($from !== null && $this->db->table('payroll_runs')->where('is_locked', 1)->where('period_end >=', $from)->countAllResults() > 0) {
            $errors['effective_from'] = 'Payroll is already locked for this date. Revisions must start after the last finalized period.';
        }
        if ($errors !== []) {
            throw new BusinessRuleException(reset($errors), $errors);
        }

        $this->db->transBegin();
        try {
            $row = [
                'employee_id' => $employeeId, 'effective_from' => $from, 'salary_type' => ($in['salary_type'] ?? '') === 'daily' ? 'daily' : 'monthly',
                'ctc_annual' => self::money($in['ctc_annual'] ?? ''), 'monthly_gross' => $gross,
                'proration_basis' => in_array($in['proration_basis'] ?? '', ['calendar_days', 'fixed_30', 'working_days'], true) ? $in['proration_basis'] : 'calendar_days',
                'overtime_rate_per_hour' => self::money($in['overtime_rate_per_hour'] ?? ''),
                'pf_applicable' => empty($in['pf_applicable']) ? 0 : 1, 'esi_applicable' => empty($in['esi_applicable']) ? 0 : 1, 'pt_applicable' => empty($in['pt_applicable']) ? 0 : 1,
                'tds_monthly' => self::money($in['tds_monthly'] ?? '') ?? '0.00', 'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 500) ?: null,
                'created_by' => service('authz')->currentUserId(),
            ];
            $this->db->table('salary_structures')->insert($row);
            $id = (int) $this->db->insertID();
            foreach ($components as $c) {
                $this->db->table('salary_structure_components')->insert($c + ['salary_structure_id' => $id]);
            }
            service('audit')->log('salary.structure_saved', 'employee', $employeeId, null, ['effective_from' => $from, 'monthly_gross' => $gross, 'salary_type' => $row['salary_type']], 'Salary structure saved (effective ' . dmy($from) . ')');
            $this->db->transCommit();

            return $id;
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }

    /** @return array<string, array<string, mixed>> code => statutory config */
    public function statutory(): array
    {
        return array_column($this->db->table('statutory_configs')->get()->getResultArray(), null, 'code');
    }

    /** @param array<string, mixed> $in */
    public function saveStatutory(string $code, array $in): void
    {
        service('authz')->authorize('hr.payroll.statutory');
        $cfg = $this->statutory()[$code] ?? throw new BusinessRuleException('Unknown statutory item.');
        $num = static fn ($k) => ($v = trim((string) ($in[$k] ?? ''))) === '' ? null : (is_numeric($v) && (float) $v >= 0 ? $v : throw new BusinessRuleException('Rates and amounts must be non-negative numbers.'));
        $row = [
            'employee_rate' => $num('employee_rate'), 'employer_rate' => $num('employer_rate'), 'wage_ceiling' => $num('wage_ceiling'), 'fixed_amount' => $num('fixed_amount'),
            'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 500) ?: null, 'updated_by' => service('authz')->currentUserId(),
        ];
        $enable = ! empty($in['enabled']);
        if ($enable) {
            $needsRate = in_array($code, ['PF', 'ESI'], true);
            if (($needsRate && $row['employee_rate'] === null) || (in_array($code, ['PT', 'LWF'], true) && $row['fixed_amount'] === null)) {
                throw new BusinessRuleException('Enter the rate/amount confirmed by your accountant before enabling ' . $code . '. No default rates are assumed.');
            }
            if (empty($in['reviewed'])) {
                throw new BusinessRuleException('Tick “reviewed by HR/accountant” to enable ' . $code . '.');
            }
            $row['reviewed_by'] = service('authz')->currentUserId();
            $row['reviewed_at'] = date('Y-m-d H:i:s');
        }
        $row['enabled'] = $enable ? 1 : 0;
        $this->db->table('statutory_configs')->where('code', $code)->update($row);
        service('audit')->log('payroll.statutory_updated', 'statutory_config', $code, array_intersect_key($cfg, $row), $row, $code . ' settings ' . ($enable ? 'enabled' : 'saved (disabled)'));
    }
}
