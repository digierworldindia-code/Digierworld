<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Exceptions\BusinessRuleException;
use App\Services\Ai\AiConfig;

/**
 * AI Permissions: provider status, per-capability switches, field
 * allowlists, sharing/consent/retention, rate limits and cost ceilings,
 * service tokens with TTL and record scope, monthly usage and action logs.
 */
class AiController extends BaseController
{
    public function index()
    {
        $this->authorize(['ai.permissions.manage', 'ai.usage.view']);
        $cfg    = service('aiConfig');
        $caps   = $this->db->table('ai_capabilities')->orderBy('code')->get()->getResultArray();
        $month  = date('Y-m-01 00:00:00');
        $usage  = array_column($this->db->query("SELECT capability, SUM(outcome = 'allowed') requests, SUM(outcome = 'completed') completed, SUM(outcome IN ('error','fallback')) errors,
                SUM(outcome = 'denied') denied, COALESCE(SUM(input_tokens), 0) tin, COALESCE(SUM(output_tokens), 0) tout, COALESCE(SUM(cost_estimate), 0) cost
            FROM ai_action_logs WHERE occurred_at >= ? GROUP BY capability", [$month])->getResultArray(), null, 'capability');
        $f = ['outcome' => (string) $this->request->getGet('outcome'), 'capability' => (string) $this->request->getGet('capability')];
        $logs = $this->db->table('ai_action_logs l')->select('l.*, p.full_name AS actor')->join('user_profiles p', 'p.user_id = l.actor_user_id', 'left');
        if (in_array($f['outcome'], ['allowed', 'denied', 'error', 'completed', 'fallback'], true)) {
            $logs->where('l.outcome', $f['outcome']);
        }
        if ($f['capability'] !== '') {
            $logs->where('l.capability', $f['capability']);
        }

        return $this->render('admin/ai', [
            'pageTitle' => 'AI Permissions', 'breadcrumbs' => [['Administration'], ['AI Permissions']],
            'provider' => ['name' => AiConfig::PROVIDERS[$cfg->provider()] ?? 'None', 'model' => $cfg->model(), 'hasKey' => $cfg->hasKey(), 'configured' => $cfg->isConfigured(),
                'timeout' => $cfg->timeout(), 'retries' => $cfg->maxRetries(), 'effort' => $cfg->effort(), 'baseUrl' => $cfg->baseUrl()],
            'caps' => $caps, 'meta' => config('Permissions')->aiCapabilities, 'usage' => $usage,
            'tokens' => $this->db->table('ai_service_tokens t')->select('t.*, p.full_name AS creator')->join('user_profiles p', 'p.user_id = t.created_by', 'left')->orderBy('t.id', 'DESC')->limit(30)->get()->getResultArray(),
            'logs' => $logs->orderBy('l.id', 'DESC')->limit(100)->get()->getResultArray(), 'f' => $f,
            'newToken' => session()->getFlashdata('ai_token'), 'models' => array_keys(AiConfig::PRICES),
            'canManage' => $this->can('ai.permissions.manage'),
        ]);
    }

    public function saveProvider()
    {
        $this->authorize('ai.permissions.manage');
        $s     = service('settingsStore');
        $model = trim((string) $this->request->getPost('model'));
        if ($model !== '' && ! preg_match('/^[a-z0-9.\-]{3,80}$/', $model)) {
            return redirect()->back()->with('error', 'Model id looks invalid.');
        }
        $s->setIntegration('ai', 'provider', isset(AiConfig::PROVIDERS[$this->request->getPost('provider')]) ? (string) $this->request->getPost('provider') : 'anthropic');
        $s->setIntegration('ai', 'model', $model ?: AiConfig::DEFAULT_MODEL);
        $s->setIntegration('ai', 'timeout', (string) max(10, min(300, (int) $this->request->getPost('timeout'))));
        $s->setIntegration('ai', 'max_retries', (string) max(0, min(4, (int) $this->request->getPost('max_retries'))));
        $s->setIntegration('ai', 'effort', in_array($this->request->getPost('effort'), ['low', 'medium', 'high'], true) ? (string) $this->request->getPost('effort') : 'medium');
        service('audit')->log('ai.provider_updated', 'integration', 'ai', null, ['model' => $model], 'AI provider settings updated');

        return redirect()->to('admin/ai')->with('success', 'Provider settings saved. The API key is read from .env (ai.apiKey) only.');
    }

    public function saveCapability($code)
    {
        $this->authorize('ai.permissions.manage');
        $cap = $this->db->table('ai_capabilities')->where('code', (string) $code)->get()->getRowArray() ?? $this->notFound();
        $known = config('Permissions')->aiCapabilities[$cap['code']]['fields'] ?? [];
        $fields = array_values(array_intersect((array) $this->request->getPost('fields'), $known));
        $scope  = null;
        $empIds = array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', (string) $this->request->getPost('scope_employee_ids')) ?: [])));
        if (str_starts_with($cap['code'], 'ai.payroll.') && $empIds !== []) {
            $scope = json_encode(['employee_ids' => $empIds]);
        }
        $row = [
            'enabled' => $this->request->getPost('enabled') ? 1 : 0, 'allowed_fields' => json_encode($fields), 'scope_config' => $scope,
            'share_with_provider' => $this->request->getPost('share_with_provider') ? 1 : 0, 'requires_consent' => $this->request->getPost('requires_consent') ? 1 : 0,
            'rate_limit_per_hour' => max(0, min(1000, (int) $this->request->getPost('rate_limit_per_hour'))),
            'monthly_cost_ceiling' => number_format(max(0, (float) $this->request->getPost('monthly_cost_ceiling')), 2, '.', ''),
            'retention_days' => max(1, min(3650, (int) $this->request->getPost('retention_days'))), 'updated_by' => $this->userId,
        ];
        if (str_starts_with($cap['code'], 'ai.recruitment.') && ! $row['requires_consent']) {
            return redirect()->back()->with('error', 'Recruitment AI capabilities always require candidate consent.');
        }
        if ($row['enabled'] && $fields === []) {
            return redirect()->back()->with('error', 'Allow at least one field before enabling a capability.');
        }
        $this->db->table('ai_capabilities')->where('code', $cap['code'])->update($row);
        service('audit')->log('ai.capability_updated', 'ai_capability', $cap['code'], array_intersect_key($cap, $row), $row, 'AI capability ' . $cap['code'] . ($row['enabled'] ? ' enabled' : ' disabled'));

        return redirect()->to('admin/ai')->with('success', 'Capability saved.');
    }

    public function issueToken()
    {
        $this->authorize('ai.permissions.manage');
        $ids   = static fn ($v) => array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,]+/', (string) $v) ?: []))));
        $scope = array_filter([
            'candidate_ids' => $ids($this->request->getPost('candidate_ids')), 'employee_ids' => $ids($this->request->getPost('employee_ids')),
            'periods' => array_values(array_filter(preg_split('/[\s,]+/', (string) $this->request->getPost('periods')) ?: [], static fn ($p) => preg_match('/^\d{4}-\d{2}$/', $p))),
        ]);
        try {
            [$plain] = service('aiGateway')->issueToken((array) $this->request->getPost('capabilities'), $scope, (string) $this->request->getPost('purpose'),
                (int) $this->request->getPost('ttl_hours') ?: 4, (int) $this->request->getPost('max_uses') ?: null);
        } catch (BusinessRuleException $e) {
            return $this->backWithRuleError($e);
        }

        return redirect()->to('admin/ai#tokens')->with('ai_token', $plain)->with('success', 'Token issued. Copy it now — it is shown only once.');
    }

    public function revokeToken($id)
    {
        $this->authorize('ai.permissions.manage');
        service('aiGateway')->revokeToken($this->idOr404($id));

        return redirect()->to('admin/ai#tokens')->with('success', 'Token revoked immediately.');
    }
}
