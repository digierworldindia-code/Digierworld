<?php
/**
 * Private file storage in storage/uploads (never served directly).
 *
 * - Random file names; the original name is never used as a path.
 * - Images are re-encoded with GD (removes metadata and hidden payloads).
 * - PDFs must start with %PDF-.
 * - Files are sent only by pages that check permissions (logo.php, certificate.php).
 */
defined('QMS') || exit;

const UPLOAD_AREAS = ['logo', 'certificates'];
const UPLOAD_LOGO_MAX_KB = 1024;
const UPLOAD_CERTIFICATE_MAX_KB = 5120;
const UPLOAD_CERTIFICATE_MIMES = ['application/pdf', 'image/png', 'image/jpeg'];
const UPLOAD_IMAGE_MAX_PIXELS = 20_000_000; // decoded by GD at about 4 bytes per pixel

/** Absolute path of a stored file, or null when the name is invalid or missing. */
function upload_path(string $area, string $name): ?string
{
    if (! in_array($area, UPLOAD_AREAS, true) || ! preg_match('/^[a-f0-9]{32}\.(png|jpg|pdf)$/', $name)) {
        return null;
    }
    $file = QMS_ROOT . '/storage/uploads/' . $area . '/' . $name;

    return is_file($file) ? $file : null;
}

/**
 * Checks one entry of $_FILES. Returns the temporary path and the detected MIME type.
 *
 * @param list<string> $mimes allowed MIME types
 *
 * @return array{tmp: string, mime: string, name: string}
 */
function upload_accept(?array $file, string $field, int $maxKb, array $mimes): array
{
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        fail_field($field, 'Choose a file to upload.');
    }
    if (is_array($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        fail_field($field, in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'The file is larger than the server allows.' : 'The file could not be uploaded. Please try again.');
    }
    $tmp = (string) $file['tmp_name'];
    if (! is_uploaded_file($tmp) && ! (QMS_CLI && is_file($tmp))) {
        fail_field($field, 'The file could not be uploaded.');
    }
    if (filesize($tmp) > $maxKb * 1024) {
        fail_field($field, "The file is larger than {$maxKb} KB.");
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (! in_array($mime, $mimes, true)) {
        fail_field($field, 'This file type is not allowed.');
    }

    return ['tmp' => $tmp, 'mime' => $mime, 'name' => basename((string) ($file['name'] ?? 'file'))];
}

/** Stores the company logo as a re-encoded PNG (max 600 × 200 px). Returns the file name. */
function upload_store_logo(array $accepted): string
{
    $image  = upload_decode_image($accepted['tmp'], 'logo');
    $width  = imagesx($image);
    $height = imagesy($image);
    $scale  = min(1, 600 / $width, 200 / $height);
    $w      = max(1, (int) round($width * $scale));
    $h      = max(1, (int) round($height * $scale));
    $canvas = imagecreatetruecolor($w, $h);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
    imagecopyresampled($canvas, $image, 0, 0, 0, 0, $w, $h, $width, $height);
    $name = bin2hex(random_bytes(16)) . '.png';
    imagepng($canvas, upload_dir('logo') . $name, 6);

    return $name;
}

/**
 * Stores a calibration certificate (PDF, or an image re-encoded as PNG / JPEG).
 *
 * @return array{name: string, mime: string, sha256: string}
 */
function upload_store_certificate(array $accepted): array
{
    $mime = $accepted['mime'];
    if ($mime === 'application/pdf') {
        $handle = fopen($accepted['tmp'], 'rb');
        $magic  = $handle === false ? '' : (string) fread($handle, 5);
        if ($handle !== false) {
            fclose($handle);
        }
        if ($magic !== '%PDF-') {
            fail_field('certificate', 'The file is not a valid PDF.');
        }
        $name = bin2hex(random_bytes(16)) . '.pdf';
        if (! copy($accepted['tmp'], upload_dir('certificates') . $name)) {
            fail_field('certificate', 'The file could not be stored.');
        }
    } else {
        $image = upload_decode_image($accepted['tmp'], 'certificate');
        $name  = bin2hex(random_bytes(16)) . ($mime === 'image/png' ? '.png' : '.jpg');
        $mime === 'image/png' ? imagepng($image, upload_dir('certificates') . $name, 6) : imagejpeg($image, upload_dir('certificates') . $name, 90);
    }
    $stored = upload_dir('certificates') . $name;

    return ['name' => $name, 'mime' => $mime, 'sha256' => (string) hash_file('sha256', $stored)];
}

function upload_delete(string $area, string $name): void
{
    $path = upload_path($area, $name);
    if ($path !== null) {
        @unlink($path);
    }
}

function upload_decode_image(string $path, string $field): GdImage
{
    // Check the pixel size first: a small file can describe a huge image that would exhaust PHP's memory.
    $size = @getimagesize($path);
    if ($size === false || $size[0] < 1 || $size[1] < 1) {
        fail_field($field, 'The image could not be read.');
    }
    if ($size[0] * $size[1] > UPLOAD_IMAGE_MAX_PIXELS) {
        fail_field($field, 'The image is too large (' . $size[0] . ' × ' . $size[1] . ' pixels). Please scale it down to at most 20 megapixels.');
    }
    $image = @imagecreatefromstring((string) file_get_contents($path));
    if (! $image instanceof GdImage) {
        fail_field($field, 'The image could not be read.');
    }

    return $image;
}

function upload_dir(string $area): string
{
    $dir = QMS_ROOT . '/storage/uploads/' . $area . '/';
    if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
        throw new RuntimeException("Cannot create the upload folder {$area}.");
    }
    if (! is_file($dir . 'index.html')) {
        @file_put_contents($dir . 'index.html', "<!doctype html><title>403</title>\n"); // no listing if .htaccess is ignored
    }

    return $dir;
}
