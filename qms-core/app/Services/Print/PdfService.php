<?php

namespace App\Services\Print;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * A4 PDF rendering with mPDF from the same HTML as the print view.
 *
 * The HTML is produced by our own views (all data escaped) and embeds the logo
 * as a data URI, so mPDF never needs to fetch remote resources.
 */
class PdfService
{
    public function render(string $html, string $orientation, string $title, ?string $watermark, string $footerHtml): string
    {
        $tempDir = WRITEPATH . 'cache' . DIRECTORY_SEPARATOR . 'mpdf';
        if (! is_dir($tempDir) && ! mkdir($tempDir, 0770, true) && ! is_dir($tempDir)) {
            throw new \RuntimeException('PDF temp directory cannot be created.');
        }

        $mpdf = new Mpdf([
            'mode'              => 'utf-8',
            'format'            => 'A4',
            'orientation'       => $orientation === 'L' ? 'L' : 'P',
            'margin_left'       => 8,
            'margin_right'      => 8,
            'margin_top'        => 8,
            'margin_bottom'     => 14,
            'margin_header'     => 4,
            'margin_footer'     => 5,
            'tempDir'           => $tempDir,
            'default_font'      => 'dejavusans',
            'default_font_size' => 8,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->SetCreator('QMS');
        $mpdf->SetDisplayMode('fullpage');
        $mpdf->showImageErrors = false;
        if ($watermark !== null) {
            $mpdf->SetWatermarkText($watermark, 0.08);
            $mpdf->showWatermarkText  = true;
            $mpdf->watermark_font     = 'dejavusans';
        }
        $mpdf->SetHTMLFooter($footerHtml);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
