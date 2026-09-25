<?php

namespace App\Services;

use App\Exceptions\AppException;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Polyfix;

/**
 * Claim uploads: what is accepted, and where it is kept.
 *
 * An upload is untrusted input that will later be handed back to a browser.
 * Four checks, all of which must pass:
 *
 *   1. size         within the configured limit for its kind
 *   2. signature    the file's first bytes identify it as one of the allowed
 *                   formats; the declared type and extension are only hints
 *   3. agreement    the browser's declared type and the extension must agree
 *                   with the bytes — a disagreement is how polyglot and
 *                   stored-XSS payloads are smuggled
 *   4. sanity       images must have readable, plausible dimensions (read from
 *                   the header, never by decoding, so a crafted file cannot
 *                   turn validation into a decompression bomb)
 *
 * Files are stored under writable/, outside the web root, with a generated
 * name; the uploader's filename never reaches the filesystem. They are only
 * ever served through an authorised controller.
 */
final class MediaService
{
    private const MIN_DIMENSION = 80;
    private const MAX_DIMENSION = 12_000;

    /** detected type => allowed extensions */
    private const EXTENSIONS = [
        'image/jpeg'      => ['jpg', 'jpeg'],
        'image/png'       => ['png'],
        'image/webp'      => ['webp'],
        'application/pdf' => ['pdf'],
        'video/mp4'       => ['mp4', 'm4v'],
        'video/quicktime' => ['mov'],
    ];

    public function __construct(private readonly Polyfix $config)
    {
    }

    public static function instance(): self
    {
        return new self(config(Polyfix::class));
    }

    /**
     * @return array{mime:string, extension:string, kind:string, width:?int, height:?int, bytes:int, sha256:string}
     */
    public function inspect(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw AppException::rule('The upload did not complete: ' . $file->getErrorString());
        }

        $path  = $file->getTempName();
        $bytes = (int) filesize($path);
        if ($bytes === 0) {
            throw AppException::rule('The file contained no data.');
        }

        $detected = $this->detect($path);
        if ($detected === null) {
            log_message('warning', 'security.UPLOAD_REJECTED reason=unknown_format declared={mime}', ['mime' => $file->getClientMimeType()]);

            throw AppException::rule('Only JPEG, PNG or WebP photos, PDF invoices and MP4 or MOV videos can be attached.');
        }

        $limit = str_starts_with($detected, 'video/') ? $this->config->maxVideoBytes : $this->config->maxUploadBytes;
        if ($bytes > $limit) {
            throw AppException::rule('The file is larger than ' . round($limit / 1_048_576) . ' MB.');
        }

        $declared = strtolower(trim(explode(';', (string) $file->getClientMimeType())[0]));
        // Some phones send video as application/octet-stream; that alone is not
        // a contradiction. Any other declared type must match the bytes.
        if ($declared !== '' && $declared !== 'application/octet-stream' && $declared !== $detected) {
            log_message('warning', 'security.UPLOAD_REJECTED reason=type_mismatch declared={d} detected={t}', ['d' => $declared, 't' => $detected]);

            throw AppException::rule("The file was sent as {$declared}, but its contents are {$detected}.");
        }

        $extension = strtolower(pathinfo((string) $file->getClientName(), PATHINFO_EXTENSION));
        if ($extension !== '' && ! in_array($extension, self::EXTENSIONS[$detected], true)) {
            throw AppException::rule("A {$detected} file cannot have a .{$extension} extension.");
        }

        $width = $height = null;
        if (str_starts_with($detected, 'image/')) {
            // Reads the header only; the image is never decoded here.
            $size = @getimagesize($path);
            if ($size === false || $size[0] < 1) {
                throw AppException::rule('The image header could not be read.');
            }
            [$width, $height] = $size;
            if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION) {
                throw AppException::rule("The image is {$width}×{$height}; at least " . self::MIN_DIMENSION . 'px each way is needed to show the problem.');
            }
            if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
                throw AppException::rule("The image is {$width}×{$height}, larger than the " . self::MAX_DIMENSION . 'px limit.');
            }
        }

        return [
            'mime'      => $detected,
            'extension' => self::EXTENSIONS[$detected][0],
            'kind'      => match (true) {
                $detected === 'application/pdf'     => 'CLAIM_INVOICE',
                str_starts_with($detected, 'video/') => 'CLAIM_VIDEO',
                default                               => 'CLAIM_PHOTO',
            },
            'width'  => $width,
            'height' => $height,
            'bytes'  => $bytes,
            'sha256' => hash_file('sha256', $path),
        ];
    }

    /** Moves an inspected upload into storage. Returns the storage key. */
    public function store(UploadedFile $file, string $claimId, string $extension): string
    {
        $key = 'claims/' . $claimId . '/' . uuid4() . '.' . $extension;
        $dir = dirname($this->absolute($key));
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Could not create the upload directory.');
        }
        $file->move($dir, basename($key));

        return $key;
    }

    /** Absolute path for a key. Refuses anything that would leave the upload root. */
    public function absolute(string $key): string
    {
        if (! preg_match('#^claims/[0-9a-f-]{36}/[0-9a-f-]{36}\.[a-z0-9]{2,5}$#', $key)) {
            throw new \RuntimeException('Invalid storage key.');
        }

        return $this->config->uploadDirectory() . $key;
    }

    /** Identifies the format from the file's own bytes. */
    private function detect(string $path): ?string
    {
        $head = (string) file_get_contents($path, false, null, 0, 16);

        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF")                               => 'image/jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") && substr($head, 12, 4) === 'IHDR' => 'image/png',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP'    => 'image/webp',
            str_starts_with($head, '%PDF-')                                      => 'application/pdf',
            // ISO base media file format: a box size, then 'ftyp', then the brand.
            substr($head, 4, 4) === 'ftyp' => substr($head, 8, 4) === 'qt  ' ? 'video/quicktime' : 'video/mp4',
            default => null,
        };
    }
}
