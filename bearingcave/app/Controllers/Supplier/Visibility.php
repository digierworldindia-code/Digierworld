<?php

declare(strict_types=1);

namespace App\Controllers\Supplier;

use App\Controllers\BaseController;
use App\Models\CompanyCountryRuleModel;

/**
 * Supplier-wide country visibility (allowed or excluded countries).
 */
class Visibility extends BaseController
{
    public function index(): string
    {
        $rules = model(CompanyCountryRuleModel::class)->where('company_id', $this->company()['id'])->findAll();

        return view('supplier/visibility', [
            'title' => 'Country visibility', 'area' => 'supplier', 'company' => $this->company(), 'entitled' => entitled('country_visibility_control'),
            'mode' => $rules ? ($rules[0]['rule'] === 'allow' ? 'allow' : 'deny') : 'all', 'selected' => array_column($rules, 'country_code'),
        ]);
    }

    public function save()
    {
        $mode = (string) $this->request->getPost('mode');
        $list = array_values(array_filter((array) $this->request->getPost('countries'), static fn ($c) => preg_match('/^[A-Z]{2}$/', (string) $c)));
        if (! in_array($mode, ['all', 'allow', 'deny'], true)) {
            return redirect()->back()->with('error', 'Invalid option.');
        }
        if ($mode !== 'all' && $list === []) {
            return redirect()->back()->with('error', 'Select at least one country.');
        }
        $m   = model(CompanyCountryRuleModel::class);
        $cid = (int) $this->company()['id'];
        $db  = db_connect();
        $db->transBegin();

        try {
            $m->where('company_id', $cid)->delete();
            if ($mode !== 'all') {
                foreach (array_unique($list) as $cc) {
                    $m->insert(['company_id' => $cid, 'country_code' => $cc, 'rule' => $mode]);
                }
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
        service('audit')->log('visibility.company_rules', ['company_id' => $cid, 'entity_type' => 'company', 'entity_id' => $cid, 'description' => $mode . ': ' . implode(',', $list)]);

        return redirect()->back()->with('success', 'Country visibility saved. It applies to all your listings immediately, in addition to per-listing rules.');
    }
}
