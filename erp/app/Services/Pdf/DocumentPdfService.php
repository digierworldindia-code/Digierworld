<?php

namespace App\Services\Pdf;

use App\Services\Billing\SnapshotBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Produces document PDFs. Finalized/cancelled documents render from their
 * immutable snapshot; drafts render a live preview with a DRAFT watermark.
 */
class DocumentPdfService
{
    public function __construct(private readonly BaseConnection $db, private readonly PdfRenderer $renderer)
    {
    }

    /** @return array{0: string, 1: string} [pdf bytes, filename] */
    public function render(int $docId, string $copy = 'original'): array
    {
        $doc   = $this->db->table('billing_documents')->where('id', $docId)->get()->getRowArray();
        $snapB = new SnapshotBuilder($this->db);
        if ($doc['status'] === 'draft') {
            $lines = $this->db->table('billing_line_items')->where('document_id', $docId)->orderBy('line_no')->get()->getResultArray();
            $taxes = $this->db->table('billing_taxes')->where('document_id', $docId)->get()->getResultArray();
            $snap  = $snapB->build($doc, $lines, $taxes, false);
        } else {
            $snap = $snapB->load($docId);
            if ($snap === null) {
                throw new \RuntimeException('Snapshot missing for finalized document #' . $docId);
            }
        }

        return [$this->renderSnapshot($snap, $doc['status'], $copy, $doc), $this->filename($doc)];
    }

    /**
     * @param array<string, mixed> $snap
     * @param array<string, mixed> $doc
     */
    public function renderSnapshot(array $snap, string $status, string $copy = 'original', array $doc = []): string
    {
        $images = [];
        foreach (['logo', 'signature', 'stamp', 'sign_stamp', 'upi_qr'] as $slot) {
            $a = $snap['branding'][$slot] ?? null;
            if (is_array($a) && ! empty($a['file_id'])) {
                $images[$slot] = service('files')->dataUri((int) $a['file_id']);
            }
        }
        $original   = null;
        $noteReason = null;
        if (! empty($snap['header']['original_document_id'])) {
            $original = $this->db->table('billing_documents')->select('doc_type, document_number, issue_date')->where('id', $snap['header']['original_document_id'])->get()->getRowArray();
            if (! empty($doc['id'])) {
                $noteReason = $this->db->table('credit_debit_note_links')->select('reason_text')->where('note_document_id', $doc['id'])->get()->getRow()->reason_text ?? null;
            }
        }
        $copyLabel = in_array($snap['header']['doc_type'], ['estimate', 'quotation', 'proforma'], true) ? '' : ($copy === 'duplicate' ? 'DUPLICATE FOR SUPPLIER' : 'ORIGINAL FOR RECIPIENT');
        $paper     = ($snap['template']['paper'] ?? 'A4') === 'A5' ? 'A5' : 'A4';

        return $this->renderer->renderView('pdf/document', [
            's'               => $snap,
            'images'          => $images,
            'status'          => $status,
            'copyLabel'       => $copyLabel,
            'original'        => $original,
            'noteReason'      => $noteReason,
            'treatmentLabels' => array_column($this->db->table('tax_treatments')->get()->getResultArray(), 'label', 'code'),
        ], $paper, 'portrait', $snap['supplier']['footer'] ?? null);
    }

    /** @param array<string, mixed> $doc */
    public function filename(array $doc): string
    {
        $num = $doc['document_number'] ?: $doc['draft_ref'];

        return preg_replace('/[^A-Za-z0-9_\-]+/', '_', doc_type_label($doc['doc_type']) . '_' . $num) . '.pdf';
    }
}
