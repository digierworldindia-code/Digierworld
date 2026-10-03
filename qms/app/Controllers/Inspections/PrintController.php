<?php

namespace App\Controllers\Inspections;

use App\Controllers\BaseController;

/**
 * A4 printouts: HTML (browser print) and PDF (mPDF) from the same view.
 * Every print / download is recorded in the audit trail.
 */
class PrintController extends BaseController
{
    public function html(int $id): string
    {
        $model = service('printBuilder')->build($id, $this->currentUser(), false);
        service('audit')->log('PRINT', 'inspection', $id, null, ['format' => 'HTML'], $model['ref']);

        return view('print/report', $model + ['mode' => 'html']);
    }

    public function pdf(int $id)
    {
        $model  = service('printBuilder')->build($id, $this->currentUser(), true);
        $html   = view('print/report', $model + ['mode' => 'pdf']);
        $footer = view('print/_footer', $model);
        $pdf    = service('pdf')->render($html, $model['orientation'], $model['ref'], $model['watermark'], $footer);
        service('audit')->log('PRINT', 'inspection', $id, null, ['format' => 'PDF'], $model['ref']);

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', ($this->request->getGet('download') ? 'attachment' : 'inline') . '; filename="' . $model['filename'] . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($pdf);
    }
}
