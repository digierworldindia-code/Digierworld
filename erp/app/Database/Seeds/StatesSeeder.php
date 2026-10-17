<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * GST state/UT codes. uses_utgst = Union Territory without legislature,
 * where intra-UT supplies attract CGST + UTGST (not SGST).
 * Delhi (07), Puducherry (34) and J&K (01) have legislatures → SGST.
 */
class StatesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['01', 'Jammu and Kashmir', 'JK', 1, 0], ['02', 'Himachal Pradesh', 'HP', 0, 0],
            ['03', 'Punjab', 'PB', 0, 0], ['04', 'Chandigarh', 'CH', 1, 1], ['05', 'Uttarakhand', 'UK', 0, 0],
            ['06', 'Haryana', 'HR', 0, 0], ['07', 'Delhi', 'DL', 1, 0], ['08', 'Rajasthan', 'RJ', 0, 0],
            ['09', 'Uttar Pradesh', 'UP', 0, 0], ['10', 'Bihar', 'BR', 0, 0], ['11', 'Sikkim', 'SK', 0, 0],
            ['12', 'Arunachal Pradesh', 'AR', 0, 0], ['13', 'Nagaland', 'NL', 0, 0], ['14', 'Manipur', 'MN', 0, 0],
            ['15', 'Mizoram', 'MZ', 0, 0], ['16', 'Tripura', 'TR', 0, 0], ['17', 'Meghalaya', 'ML', 0, 0],
            ['18', 'Assam', 'AS', 0, 0], ['19', 'West Bengal', 'WB', 0, 0], ['20', 'Jharkhand', 'JH', 0, 0],
            ['21', 'Odisha', 'OD', 0, 0], ['22', 'Chhattisgarh', 'CG', 0, 0], ['23', 'Madhya Pradesh', 'MP', 0, 0],
            ['24', 'Gujarat', 'GJ', 0, 0], ['26', 'Dadra and Nagar Haveli and Daman and Diu', 'DH', 1, 1],
            ['27', 'Maharashtra', 'MH', 0, 0], ['29', 'Karnataka', 'KA', 0, 0], ['30', 'Goa', 'GA', 0, 0],
            ['31', 'Lakshadweep', 'LD', 1, 1], ['32', 'Kerala', 'KL', 0, 0], ['33', 'Tamil Nadu', 'TN', 0, 0],
            ['34', 'Puducherry', 'PY', 1, 0], ['35', 'Andaman and Nicobar Islands', 'AN', 1, 1],
            ['36', 'Telangana', 'TS', 0, 0], ['37', 'Andhra Pradesh', 'AP', 0, 0], ['38', 'Ladakh', 'LA', 1, 1],
        ];
        foreach ($rows as [$code, $name, $alpha, $ut, $utgst]) {
            $this->db->query(
                'INSERT INTO states (code, name, alpha_code, is_ut, uses_utgst, is_special) VALUES (?, ?, ?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), alpha_code = VALUES(alpha_code), is_ut = VALUES(is_ut), uses_utgst = VALUES(uses_utgst)',
                [$code, $name, $alpha, $ut, $utgst]
            );
        }
        foreach ([['96', 'Other Country (export)'], ['97', 'Other Territory']] as [$code, $name]) {
            $this->db->query(
                'INSERT INTO states (code, name, alpha_code, is_ut, uses_utgst, is_special) VALUES (?, ?, NULL, 0, 0, 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), is_special = 1',
                [$code, $name]
            );
        }
    }
}
