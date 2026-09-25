<?php

namespace App\Libraries;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;

/**
 * QR codes and barcodes for the mattress digital passport.
 *
 * The QR code encodes {baseURL}/warranty/verify?q=<qr_token> — exactly the
 * address printed on every label already in the field. It carries an opaque
 * token, never the serial number, so a label reveals neither how many units
 * exist nor a way to enumerate them. Anyone scanning it reaches only the
 * public verification page and its limited answer.
 *
 * The barcode encodes the serial number itself (Code 128), for warehouse
 * scanners and for a dealer typing it in.
 */
final class Labels
{
    public static function verifyUrl(string $qrToken): string
    {
        return rtrim(config('App')->baseURL, '/') . '/warranty/verify?q=' . rawurlencode($qrToken);
    }

    public static function qrSvg(string $qrToken, int $scale = 5): string
    {
        $options = new QROptions([
            'outputInterface'  => QRMarkupSVG::class,
            'outputBase64'     => false,
            // Q tolerates ~25% damage: labels get scuffed in transit.
            'eccLevel'         => EccLevel::Q,
            'scale'            => $scale,
            'addQuietzone'     => true,
            'svgAddXmlHeader'  => false,
            'drawLightModules' => false,
        ]);

        return (new QRCode($options))->render(self::verifyUrl($qrToken));
    }

    public static function barcodeSvg(string $serial): string
    {
        $barcode  = (new TypeCode128())->getBarcode($serial);
        $renderer = new SvgRenderer();
        $renderer->setForegroundColor([23, 24, 26]);

        return $renderer->render($barcode, 2 * $barcode->getWidth(), 56);
    }
}
