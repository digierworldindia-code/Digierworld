<?php

namespace App\Services\Files;

use App\Exceptions\ValidationException;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Private file storage under writable/uploads (outside the web root).
 *
 * - Random file names; the original name is never used as a path.
 * - Images are re-encoded with GD (strips metadata and polyglot payloads).
 * - PDFs must start with the %PDF- signature.
 * - Files are only served through controllers that check permissions.
 */
class UploadService
{
    private const AREAS = ['logo', 'certificates'];

    public function __construct(private readonly string $root)
    {
    }

    /**
     * Absolute path of a stored file, or null when the name is invalid or missing.
     */
    public function path(string $area, string $name): ?string
    {
        if (! in_array($area, self::AREAS, true) || ! preg_match('/^[a-f0-9]{32}\.(png|jpg|pdf)$/', $name)) {
            return null;
        }
        $file = $this->root . DIRECTORY_SEPARATOR . $area . DIRECTORY_SEPARATOR . $name;

        return is_file($file) ? $file : null;
    }

    /**
     * Stores the company logo as a re-encoded PNG (max 600 x 200 px).
     */
    public function storeLogo(UploadedFile $file): string
    {
        $image = $this->decodeImage($file);
        [$width, $height] = [imagesx($image), imagesy($image)];
        $scale = min(1, 600 / $width, 200 / $height);
        $w     = max(1, (int) round($width * $scale));
        $h     = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($w, $h);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $w, $h, $width, $height);

        $name = bin2hex(random_bytes(16)) . '.png';
        imagepng($canvas, $this->dir('logo') . $name, 6);
        imagedestroy($canvas);
        imagedestroy($image);

        return $name;
    }

    /**
     * Stores a calibration certificate (PDF, or an image re-encoded as PNG/JPEG).
     *
     * @return array{name: string, mime: string, sha256: string}
     */
    public function storeCertificate(UploadedFile $file): array
    {
        $mime = (string) $file->getMimeType();

        if ($mime === 'application/pdf') {
            $handle = fopen($file->getTempName(), 'rb');
            $magic  = $handle === false ? '' : (string) fread($handle, 5);
            if ($handle !== false) {
                fclose($handle);
            }
            if ($magic !== '%PDF-') {
                throw ValidationException::single('certificate', 'The file is not a valid PDF.');
            }
            $name = bin2hex(random_bytes(16)) . '.pdf';
            if (! copy($file->getTempName(), $this->dir('certificates') . $name)) {
                throw ValidationException::single('certificate', 'The file could not be stored.');
            }
        } elseif (in_array($mime, ['image/png', 'image/jpeg'], true)) {
            $image = $this->decodeImage($file);
            $name  = bin2hex(random_bytes(16)) . ($mime === 'image/png' ? '.png' : '.jpg');
            $mime === 'image/png'
                ? imagepng($image, $this->dir('certificates') . $name, 6)
                : imagejpeg($image, $this->dir('certificates') . $name, 90);
            imagedestroy($image);
        } else {
            throw ValidationException::single('certificate', 'Only PDF, PNG or JPEG certificates are accepted.');
        }

        $stored = $this->dir('certificates') . $name;

        return ['name' => $name, 'mime' => $mime, 'sha256' => (string) hash_file('sha256', $stored)];
    }

    public function delete(string $area, string $name): void
    {
        $path = $this->path($area, $name);
        if ($path !== null) {
            @unlink($path);
        }
    }

    private function decodeImage(UploadedFile $file): \GdImage
    {
        $data  = (string) file_get_contents($file->getTempName());
        $image = @imagecreatefromstring($data);
        if (! $image instanceof \GdImage) {
            throw ValidationException::single($file->getName(), 'The image could not be read.');
        }

        return $image;
    }

    private function dir(string $area): string
    {
        $dir = $this->root . DIRECTORY_SEPARATOR . $area . DIRECTORY_SEPARATOR;
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create upload directory {$area}");
        }

        return $dir;
    }
}
