<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Admin\BackupService;
use Throwable;

/** Create and download backups (security.backup.manage). Every download is audited. */
class BackupsController extends BaseController
{
    public function index()
    {
        $this->authorize('security.backup.manage');

        return $this->render('admin/backups', ['pageTitle' => 'Backups', 'breadcrumbs' => [['Administration'], ['Backups']], 'backups' => (new BackupService($this->db))->list()]);
    }

    public function create()
    {
        $this->authorize('security.backup.manage');
        try {
            $names = (new BackupService($this->db))->create((bool) $this->request->getPost('files'));
        } catch (Throwable $e) {
            return redirect()->back()->with('error', 'Backup failed: ' . $e->getMessage());
        }
        service('audit')->log('backup.created', 'backup', null, null, ['files' => $names], 'Backup created');

        return redirect()->to('admin/backups')->with('success', 'Backup created: ' . implode(', ', $names));
    }

    public function download($name)
    {
        $this->authorize('security.backup.manage');
        try {
            $path = (new BackupService($this->db))->path((string) $name);
        } catch (Throwable) {
            $this->notFound();
        }
        service('audit')->log('backup.downloaded', 'backup', null, null, ['file' => basename($path)], 'Backup downloaded');

        return $this->response->download($path, null)->setFileName(basename($path))->setHeader('Cache-Control', 'private, no-store');
    }
}
