<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Lets a dealer upload the invoice a warranty review asked for.
 *
 * `warranty_review_cases.invoice_media_id` already expected a file, but
 * every stored file belonged to a claim: `claim_media.claim_id` is NOT NULL,
 * and a review is not a claim.
 *
 * A second media table was the obvious move and the wrong one. Everything
 * that makes uploads safe here — sniffing the format from the bytes, the
 * size limits, the storage keys, serving through one controller that checks
 * who is asking, the dealer-isolation rule — hangs off this table. A
 * parallel table would be a second place for all of it to be got right, and
 * eventually wrong.
 *
 * So the owner becomes "a claim or a review, exactly one of the two",
 * enforced by a CHECK rather than by whoever writes the next insert.
 */
class ReviewInvoiceUploads extends SchemaMigration
{
    public function up(): void
    {
        // As with the scan constraint: `claim_media` is part of the core
        // schema, so its absence is a broken run, not a variation to absorb.
        if (! $this->db->tableExists('claim_media', false)) {
            throw new \RuntimeException(
                'claim_media does not exist, so invoice uploads cannot be attached to a review case. '
                . 'Run the migrations in order, from an empty database or from the previous release.',
            );
        }

        if (! $this->db->fieldExists('review_case_id', 'claim_media')) {
            $this->db->query(
                'ALTER TABLE `claim_media`
                   MODIFY COLUMN `claim_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
                   ADD COLUMN `review_case_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `claim_id`,
                   ADD KEY `claim_media_review_case_idx` (`review_case_id`)',
            );
        }

        // Widen the kind so a review invoice is not filed as a claim invoice.
        //
        // CLAIM_VIDEO stays in the list. Rewriting an ENUM replaces it rather
        // than adding to it, and the first version of this line left
        // CLAIM_VIDEO out: MediaService files any video as CLAIM_VIDEO, the
        // claim forms accept MP4 and MOV and the mime CHECK on this table
        // allows both, so every video a dealer attached to a claim failed at
        // the database with the file already written to disk. An ENUM is only
        // ever widened here.
        $this->db->query(
            "ALTER TABLE `claim_media` MODIFY COLUMN `kind`
             ENUM('CLAIM_PHOTO', 'CLAIM_INVOICE', 'REVIEW_INVOICE', 'CLAIM_VIDEO', 'PRODUCT_IMAGE', 'OG_IMAGE')
             NOT NULL DEFAULT 'CLAIM_PHOTO'",
        );

        // Every existing row belongs to a claim, so this is satisfied from
        // the moment it is added.
        if (! $this->constraintExists('claim_media_owner_chk')) {
            $this->db->query(
                'ALTER TABLE `claim_media` ADD CONSTRAINT `claim_media_owner_chk` CHECK (
                    (`claim_id` IS NOT NULL AND `review_case_id` IS NULL)
                 OR (`claim_id` IS NULL AND `review_case_id` IS NOT NULL))',
            );
        }

        if (! $this->constraintExists('claim_media_review_case_fk') && $this->db->tableExists('warranty_review_cases')) {
            $this->db->query(
                'ALTER TABLE `claim_media` ADD CONSTRAINT `claim_media_review_case_fk`
                 FOREIGN KEY (`review_case_id`) REFERENCES `warranty_review_cases` (`id`)
                 ON DELETE CASCADE ON UPDATE RESTRICT',
            );
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('claim_media')) {
            return;
        }

        // Refuse while review uploads exist: narrowing the column would
        // orphan the files, and the rows point at bytes on disk.
        $held = (int) $this->db->query(
            'SELECT COUNT(*) AS n FROM `claim_media` WHERE `review_case_id` IS NOT NULL',
        )->getRow()->n;

        if ($held > 0) {
            return;
        }

        if ($this->constraintExists('claim_media_review_case_fk')) {
            $this->db->query('ALTER TABLE `claim_media` DROP FOREIGN KEY `claim_media_review_case_fk`');
        }
        if ($this->constraintExists('claim_media_owner_chk')) {
            $this->db->query('ALTER TABLE `claim_media` DROP CONSTRAINT `claim_media_owner_chk`');
        }
        if ($this->db->fieldExists('review_case_id', 'claim_media')) {
            $this->db->query('ALTER TABLE `claim_media` DROP COLUMN `review_case_id`');
        }

        $this->db->query(
            'ALTER TABLE `claim_media`
               MODIFY COLUMN `claim_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
        );
    }

    private function constraintExists(string $name): bool
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['claim_media', $name],
        )->getRow()->n > 0;
    }
}
