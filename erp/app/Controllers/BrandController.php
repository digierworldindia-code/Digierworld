<?php

namespace App\Controllers;

/**
 * Serves the active official logo (unchanged original bytes) to signed-in
 * users and the login page. Signatures/stamps are NOT served here.
 */
class BrandController extends BaseController
{
    public function logo()
    {
        $asset = $this->db->table('branding_assets')->where(['asset_type' => 'logo', 'is_active' => 1])
            ->orderBy('version', 'DESC')->get()->getRowArray();
        $file = $asset ? service('files')->find((int) $asset['stored_file_id']) : null;
        $path = $file ? service('files')->existingPath($file) : null;
        if ($path === null) {
            return $this->response->setStatusCode(404);
        }

        return $this->response
            ->setHeader('Content-Type', $file['mime_type'])
            ->setHeader('Cache-Control', 'private, max-age=3600')
            ->setHeader('ETag', '"' . $file['sha256'] . '"')
            ->setBody((string) file_get_contents($path));
    }
}
