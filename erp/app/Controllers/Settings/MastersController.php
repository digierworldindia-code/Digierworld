<?php

namespace App\Controllers\Settings;

use App\Controllers\BaseController;

/**
 * Config-driven editor for simple master lists. Each list declares its own
 * permission, fields and validation; rows are never hard-deleted (deactivate).
 */
class MastersController extends BaseController
{
    /** @return array<string, array<string, mixed>> */
    private function defs(): array
    {
        $ledgers = array_column($this->db->table('ledger_accounts')->select('id, code, name')->where('account_group', 'expense')->orderBy('code')->get()->getResultArray(), null, 'id');
        $itc     = array_column($this->db->table('itc_rules')->select('id, name')->get()->getResultArray(), 'name', 'id');
        $depts   = array_column($this->db->table('departments')->select('id, name')->orderBy('name')->get()->getResultArray(), 'name', 'id');

        return [
            'lead_sources' => ['label' => 'Lead sources', 'perm' => 'crm.settings.manage', 'order' => 'name', 'fields' => [
                'name' => ['Name', 'text', true], 'is_active' => ['Active', 'bool'],
            ]],
            'lost_reasons' => ['label' => 'Lost reasons', 'perm' => 'crm.settings.manage', 'order' => 'name', 'fields' => [
                'name' => ['Reason', 'text', true], 'is_active' => ['Active', 'bool'],
            ]],
            'crm_stages' => ['label' => 'CRM pipeline stages', 'perm' => 'crm.settings.manage', 'order' => 'sort_order', 'fields' => [
                'code' => ['Code', 'code', true], 'name' => ['Name', 'text', true], 'sort_order' => ['Order', 'int'], 'color' => ['Colour', 'color'],
                'is_won' => ['Won', 'bool'], 'is_lost' => ['Lost', 'bool'], 'is_closed' => ['Closed', 'bool'], 'is_dnd' => ['DNC', 'bool'], 'is_active' => ['Active', 'bool'],
            ]],
            'expense_categories' => ['label' => 'Expense categories', 'perm' => 'finance.expense.manage_categories', 'order' => 'name', 'fields' => [
                'code' => ['Code', 'code', true], 'name' => ['Name', 'text', true],
                'ledger_account_id' => ['Ledger', 'select', true, array_map(static fn ($l) => $l['code'] . ' ' . $l['name'], $ledgers)],
                'default_itc_rule_id' => ['Default ITC rule', 'select', false, $itc], 'is_active' => ['Active', 'bool'],
            ]],
            'itc_rules' => ['label' => 'ITC eligibility rules (accountant-reviewed)', 'perm' => 'finance.expense.manage_categories', 'order' => 'id', 'fields' => [
                'name' => ['Rule', 'text', true], 'description' => ['Description', 'text'], 'eligible' => ['Eligible', 'bool'],
                'eligible_percent' => ['Eligible %', 'decimal'], 'block_reference' => ['Legal reference', 'text'],
                'review_status' => ['Review', 'select', true, ['suggested' => 'Suggested', 'accountant_reviewed' => 'Accountant reviewed']], 'is_active' => ['Active', 'bool'],
            ]],
            'departments' => ['label' => 'Departments', 'perm' => 'hr.employee.manage', 'order' => 'name', 'fields' => [
                'name' => ['Name', 'text', true], 'is_active' => ['Active', 'bool'],
            ]],
            'designations' => ['label' => 'Designations', 'perm' => 'hr.employee.manage', 'order' => 'name', 'fields' => [
                'name' => ['Name', 'text', true], 'department_id' => ['Department', 'select', false, $depts], 'is_active' => ['Active', 'bool'],
            ]],
            'leave_types' => ['label' => 'Leave types & entitlements', 'perm' => 'hr.leave.manage_types', 'order' => 'code', 'fields' => [
                'code' => ['Code', 'code', true], 'name' => ['Name', 'text', true], 'is_paid' => ['Paid', 'bool'], 'annual_quota' => ['Annual days', 'decimal'],
                'carry_forward' => ['Carry fwd', 'bool'], 'max_carry' => ['Max carry', 'decimal'], 'is_active' => ['Active', 'bool'],
            ]],
        ];
    }

    public function index()
    {
        $lists = array_filter($this->defs(), fn ($d) => $this->can($d['perm']));

        return $this->render('settings/masters/index', ['pageTitle' => 'Master lists', 'breadcrumbs' => [['Settings'], ['Master lists']], 'lists' => $lists]);
    }

    public function list($table)
    {
        $def = $this->def((string) $table);

        return $this->render('settings/masters/list', [
            'pageTitle'   => $def['label'],
            'breadcrumbs' => [['Settings'], ['Master lists', 'settings/masters'], [$def['label']]],
            'table'       => $table,
            'def'         => $def,
            'rows'        => $this->db->table($table)->orderBy($def['order'])->get()->getResultArray(),
        ]);
    }

    public function save($table, $id = null)
    {
        $def    = $this->def((string) $table);
        $before = $id ? ($this->db->table($table)->where('id', (int) $id)->get()->getRowArray() ?? $this->notFound()) : null;
        $data   = [];
        foreach ($def['fields'] as $field => $f) {
            $type = $f[1];
            $raw  = $this->request->getPost($field);
            $val  = match ($type) {
                'bool'    => $raw ? 1 : 0,
                'int'     => (int) $raw,
                'decimal' => number_format((float) $raw, 2, '.', ''),
                'color'   => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $raw) ? $raw : '#6c757d',
                'code'    => strtolower(trim(preg_replace('/[^a-z0-9_]+/i', '_', (string) $raw), '_')),
                'select'  => ($raw === '' || $raw === null) ? null : (isset($f[3][$raw]) ? $raw : '__invalid__'),
                default   => trim((string) $raw),
            };
            if ($type === 'code' && $table === 'expense_categories' || $type === 'code' && $table === 'leave_types') {
                $val = strtoupper($val);
            }
            if ($val === '__invalid__') {
                return redirect()->back()->with('error', $f[0] . ': invalid choice.');
            }
            if (! empty($f[2]) && ($val === '' || $val === null)) {
                return redirect()->back()->with('error', $f[0] . ' is required.');
            }
            if ($before !== null && $type === 'code' && in_array($table, ['crm_stages'], true)) {
                $val = $before[$field]; // stage codes are referenced by logic — immutable
            }
            $data[$field] = $val;
        }
        try {
            if ($before === null) {
                $this->db->table($table)->insert($data + ['created_by' => $this->userId]);
                $id = (int) $this->db->insertID();
            } else {
                $this->db->table($table)->where('id', $before['id'])->update($data + ['updated_by' => $this->userId]);
            }
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', str_contains($e->getMessage(), 'Duplicate') ? 'That name/code already exists.' : 'Could not save: ' . $e->getMessage());
        }
        service('audit')->log('master.' . ($before ? 'updated' : 'created'), $table, (int) $id, $before, $data, $def['label'] . ' saved');

        return redirect()->back()->with('success', 'Saved.');
    }

    /** @return array<string, mixed> */
    private function def(string $table): array
    {
        $def = $this->defs()[$table] ?? $this->notFound();
        $this->authorize($def['perm']);

        return $def;
    }
}
