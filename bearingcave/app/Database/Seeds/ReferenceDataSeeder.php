<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * ISO 3166-1 countries (names from the ICU/CLDR dataset) and enabled currencies.
 * Exchange rates are intentionally left empty: no live FX feed is configured.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $countries = json_decode((string) file_get_contents(__DIR__ . '/data/countries.json'), true);
        foreach ($countries as $iso => $name) {
            if ($this->db->table('countries')->where('iso2', $iso)->countAllResults() === 0) {
                $this->db->table('countries')->insert(['iso2' => $iso, 'name' => $name, 'is_enabled' => 1]);
            }
        }

        $currencies = [
            ['INR', 'Indian Rupee', '₹', 1], ['USD', 'US Dollar', '$', 0], ['EUR', 'Euro', '€', 0], ['GBP', 'Pound Sterling', '£', 0],
            ['AED', 'UAE Dirham', 'AED', 0], ['SGD', 'Singapore Dollar', 'S$', 0], ['JPY', 'Japanese Yen', '¥', 0], ['CNY', 'Chinese Yuan', 'CN¥', 0],
        ];
        foreach ($currencies as [$code, $name, $symbol, $base]) {
            if ($this->db->table('currencies')->where('code', $code)->countAllResults() === 0) {
                $this->db->table('currencies')->insert(['code' => $code, 'name' => $name, 'symbol' => $symbol, 'is_enabled' => 1, 'is_base' => $base]);
            }
        }
    }
}
