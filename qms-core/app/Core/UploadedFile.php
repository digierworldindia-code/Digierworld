<?php

declare(strict_types=1);

namespace App\Core;

/**
 * One file from $_FILES. The client-supplied name and type are never trusted:
 * getMimeType() inspects the content with finfo.
 */
final class UploadedFile
{
    /**
     * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int} $file
     */
    public function __construct(private readonly array $file)
    {
    }

    /** Client file name (display only, never used as a path). */
    public function getName(): string
    {
        return basename((string) ($this->file['name'] ?? ''));
    }

    public function getClientName(): string
    {
        return $this->getName();
    }

    public function getClientExtension(): string
    {
        return strtolower(pathinfo($this->getName(), PATHINFO_EXTENSION));
    }

    public function getTempName(): string
    {
        return (string) ($this->file['tmp_name'] ?? '');
    }

    public function getError(): int
    {
        return (int) ($this->file['error'] ?? UPLOAD_ERR_NO_FILE);
    }

    public function getSize(): int
    {
        $tmp = $this->getTempName();

        return $tmp !== '' && is_file($tmp) ? (int) filesize($tmp) : (int) ($this->file['size'] ?? 0);
    }

    /** Uploaded through HTTP POST without an error. */
    public function isValid(): bool
    {
        if ($this->getError() !== UPLOAD_ERR_OK) {
            return false;
        }
        $tmp = $this->getTempName();

        return $tmp !== '' && (is_uploaded_file($tmp) || (PHP_SAPI === 'cli' && is_file($tmp)));
    }

    /** MIME type detected from the file content. */
    public function getMimeType(): string
    {
        $tmp = $this->getTempName();
        if ($tmp === '' || ! is_file($tmp)) {
            return '';
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return (string) $finfo->file($tmp);
    }

    public function getErrorString(): string
    {
        return match ($this->getError()) {
            UPLOAD_ERR_OK                         => '',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the server allows.',
            UPLOAD_ERR_PARTIAL                    => 'The file was only partly uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE                    => 'No file was chosen.',
            default                               => 'The file could not be uploaded (server error ' . $this->getError() . ').',
        };
    }
}
