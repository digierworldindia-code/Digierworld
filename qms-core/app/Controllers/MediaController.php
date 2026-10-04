<?php

namespace App\Controllers;

use App\Core\HttpException;

/**
 * Serves the company logo from storage/uploads (never a direct file URL).
 * Calibration certificates are served by GaugeController with a permission check.
 */
class MediaController extends BaseController
{
    public function logo()
    {
        $name = (string) qms_setting('company.logo', '');
        $file = service('uploads')->path('logo', $name);

        if ($name === '' || $file === null) {
            throw HttpException::notFound();
        }

        return $this->response
            ->setHeader('Content-Type', 'image/png')
            ->setHeader('Cache-Control', 'public, max-age=86400')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody((string) file_get_contents($file));
    }
}
