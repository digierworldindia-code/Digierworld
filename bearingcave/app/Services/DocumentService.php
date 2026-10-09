<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Libraries\Viewer;
use App\Models\DocumentModel;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Private, versioned document storage. Files are written below
 * WRITEPATH/uploads/private (outside the public webroot) under random names
 * and are only streamed through DocumentController after authorization.
 */
class DocumentService
{
    public const ALLOWED = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'csv'  => ['text/csv', 'text/plain', 'application/csv'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'xls'  => ['application/vnd.ms-excel', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    ];

    public function __construct(private DocumentModel $model = new DocumentModel())
    {
    }

    public function validate(?UploadedFile $file, ?array $allowedExt = null): void
    {
        if ($file === null || ! $file->isValid()) {
            throw new BusinessRuleException('Please choose a valid file to upload.');
        }
        $maxMb = (int) service('settings_store')->get('documents.max_upload_mb', 10);
        if ($file->getSize() > $maxMb * 1024 * 1024) {
            throw new BusinessRuleException("The file is larger than the {$maxMb} MB limit.");
        }
        $ext     = strtolower($file->getClientExtension());
        $allowed = $allowedExt ?? array_keys(self::ALLOWED);
        if (! in_array($ext, $allowed, true) || ! isset(self::ALLOWED[$ext])) {
            throw new BusinessRuleException('File type not allowed. Allowed: ' . implode(', ', $allowed) . '.');
        }
        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED[$ext], true)) {
            throw new BusinessRuleException("The file content ({$mime}) does not match its .{$ext} extension.");
        }
    }

    /**
     * @param array{company_id?:?int,entity_type:string,entity_id?:?int,doc_type:string,title?:string,expires_on?:?string,issued_on?:?string,visibility?:string,previous_version_id?:?int,review_status?:string} $meta
     */
    public function store(UploadedFile $file, array $meta, ?array $allowedExt = null): array
    {
        $this->validate($file, $allowedExt);

        $dir = WRITEPATH . 'uploads/private/' . date('Y/m');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $ext      = strtolower($file->getClientExtension());
        $name     = bin2hex(random_bytes(16)) . '.' . $ext;
        $tmpPath  = $file->getTempName();
        $sha      = hash_file('sha256', $tmpPath);
        $size     = $file->getSize();
        $mime     = $file->getMimeType();
        $original = mb_substr($file->getClientName(), 0, 191);
        $file->move($dir, $name);

        $version = 1;
        if (! empty($meta['previous_version_id'])) {
            $prev = $this->model->find($meta['previous_version_id']);
            if ($prev) {
                $version = (int) $prev['version'] + 1;
                $this->model->update($prev['id'], ['is_current' => 0]);
            }
        }

        $row = [
            'uuid'                => uuid4(),
            'company_id'          => $meta['company_id'] ?? null,
            'uploaded_by'         => auth()->loggedIn() ? (int) auth()->id() : null,
            'entity_type'         => $meta['entity_type'],
            'entity_id'           => $meta['entity_id'] ?? null,
            'doc_type'            => $meta['doc_type'],
            'title'               => $meta['title'] ?? $original,
            'original_name'       => $original,
            'stored_path'         => date('Y/m') . '/' . $name,
            'mime_type'           => $mime,
            'size_bytes'          => $size,
            'sha256'              => $sha,
            'version'             => $version,
            'previous_version_id' => $meta['previous_version_id'] ?? null,
            'is_current'          => 1,
            'issued_on'           => $meta['issued_on'] ?? null,
            'expires_on'          => $meta['expires_on'] ?? null,
            'visibility'          => $meta['visibility'] ?? 'private',
            'review_status'       => $meta['review_status'] ?? 'pending',
        ];
        $row['id'] = (int) $this->model->insert($row);

        service('audit')->log('document.uploaded', [
            'entity_type' => 'document', 'entity_id' => $row['id'], 'company_id' => $row['company_id'],
            'description' => "{$row['doc_type']} uploaded for {$row['entity_type']}#{$row['entity_id']}",
        ]);

        return $row;
    }

    public function absolutePath(array $doc): string
    {
        $path = realpath(WRITEPATH . 'uploads/private/' . $doc['stored_path']);
        $base = realpath(WRITEPATH . 'uploads/private');
        if ($path === false || $base === false || ! str_starts_with($path, $base)) {
            throw new BusinessRuleException('Document file is missing.');
        }

        return $path;
    }

    /**
     * Central authorization for document downloads.
     */
    public function canAccess(array $doc, Viewer $v): bool
    {
        if ($v->isStaff) {
            return true;
        }
        if ($doc['visibility'] === 'public') {
            return true;
        }
        if ($v->isGuest()) {
            return false;
        }
        $cid = $v->companyId();
        if ($cid !== null && (int) $doc['company_id'] === $cid) {
            return true;
        }

        $db = db_connect();

        switch ($doc['entity_type']) {
            case 'product':
                $product = $db->table('products')->where('id', $doc['entity_id'])->get()->getRowArray();
                if (! $product || ! service('visibility')->canView($product, $v)) {
                    return false;
                }

                return $doc['visibility'] === 'platform' || ($doc['visibility'] === 'verified_buyers' && $v->isVerifiedBuyer);

            case 'order':
                return $this->isOrderParty((int) $doc['entity_id'], $cid);

            case 'dispute':
                $d = $db->table('disputes')->where('id', $doc['entity_id'])->get()->getRowArray();

                return $d && in_array($cid, [(int) $d['raised_by_company_id'], (int) $d['against_company_id']], true);

            case 'inspection_report':
            case 'inspection_request':
                $r = $db->table('inspection_requests')->where('id', $doc['entity_id'])->get()->getRowArray();

                return $r && ((int) $r['company_id'] === $cid || ($r['order_id'] && $this->isOrderParty((int) $r['order_id'], $cid)));

            case 'rfq':
                $rfq = $db->table('rfqs')->where('id', $doc['entity_id'])->get()->getRowArray();
                if (! $rfq) {
                    return false;
                }

                return (int) $rfq['buyer_company_id'] === $cid
                    || $db->table('rfq_matches')->where('rfq_id', $rfq['id'])->where('supplier_company_id', $cid)
                        ->whereIn('status', ['invited', 'viewed', 'quoted'])->countAllResults() > 0;

            case 'logistics_request':
                $l = $db->table('logistics_requests')->where('id', $doc['entity_id'])->get()->getRowArray();

                return $l && ((int) $l['company_id'] === $cid || ($l['order_id'] && $this->isOrderParty((int) $l['order_id'], $cid)));
        }

        return false;
    }

    private function isOrderParty(int $orderId, ?int $cid): bool
    {
        if ($cid === null) {
            return false;
        }
        $o = db_connect()->table('orders')->where('id', $orderId)->get()->getRowArray();

        return $o && in_array($cid, [(int) $o['buyer_company_id'], (int) $o['supplier_company_id']], true);
    }

    public function forEntity(string $type, int $id, bool $currentOnly = true): array
    {
        $q = $this->model->where('entity_type', $type)->where('entity_id', $id);
        if ($currentOnly) {
            $q->where('is_current', 1);
        }

        return $q->orderBy('id', 'DESC')->findAll();
    }
}
