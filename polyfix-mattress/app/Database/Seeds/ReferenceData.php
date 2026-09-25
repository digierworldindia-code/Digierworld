<?php

namespace App\Database\Seeds;

use App\Libraries\Rbac;
use CodeIgniter\Database\Seeder;

/**
 * Reference data a working installation cannot do without: the roles, the
 * permissions, which role holds which, the settings an administrator can
 * change, and a first warehouse.
 *
 * It is idempotent — running it again updates descriptions and adds anything
 * missing, and never touches business records. This is what a fresh
 * installation runs after the migrations, and what the test suite builds on.
 *
 *   php spark db:seed ReferenceData
 */
class ReferenceData extends Seeder
{
    /** Settings, with the value a new installation starts from. */
    private const SETTINGS = [
        ['company.legal_name', '"POLYFIX MATTRESS"', 'company', 'Registered name, shown in the website footer'],
        ['company.address', '"Manesar, Noranpur Chowk, Haryana, India"', 'company', 'Address shown on the website'],
        ['company.support_phone', '""', 'company', 'Support number shown on the website'],
        ['company.support_email', '""', 'company', 'Support address shown on the website'],
        ['company.gst_number', '""', 'company', 'GSTIN, for documents'],
        ['social.instagram', '""', 'social', 'Instagram profile URL'],
        ['social.facebook', '""', 'social', 'Facebook page URL'],
        ['social.linkedin', '""', 'social', 'LinkedIn page URL'],
        ['social.youtube', '""', 'social', 'YouTube channel URL'],
        ['seo.robots_allow_indexing', 'true', 'seo', 'Allow search engines to index the website. Off on a staging copy.'],
        ['seo.sitemap_enabled', 'true', 'seo', 'Serve /sitemap.xml'],
        ['website.contact_form_enabled', 'true', 'website', 'Accept enquiries from the contact form'],
        ['website.dealer_application_enabled', 'true', 'website', 'Accept dealership applications'],
        ['website.dealer_locator_enabled', 'true', 'website', 'Show the public dealer locator'],
        ['warranty.terms_version', '"v1.0"', 'warranty', 'Version of the warranty terms recorded against each new sale'],
        ['warranty.claim_window_days', '0', 'warranty', 'Days after warranty expiry a claim may still be raised (0 = none)'],
        ['risk.early_claim_days', '30', 'risk', 'A claim raised within this many days of the sale is flagged for a closer look'],
        ['risk.dealer_claim_ratio_threshold', '0.15', 'risk', 'Dealer claims-to-sales ratio above which the claim is flagged'],
        ['risk.repeat_customer_claim_threshold', '2', 'risk', 'Prior claims on one contact number before the claim is flagged'],
    ];

    public function run(): void
    {
        $this->permissions();
        $this->roles();
        $this->rolePermissions();
        $this->settings();
        $this->warehouse();
    }

    private function permissions(): void
    {
        foreach (Rbac::PERMISSIONS as $key => $meta) {
            $existing = $this->db->table('permissions')->select('id')->where('key', $key)->get()->getRow();
            if ($existing === null) {
                $this->db->table('permissions')->insert([
                    'id' => uuid4(), 'key' => $key, 'group' => $meta['group'], 'description' => $meta['description'],
                ]);
            } else {
                $this->db->table('permissions')->where('id', $existing->id)
                    ->update(['group' => $meta['group'], 'description' => $meta['description']]);
            }
        }
    }

    private function roles(): void
    {
        foreach (Rbac::ROLE_METADATA as $key => $meta) {
            $existing = $this->db->table('roles')->select('id')->where('key', $key)->get()->getRow();
            $row      = ['name' => $meta['name'], 'description' => $meta['description'], 'rank' => $meta['rank'], 'is_system' => 1];
            $existing === null
                ? $this->db->table('roles')->insert($row + ['id' => uuid4(), 'key' => $key])
                : $this->db->table('roles')->where('id', $existing->id)->update($row);
        }
    }

    /** The grant table is rebuilt from Rbac, which is the single definition of who can do what. */
    private function rolePermissions(): void
    {
        $roleIds       = array_column($this->db->table('roles')->select('id, key')->get()->getResultArray(), 'id', 'key');
        $permissionIds = array_column($this->db->table('permissions')->select('id, key')->get()->getResultArray(), 'id', 'key');

        foreach (Rbac::ROLE_PERMISSIONS as $role => $permissions) {
            if (! isset($roleIds[$role])) {
                continue;
            }
            $this->db->table('role_permissions')->where('role_id', $roleIds[$role])->delete();
            foreach ($permissions as $permission) {
                if (isset($permissionIds[$permission])) {
                    $this->db->table('role_permissions')->insert(['role_id' => $roleIds[$role], 'permission_id' => $permissionIds[$permission]]);
                }
            }
        }
    }

    private function settings(): void
    {
        foreach (self::SETTINGS as [$key, $value, $category, $description]) {
            $existing = $this->db->table('system_settings')->select('key')->where('key', $key)->get()->getRow();
            if ($existing === null) {
                // An existing value is never overwritten: it is the administrator's.
                $this->db->table('system_settings')->insert([
                    'key' => $key, 'value' => $value, 'category' => $category, 'description' => $description, 'is_secret' => 0,
                ]);
            } else {
                $this->db->table('system_settings')->where('key', $key)->update(['category' => $category, 'description' => $description]);
            }
        }
    }

    private function warehouse(): void
    {
        if ($this->db->table('warehouses')->countAllResults() > 0) {
            return;
        }
        $this->db->table('warehouses')->insert([
            'id' => uuid4(), 'code' => 'WH01', 'name' => 'Manesar plant',
            'address' => 'Noranpur Chowk', 'city' => 'Manesar', 'state' => 'Haryana', 'pincode' => '122051', 'is_active' => 1,
        ]);
    }
}
