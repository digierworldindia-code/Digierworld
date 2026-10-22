<?php

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Exceptions\BusinessRuleException;
use Config\Billing;

/**
 * Official brand assets. Files are stored byte-for-byte as uploaded (never
 * resized, recoloured or redrawn); each upload is a new version, earlier
 * versions remain for history and for already-issued documents.
 */
class BrandingController extends BaseController
{
    public const TYPES = [
        'logo'       => ['Official company logo', 'Top of every document. Stored exactly as uploaded.'],
        'signature'  => ['Authorised signature', 'Applied only by users allowed by the Signature Policy.'],
        'stamp'      => ['Rubber stamp / seal', 'Optional; governed by the Signature Policy.'],
        'sign_stamp' => ['Combined sign + stamp', 'Optional single image used instead of separate signature and stamp.'],
        'upi_qr'     => ['UPI payment QR', 'Printed in the payment block when enabled on the template.'],
        'letterhead' => ['Authorised letterhead', 'Optional header image for letters/estimates.'],
    ];

    public function index()
    {
        $assets = [];
        foreach ($this->db->table('branding_assets b')->select('b.*, f.original_name, f.size_bytes, f.sha256, f.mime_type, f.disk_path, p.full_name')
            ->join('stored_files f', 'f.id = b.stored_file_id')
            ->join('user_profiles p', 'p.user_id = b.created_by', 'left')
            ->orderBy('b.version', 'DESC')->get()->getResultArray() as $a) {
            $a['on_disk'] = service('files')->isOnDisk($a);
            $assets[$a['asset_type']][] = $a;
        }

        return $this->render('settings/branding', [
            'pageTitle'   => 'Branding assets',
            'breadcrumbs' => [['Settings'], ['Branding assets']],
            'types'       => self::TYPES,
            'assets'      => $assets,
            'docTypes'    => config(Billing::class)->docTypes + ['receipt' => ['label' => 'Receipt Voucher']],
            'banks'       => $this->db->table('bank_cash_accounts')->select('id, display_name')->where('account_kind !=', 'cash')->get()->getResultArray(),
            'wizard'      => $this->request->getGet('wizard') !== null,
        ]);
    }

    public function upload()
    {
        $type = (string) $this->request->getPost('asset_type');
        if (! isset(self::TYPES[$type])) {
            return redirect()->back()->with('error', 'Choose what you are uploading.');
        }
        $file = $this->request->getFile('file');
        if ($file === null) {
            return redirect()->back()->with('error', 'Choose an image file.');
        }

        try {
            $fileId = service('files')->storeUpload($file, 'image', $type);
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
        $stored = service('files')->find($fileId);
        $dims   = @getimagesize(service('files')->path($stored)) ?: [null, null];
        $prev   = $this->db->table('branding_assets')->where('asset_type', $type)->orderBy('version', 'DESC')->get()->getRowArray();
        $version = (int) ($prev['version'] ?? 0) + 1;

        $this->db->transBegin();
        try {
            $this->db->table('branding_assets')->where(['asset_type' => $type, 'is_active' => 1])->update(['is_active' => 0, 'replaced_at' => date('Y-m-d H:i:s')]);
            $this->db->table('branding_assets')->insert([
                'asset_type'        => $type,
                'version'           => $version,
                'stored_file_id'    => $fileId,
                'width_px'          => $dims[0],
                'height_px'         => $dims[1],
                'position_preset'   => $prev['position_preset'] ?? ($type === 'logo' ? 'top_left' : 'bottom_right'),
                'display_width_mm'  => $prev['display_width_mm'] ?? ($type === 'logo' ? '45.0' : '35.0'),
                'enabled_doc_types' => $prev['enabled_doc_types'] ?? json_encode(array_keys(config(Billing::class)->docTypes)),
                'bank_account_id'   => $prev['bank_account_id'] ?? null,
                'is_active'         => 1,
                'created_by'        => $this->userId,
            ]);
            $assetId = (int) $this->db->insertID();
            $this->db->table('stored_files')->where('id', $fileId)->update(['entity_type' => 'branding_asset', 'entity_id' => $assetId]);
            service('audit')->log('branding.uploaded', 'branding_asset', $assetId, $prev ? ['version' => $prev['version']] : null, ['type' => $type, 'version' => $version, 'sha256' => $stored['sha256']], self::TYPES[$type][0] . ' v' . $version . ' uploaded');
            if ($type === 'logo') {
                service('settingsStore')->markSetupStep('branding');
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return redirect()->back()->with('success', self::TYPES[$type][0] . ' uploaded as version ' . $version . ' (stored unchanged; previous version kept in history).');
    }

    public function update($id)
    {
        $asset = $this->db->table('branding_assets')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();
        $presets = ['top_left', 'top_center', 'top_right', 'bottom_left', 'bottom_center', 'bottom_right'];
        $types   = array_values(array_intersect((array) $this->request->getPost('enabled_doc_types'), array_merge(array_keys(config(Billing::class)->docTypes), ['receipt'])));
        $data    = [
            'position_preset'   => in_array($this->post('position_preset'), $presets, true) ? $this->post('position_preset') : $asset['position_preset'],
            'display_width_mm'  => (string) max(10, min(120, (float) $this->post('display_width_mm', $asset['display_width_mm']))),
            'enabled_doc_types' => json_encode($types),
            'bank_account_id'   => $asset['asset_type'] === 'upi_qr' && $this->post('bank_account_id') ? (int) $this->post('bank_account_id') : null,
            'updated_by'        => $this->userId,
        ];
        $activate = $this->request->getPost('is_active') ? 1 : 0;
        $this->db->transBegin();
        try {
            if ($activate && ! $asset['is_active']) {
                $this->db->table('branding_assets')->where(['asset_type' => $asset['asset_type'], 'is_active' => 1])->update(['is_active' => 0, 'replaced_at' => date('Y-m-d H:i:s')]);
            }
            $data['is_active'] = $activate;
            $this->db->table('branding_assets')->where('id', $asset['id'])->update($data);
            service('audit')->log('branding.updated', 'branding_asset', (int) $asset['id'], array_intersect_key($asset, $data), $data, 'Brand asset settings changed');
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return redirect()->back()->with('success', 'Asset settings saved.');
    }

    public function preview($id)
    {
        $asset = $this->db->table('branding_assets')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();
        $file  = service('files')->find((int) $asset['stored_file_id']) ?? $this->notFound();
        $path  = service('files')->existingPath($file) ?? $this->notFound();

        return $this->response->setHeader('Content-Type', $file['mime_type'])->setHeader('Cache-Control', 'private, no-store')
            ->setBody((string) file_get_contents($path));
    }
}
