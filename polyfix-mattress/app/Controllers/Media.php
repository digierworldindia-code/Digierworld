<?php

namespace App\Controllers;

use App\Exceptions\AppException;
use App\Libraries\DealerScope;
use App\Services\MediaService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Claim photographs and videos. They live under writable/, outside the web
 * root, and are only ever served through here: staff need claim:read, a
 * dealer only sees media on their own claims. A file that is not yours is a
 * plain 404.
 */
class Media extends BaseController
{
    public function show(string $id): ResponseInterface
    {
        $row = db_connect()->table('claim_media')->where('id', $id)->get()->getRowArray();

        try {
            if ($this->ctx->isDealer()) {
                DealerScope::current()->assertOwns('claim_media', $row, 'file');
            } elseif (! $this->ctx->can('claim:media:view') || $row === null) {
                $this->notFound();
            }
            $path = (new MediaService(config('Polyfix')))->absolute($row['storage_key']);
        } catch (AppException|\RuntimeException) {
            $this->notFound();
        }

        if (! is_file($path)) {
            log_message('error', 'media.MISSING id={id}', ['id' => $id]);
            $this->notFound();
        }

        // The stored MIME type was decided from the file's bytes at upload.
        return $this->response
            ->setHeader('Content-Type', $row['mime_type'])
            ->setHeader('Content-Length', (string) filesize($path))
            ->setHeader('Content-Disposition', 'inline; filename="' . basename($path) . '"')
            ->setHeader('Cache-Control', 'private, max-age=600')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Security-Policy', "default-src 'none'; img-src 'self'; media-src 'self'; sandbox")
            ->setBody(file_get_contents($path));
    }
}
