<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Legal/CMS page placeholders. They are published so links work, but are
 * flagged "pending approval" and display a draft notice until the client's
 * legal team supplies and approves the final text.
 */
class CmsSeeder extends Seeder
{
    public function run(): void
    {
        $pages = [
            'terms' => ['Terms of Use', '<p>These Terms of Use will govern access to the BearingCave marketplace. The final text must be prepared and approved by the client\'s legal advisers.</p><h2>Points the final terms must cover</h2><ul><li>Account eligibility and business verification</li><li>Supplier membership plans, fees, renewal and suspension</li><li>Buyer and supplier responsibilities for product quality, warranty and returns</li><li>RFQ confidentiality and use of platform data</li><li>Inspection and logistics service terms</li><li>Payments, escrow partner terms (once selected), cancellations and refunds</li><li>Dispute handling process</li><li>Country restrictions, export controls and sanctions compliance</li><li>Limitation of liability and governing law</li></ul>'],
            'privacy' => ['Privacy Policy', '<p>This page will explain how BearingCave collects, uses and protects personal and business data, including KYC documents, bank details and cross-border data transfers. Final text pending legal review (e.g. India DPDP Act 2023, GDPR for EU users where applicable).</p>'],
            'refund-policy' => ['Cancellation & Refund Policy', '<p>Cancellation and refund rules for memberships, inspection fees, logistics charges and orders are pending client approval.</p>'],
            'disclaimer' => ['Disclaimer', '<p>Part numbers and cross references shown on BearingCave are provided by suppliers. Unless explicitly marked as verified by BearingCave, a cross reference does not confirm interchangeability. Buyers should confirm specifications before ordering.</p>'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($pages as $slug => [$title, $body]) {
            if ($this->db->table('cms_pages')->where('slug', $slug)->countAllResults() === 0) {
                $this->db->table('cms_pages')->insert(['slug' => $slug, 'title' => $title, 'body' => $body, 'meta_title' => $title . ' | BearingCave', 'status' => 'published', 'show_in_footer' => 1, 'approval_status' => 'pending_approval', 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}
