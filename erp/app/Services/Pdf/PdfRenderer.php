<?php

namespace App\Services\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * HTML → PDF via Dompdf with remote resources and PHP disabled (all images
 * are embedded data URIs from private storage). DejaVu Sans renders ₹.
 */
class PdfRenderer
{
    /** @param array<string, mixed> $data */
    public function renderView(string $view, array $data, string $paper = 'A4', string $orientation = 'portrait', ?string $footerLeft = null): string
    {
        $html = view($view, $data);

        return $this->renderHtml($html, $paper, $orientation, $footerLeft);
    }

    public function renderHtml(string $html, string $paper = 'A4', string $orientation = 'portrait', ?string $footerLeft = null): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('dpi', 96);
        $options->set('chroot', [FCPATH, WRITEPATH . 'tmp']);
        $options->set('tempDir', WRITEPATH . 'tmp');
        $options->set('fontCache', WRITEPATH . 'cache');

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper($paper, $orientation);
        $pdf->render();

        $canvas = $pdf->getCanvas();
        $font   = $pdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $w      = $canvas->get_width();
        $h      = $canvas->get_height();
        $canvas->page_text($w - 110, $h - 24, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, [0.35, 0.35, 0.35]);
        if ($footerLeft !== null && $footerLeft !== '') {
            $canvas->page_text(34, $h - 24, mb_strimwidth($footerLeft, 0, 120, '…'), $font, 7, [0.35, 0.35, 0.35]);
        }

        return (string) $pdf->output();
    }
}
