<?php

namespace Tests\Support;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * An uploaded file for tests.
 *
 * PHP's is_uploaded_file() is only ever true for a file the web server itself
 * received, so a test has to say the move happened. Nothing else is changed:
 * inspection still reads the real bytes on disk.
 */
final class FakeUpload extends UploadedFile
{
    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_file($this->getTempName());
    }

    public function move(string $targetPath, ?string $name = null, bool $overwrite = false): bool
    {
        $target = rtrim($targetPath, '/') . '/' . ($name ?? $this->getName());
        if (! copy($this->getTempName(), $target)) {
            return false;
        }
        $this->hasMoved = true;

        return true;
    }
}
