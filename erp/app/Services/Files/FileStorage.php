<?php

namespace App\Services\Files;

use App\Exceptions\BusinessRuleException;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Private file storage under WRITEPATH/uploads (outside the public webroot).
 * Files are served only through permission-checked controller actions.
 */
class FileStorage
{
    /** Allowed MIME types per category (validated with finfo, not the browser). */
    public const PROFILES = [
        'image'   => ['mimes' => ['image/png', 'image/jpeg', 'image/webp'], 'max' => 3 * 1024 * 1024],
        'cv'      => ['mimes' => ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], 'max' => 5 * 1024 * 1024],
        'doc'     => ['mimes' => ['application/pdf', 'image/png', 'image/jpeg', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], 'max' => 8 * 1024 * 1024],
        'sheet'   => ['mimes' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream', 'application/zip'], 'max' => 10 * 1024 * 1024],
        'receipt' => ['mimes' => ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'], 'max' => 8 * 1024 * 1024],
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public static function root(): string
    {
        return rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
    }

    /**
     * Validate and store an uploaded file. Returns stored_files.id.
     *
     * @throws BusinessRuleException
     */
    public function storeUpload(UploadedFile $file, string $profile, string $category, ?string $entityType = null, ?int $entityId = null): int
    {
        if (! $file->isValid() || $file->hasMoved()) {
            throw new BusinessRuleException('Upload failed: ' . $file->getErrorString());
        }
        $rules = self::PROFILES[$profile] ?? throw new BusinessRuleException('Unknown upload profile.');
        if ($file->getSize() > $rules['max']) {
            throw new BusinessRuleException(sprintf('File is too large (max %d MB).', $rules['max'] / 1048576));
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getTempName()) ?: 'application/octet-stream';
        if (! in_array($mime, $rules['mimes'], true)) {
            throw new BusinessRuleException('This file type is not allowed (' . esc($mime) . ').');
        }
        if ($profile === 'sheet' && in_array($mime, ['application/octet-stream', 'application/zip'], true)
            && strtolower($file->getClientExtension()) !== 'xlsx') {
            throw new BusinessRuleException('Only .xlsx or .csv spreadsheets are accepted.');
        }

        return $this->storeContents((string) file_get_contents($file->getTempName()), $file->getClientName(), $mime, $category, $entityType, $entityId);
    }

    /** Store raw bytes (generated PDFs/XLSX, demo assets). Returns stored_files.id. */
    public function storeContents(string $bytes, string $originalName, string $mime, string $category, ?string $entityType = null, ?int $entityId = null): int
    {
        $ext      = strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) ?: 'bin';
        $ext      = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
        $relative = $category . '/' . date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $full     = self::root() . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (! is_dir(dirname($full)) && ! mkdir(dirname($full), 0750, true) && ! is_dir(dirname($full))) {
            throw new BusinessRuleException('Storage directory is not writable.');
        }
        if (file_put_contents($full, $bytes, LOCK_EX) === false) {
            throw new BusinessRuleException('Unable to write file to private storage.');
        }
        @chmod($full, 0640);

        $userId = service('authz')->currentUserId();
        $this->db->table('stored_files')->insert([
            'disk_path'     => $relative,
            'original_name' => mb_substr(basename(str_replace('\\', '/', $originalName)), 0, 255),
            'mime_type'     => $mime,
            'size_bytes'    => strlen($bytes),
            'sha256'        => hash('sha256', $bytes),
            'category'      => $category,
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'is_private'    => 1,
            'uploaded_by'   => $userId,
        ]);

        return (int) $this->db->insertID();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->table('stored_files')->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
    }

    /** Absolute path of a stored file inside private storage, or null when it is not there. */
    private function resolve(array $file): ?string
    {
        $full = realpath(self::root() . str_replace('/', DIRECTORY_SEPARATOR, $file['disk_path']));
        $root = realpath(self::root());

        return $full === false || $root === false || ! str_starts_with($full, $root . DIRECTORY_SEPARATOR) || ! is_file($full) ? null : $full;
    }

    public function path(array $file): string
    {
        return $this->resolve($file) ?? throw new BusinessRuleException('File not found.');
    }

    /** False when the database lists a file whose contents are not on disk (uploads not restored, folder replaced on upgrade, demo SQL from another install). */
    public function isOnDisk(array $file): bool
    {
        return $this->resolve($file) !== null;
    }

    /** Like path(), but null (and a log warning) when the recorded file is not on disk. */
    public function existingPath(array $file): ?string
    {
        $full = $this->resolve($file);
        if ($full === null) {
            log_message('warning', 'Stored file {id} ({path}) is missing on disk; restore writable/uploads from the files backup.', ['id' => $file['id'], 'path' => $file['disk_path']]);
        }

        return $full;
    }

    public function contents(int $id): ?string
    {
        $file = $this->find($id);

        return $file === null ? null : (string) file_get_contents($this->path($file));
    }

    public function dataUri(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $file = $this->find($id);
        $path = $file === null ? null : $this->existingPath($file);
        if ($path === null) {
            // a PDF is still produced, without the missing image; Diagnostics and Settings › Branding report the missing file
            return null;
        }

        return 'data:' . $file['mime_type'] . ';base64,' . base64_encode((string) file_get_contents($path));
    }
}
