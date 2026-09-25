<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * The two additive schema changes the CodeIgniter release needs.
 *
 *  dispatches.invoice_number  Dispatches already record the transporter, lorry
 *                             receipt and vehicle; the dispatch invoice had no
 *                             column. Nullable, so every existing row is valid.
 *
 *  claim videos               Dealers can now attach a short video to a claim.
 *                             Adds CLAIM_VIDEO to claim_media.kind (appended to
 *                             the ENUM, which MySQL 8 applies as a metadata-only
 *                             change) and video/mp4 + video/quicktime to the
 *                             MIME whitelist. The existing 25 MB size ceiling
 *                             already accommodates short clips.
 *
 * Reversible. down() refuses if reversing would lose data.
 */
class AddDispatchInvoiceAndClaimVideo extends Migration
{
    private const KINDS_BEFORE = "'CLAIM_PHOTO','CLAIM_INVOICE','PRODUCT_IMAGE','OG_IMAGE'";
    private const KINDS_AFTER  = "'CLAIM_PHOTO','CLAIM_INVOICE','PRODUCT_IMAGE','OG_IMAGE','CLAIM_VIDEO'";
    private const MIME_BEFORE  = "'image/jpeg','image/png','image/webp','application/pdf'";
    private const MIME_AFTER   = "'image/jpeg','image/png','image/webp','application/pdf','video/mp4','video/quicktime'";

    public function up(): void
    {
        if (! $this->db->fieldExists('invoice_number', 'dispatches')) {
            $this->db->query('ALTER TABLE `dispatches` ADD COLUMN `invoice_number` VARCHAR(60) NULL AFTER `lr_number`');
        }

        $this->db->query('ALTER TABLE `claim_media` MODIFY `kind` ENUM(' . self::KINDS_AFTER . ') NOT NULL');
        $this->replaceMimeCheck(self::MIME_AFTER);
    }

    public function down(): void
    {
        $videos = $this->db->table('claim_media')
            ->groupStart()->where('kind', 'CLAIM_VIDEO')->orLike('mime_type', 'video/', 'after')->groupEnd()
            ->countAllResults();
        if ($videos > 0) {
            throw new RuntimeException("Refusing to roll back: {$videos} claim video(s) would violate the old constraint. Export or remove them first.");
        }

        $invoices = $this->db->table('dispatches')->where('invoice_number IS NOT NULL')->countAllResults();
        if ($invoices > 0) {
            throw new RuntimeException("Refusing to roll back: {$invoices} dispatch invoice number(s) would be lost.");
        }

        $this->replaceMimeCheck(self::MIME_BEFORE);
        $this->db->query('ALTER TABLE `claim_media` MODIFY `kind` ENUM(' . self::KINDS_BEFORE . ') NOT NULL');
        if ($this->db->fieldExists('invoice_number', 'dispatches')) {
            $this->db->query('ALTER TABLE `dispatches` DROP COLUMN `invoice_number`');
        }
    }

    private function replaceMimeCheck(string $types): void
    {
        $exists = $this->db->query(
            "SELECT 1 FROM information_schema.check_constraints
              WHERE constraint_schema = DATABASE() AND constraint_name = 'claim_media_mime_chk'",
        )->getRow();

        if ($exists) {
            $this->db->query('ALTER TABLE `claim_media` DROP CHECK `claim_media_mime_chk`');
        }
        $this->db->query("ALTER TABLE `claim_media` ADD CONSTRAINT `claim_media_mime_chk` CHECK (`mime_type` IN ({$types}))");
    }
}
