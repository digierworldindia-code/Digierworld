<?php

namespace App\Controllers;

use App\Services\Files\FileAccessPolicy;

/** Permission-checked download of private files (never served from webroot). */
class FilesController extends BaseController
{
    public function download($id)
    {
        $file = service('files')->find($this->idOr404($id));
        if ($file === null) {
            $this->notFound();
        }
        $policy = new FileAccessPolicy($this->db, $this->authz);
        if (! $policy->canDownload($file)) {
            service('audit')->log('file.download_denied', 'stored_file', (int) $file['id'], null, null, 'File download denied');
            $this->deny();
        }
        if ($file['category'] === 'report_export') {
            $this->db->table('report_exports')->where('stored_file_id', $file['id'])->update(['downloaded_at' => date('Y-m-d H:i:s')]);
        }
        if (in_array($file['category'], ['cv', 'candidate', 'employee', 'payslip'], true)) {
            service('audit')->log('file.downloaded', 'stored_file', (int) $file['id'], null, null, 'Sensitive file downloaded', ['category' => $file['category']]);
        }
        $inline = in_array($file['mime_type'], ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'], true) && $this->request->getGet('download') === null;
        $name   = preg_replace('/[^\w.\- ]+/', '_', $file['original_name']);

        return $this->response
            ->setHeader('Content-Type', $file['mime_type'])
            ->setHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody((string) file_get_contents(service('files')->path($file)));
    }
}
