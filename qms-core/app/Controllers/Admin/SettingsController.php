<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Exceptions\ValidationException;
use App\Services\Settings\SettingsValidator;
use Throwable;

class SettingsController extends BaseController
{
    private const GROUPS = [
        'company'    => 'Company',
        'documents'  => 'Document numbering',
        'regional'   => 'Regional',
        'workflow'   => 'Approval workflow',
        'security'   => 'Security',
        'gauge'      => 'Gauges',
        'inspection' => 'Inspection',
        'google'     => 'Google Sheets',
    ];

    public function index(): string
    {
        $settings = service('settings');
        $groups   = [];
        foreach (self::GROUPS as $key => $label) {
            $groups[$key] = ['label' => $label, 'rows' => $settings->group($key)];
        }

        return $this->render('admin/settings/index', [
            'title'       => 'Settings',
            'groups'      => $groups,
            'options'     => SettingsValidator::OPTIONS,
            'active'      => (string) (session()->getFlashdata('tab') ?? $this->request->getGet('tab') ?? 'company'),
            'errors'      => session()->getFlashdata('errors') ?? [],
            'reportTypes' => db_connect()->table('report_types')->orderBy('id')->get()->getResultArray(),
            'google'      => service('sheetsClient')->describeCredentials(),
            'nextNumber'  => service('documentNumbers')->preview(),
        ]);
    }

    public function update(string $group)
    {
        if (! isset(self::GROUPS[$group])) {
            return redirect()->to(site_url('admin/settings'))->with('error', 'Unknown settings group.');
        }

        try {
            $settings = service('settings');
            $keys     = array_column($settings->group($group), 'setting_key');
            $values   = (new SettingsValidator())->validate($this->request->getPost(), $keys);
            $settings->update($group, $values, $this->currentUser()['id'], service('audit'), service('clock')->nowUtcString());
        } catch (Throwable $e) {
            return $this->backWithError($e)->with('tab', $group);
        }

        return redirect()->to(site_url('admin/settings?tab=' . $group))->with('success', self::GROUPS[$group] . ' settings saved.');
    }

    public function logo()
    {
        $rules = ['logo' => 'uploaded[logo]|max_size[logo,' . config('Qms')->logoMaxKb . ']|is_image[logo]|mime_in[logo,image/png,image/jpeg]|ext_in[logo,png,jpg,jpeg]'];
        if (! $this->validate($rules)) {
            return redirect()->to(site_url('admin/settings?tab=company'))->with('error', implode(' ', $this->validator->getErrors()));
        }

        try {
            $settings = service('settings');
            $old      = (string) $settings->get('company.logo', '');
            $name     = service('uploads')->storeLogo($this->request->getFile('logo'));
            db_connect()->table('system_settings')->where('setting_key', 'company.logo')->update([
                'setting_value' => $name, 'updated_at' => service('clock')->nowUtcString(), 'updated_by' => $this->currentUser()['id'],
            ]);
            service('audit')->log('UPDATE', 'settings', null, ['company.logo' => $old], ['company.logo' => $name], 'company');
            $settings->refresh();
            if ($old !== '') {
                service('uploads')->delete('logo', $old);
            }
        } catch (Throwable $e) {
            return $this->backWithError($e)->with('tab', 'company');
        }

        return redirect()->to(site_url('admin/settings?tab=company'))->with('success', 'Logo updated.');
    }

    public function reportType(int $id)
    {
        try {
            $db     = db_connect();
            $before = $db->table('report_types')->where('id', $id)->get(1)->getRowArray();
            if ($before === null) {
                throw ValidationException::single('report_type', 'Unknown report type.');
            }
            $prefix = strtoupper(trim((string) $this->request->getPost('doc_prefix')));
            if (! preg_match('/^[A-Z0-9]{2,10}$/', $prefix)) {
                throw ValidationException::single('doc_prefix', 'Prefix: 2-10 capital letters or digits.');
            }
            $after = [
                'doc_prefix'                       => $prefix,
                'requires_production_verification' => $this->request->getPost('requires_production_verification') === '1' ? 1 : 0,
                'requires_quality_verification'    => $this->request->getPost('requires_quality_verification') === '1' ? 1 : 0,
                'requires_qa_approval'             => $this->request->getPost('requires_qa_approval') === '1' ? 1 : 0,
            ];
            if ($after['requires_production_verification'] + $after['requires_quality_verification'] + $after['requires_qa_approval'] === 0) {
                throw ValidationException::single('stages', 'Keep at least one approval stage switched on.');
            }
            service('transactions')->run(function ($db) use ($id, $before, $after): void {
                $db->table('report_types')->where('id', $id)->update($after + ['updated_at' => service('clock')->nowUtcString(), 'updated_by' => $this->currentUser()['id']]);
                service('audit')->log('UPDATE', 'report_type', $id, array_intersect_key($before, $after), $after, (string) $before['code']);
            });
        } catch (Throwable $e) {
            return $this->backWithError($e)->with('tab', 'workflow');
        }

        return redirect()->to(site_url('admin/settings?tab=workflow'))->with('success', 'Report type saved. In-flight reports keep their current stage.');
    }

    public function googleTest()
    {
        $id = (string) service('settings')->get('google.spreadsheet_id', '');
        try {
            if ($id === '') {
                throw ValidationException::single('google.spreadsheet_id', 'Enter and save the spreadsheet ID first.');
            }
            $title = service('sheetsClient')->spreadsheetTitle($id);
            service('audit')->log('TEST', 'sync', null, null, ['spreadsheet' => $id, 'result' => 'ok']);
        } catch (Throwable $e) {
            $message = $e instanceof ValidationException ? $e->getMessage() : 'Connection failed: ' . mb_substr(\App\Services\Sync\SheetSyncWorker::sanitize($e), 0, 300);

            return redirect()->to(site_url('admin/settings?tab=google'))->with('error', $message);
        }

        return redirect()->to(site_url('admin/settings?tab=google'))->with('success', "Connected. Spreadsheet title: \"{$title}\".");
    }
}
