<?php

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use Config\Billing;

/**
 * Who may apply the authorised signature/stamp, per role (or user) and
 * document type. Changes require an Owner/Super Admin (owner approval).
 * Applying a signature additionally needs billing.apply_signature, and every
 * use is logged in signature_usage_log.
 */
class SignaturePolicyController extends BaseController
{
    public function index()
    {
        $policies = [];
        foreach ($this->db->table('signature_policies')->get()->getResultArray() as $p) {
            $policies[$p['role_id'] ? 'r' . $p['role_id'] : 'u' . $p['user_id']][$p['doc_type']] = $p;
        }

        return $this->render('settings/signature_policy', [
            'pageTitle'   => 'Signature & stamp policy',
            'breadcrumbs' => [['Settings'], ['Signature policy']],
            'roles'       => $this->db->table('roles')->where('is_active', 1)->orderBy('is_super', 'DESC')->orderBy('name')->get()->getResultArray(),
            'users'       => $this->db->table('users u')->select('u.id, p.full_name')->join('user_profiles p', 'p.user_id = u.id')->where('u.active', 1)->where('p.is_service_account', 0)->orderBy('p.full_name')->get()->getResultArray(),
            'docTypes'    => array_filter(config(Billing::class)->docTypes, static fn ($d) => $d['posts'] || in_array($d['perm'], ['estimate'], true)) + ['receipt' => ['label' => 'Receipt Voucher']],
            'policies'    => $policies,
            'canApprove'  => $this->authz->isSuper(),
            'signers'     => $this->db->query("SELECT DISTINCT p.full_name FROM users u JOIN user_profiles p ON p.user_id = u.id
                                 WHERE u.active = 1 AND (EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.is_super = 1)
                                 OR EXISTS (SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role_id = ur.role_id JOIN permissions pm ON pm.id = rp.permission_id WHERE ur.user_id = u.id AND pm.perm_key = 'billing.apply_signature')
                                 OR EXISTS (SELECT 1 FROM user_permission_overrides o JOIN permissions pm ON pm.id = o.permission_id WHERE o.user_id = u.id AND o.effect = 'grant' AND pm.perm_key = 'billing.apply_signature'))")->getResultArray(),
            'usage'       => $this->db->table('signature_usage_log s')->select('s.used_at, s.assets, d.document_number, d.doc_type, p.full_name')
                ->join('billing_documents d', 'd.id = s.document_id')->join('user_profiles p', 'p.user_id = s.user_id', 'left')
                ->orderBy('s.id', 'DESC')->limit(20)->get()->getResultArray(),
            'wizard'      => $this->request->getGet('wizard') !== null,
        ]);
    }

    public function update()
    {
        if (! $this->authz->isSuper()) {
            return redirect()->back()->with('error', 'Only an Owner / Super Admin can approve signature policies.');
        }
        $docTypes = array_merge(array_keys(config(Billing::class)->docTypes), ['receipt']);
        $allowed  = (array) $this->request->getPost('allow'); // allow[r12][tax_invoice]=1 / allow[u5][cash_memo]=1
        $before   = $this->db->table('signature_policies')->get()->getResultArray();
        $now      = date('Y-m-d H:i:s');

        $this->db->transBegin();
        try {
            $this->db->table('signature_policies')->where('1 = 1', null, false)->delete();
            foreach ($allowed as $subject => $types) {
                if (! preg_match('/^([ru])(\d+)$/', (string) $subject, $m)) {
                    continue;
                }
                foreach (array_keys((array) $types) as $docType) {
                    if (! in_array($docType, $docTypes, true)) {
                        continue;
                    }
                    $this->db->table('signature_policies')->insert([
                        'role_id'     => $m[1] === 'r' ? (int) $m[2] : null,
                        'user_id'     => $m[1] === 'u' ? (int) $m[2] : null,
                        'doc_type'    => $docType,
                        'allowed'     => 1,
                        'approved_by' => $this->userId,
                        'approved_at' => $now,
                        'created_by'  => $this->userId,
                    ]);
                }
            }
            $after = $this->db->table('signature_policies')->get()->getResultArray();
            service('audit')->log('signature_policy.updated', 'signature_policy', null, ['rules' => count($before)], ['rules' => count($after)], 'Signature policy approved by owner (' . count($after) . ' rules)');
            service('settingsStore')->markSetupStep('signature');
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return redirect()->back()->with('success', 'Signature policy saved and approved by you. Users also need the billing.apply_signature permission.');
    }
}
