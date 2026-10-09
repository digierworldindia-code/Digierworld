<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\DocumentModel;

/**
 * Authorized streaming of private documents (never served from the webroot).
 */
class Documents extends BaseController
{
    public function download(string $uuid)
    {
        $doc = model(DocumentModel::class)->where('uuid', $uuid)->first();
        $viewer = service('visibility')->current();
        if (! $doc || ! service('documents')->canAccess($doc, $viewer)) {
            service('audit')->security('document.denied', 'Denied document ' . $uuid, ['entity_type' => 'document', 'entity_id' => $doc['id'] ?? null, 'company_id' => $viewer->companyId()]);
            $this->notFound();
        }
        $path = service('documents')->absolutePath($doc);
        service('audit')->log('document.downloaded', ['entity_type' => 'document', 'entity_id' => (int) $doc['id'], 'company_id' => $viewer->companyId()]);
        $inline = in_array($doc['mime_type'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true) && $this->request->getGet('download') === null;

        return $this->response
            ->setHeader('Content-Type', $doc['mime_type'])
            ->setHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $doc['original_name']) . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody((string) file_get_contents($path));
    }
}
