<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Automotive category taxonomy and brand reference list. Brand names are
 * used only to classify listings; BearingCave does not claim affiliation.
 * Both lists are editable in Admin → Categories / Brands.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $tree = [
            'Bearings' => ['bi-gear-wide-connected', ['Ball Bearings', 'Tapered Roller Bearings', 'Cylindrical Roller Bearings', 'Needle Roller Bearings', 'Wheel Hub Bearings & Units', 'Clutch Release Bearings', 'Spherical & Thrust Bearings']],
            'Engine Components' => ['bi-cpu', ['Pistons & Rings', 'Valves & Valve Train', 'Gaskets & Seals', 'Timing Components', 'Engine Bearings & Bushes']],
            'Transmission & Driveline' => ['bi-diagram-3', ['Gears & Shafts', 'CV Joints & Drive Shafts', 'Clutch Components', 'Differential Parts']],
            'Brake System' => ['bi-disc', ['Brake Pads & Shoes', 'Discs & Drums', 'Calipers & Cylinders', 'Brake Hoses & Lines']],
            'Suspension & Steering' => ['bi-arrows-collapse', ['Shock Absorbers & Struts', 'Control Arms & Bushes', 'Tie Rods & Ball Joints', 'Steering Gears & Pumps']],
            'Electrical & Electronics' => ['bi-lightning-charge', ['Starters & Alternators', 'Sensors', 'Wiring & Connectors', 'Switches & Relays', 'ECUs & Modules']],
            'Filters' => ['bi-funnel', ['Oil Filters', 'Air Filters', 'Fuel Filters', 'Cabin Filters']],
            'Cooling & Heating' => ['bi-thermometer-half', ['Radiators', 'Water Pumps', 'Thermostats', 'Fans & Blowers']],
            'Fuel & Exhaust' => ['bi-fuel-pump', ['Fuel Pumps & Injectors', 'Exhaust Components', 'Turbochargers']],
            'Body, Lighting & Exterior' => ['bi-lightbulb', ['Lamps & Lighting', 'Mirrors', 'Body Panels & Trim']],
            'Fasteners & Hardware' => ['bi-nut', ['Bolts, Nuts & Studs', 'Clips & Fixings', 'Springs & Washers']],
            'Rubber & Polymer Parts' => ['bi-circle', ['Hoses', 'Belts', 'Mountings', 'O-Rings & Oil Seals']],
        ];
        $now = date('Y-m-d H:i:s');
        $sort = 0;
        foreach ($tree as $parent => [$icon, $children]) {
            $slug = make_slug($parent);
            $row  = $this->db->table('categories')->where('slug', $slug)->get()->getRowArray();
            if (! $row) {
                $this->db->table('categories')->insert(['name' => $parent, 'slug' => $slug, 'icon' => $icon, 'sort_order' => $sort++, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
                $pid = $this->db->insertID();
            } else {
                $pid = $row['id'];
            }
            $i = 0;
            foreach ($children as $child) {
                $cslug = make_slug($child);
                if ($this->db->table('categories')->where('slug', $cslug)->countAllResults() === 0) {
                    $this->db->table('categories')->insert(['parent_id' => $pid, 'name' => $child, 'slug' => $cslug, 'sort_order' => $i++, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }

        $brands = [
            ['SKF', 'SE'], ['Schaeffler (FAG / INA / LuK)', 'DE'], ['NSK', 'JP'], ['NTN', 'JP'], ['Timken', 'US'], ['JTEKT (Koyo)', 'JP'], ['NBC', 'IN'],
            ['Bosch', 'DE'], ['Denso', 'JP'], ['Continental', 'DE'], ['ZF', 'DE'], ['Valeo', 'FR'], ['MAHLE', 'DE'], ['Brembo', 'IT'], ['Delphi', 'GB'],
            ['Gates', 'US'], ['Aisin', 'JP'], ['HELLA', 'DE'], ['NGK', 'JP'], ['MANN-FILTER', 'DE'], ['Lucas TVS', 'IN'], ['Minda', 'IN'], ['Rane', 'IN'],
            ['Uno Minda', 'IN'], ['Federal-Mogul', 'US'], ['Dayco', 'US'], ['Sachs', 'DE'], ['Monroe', 'US'], ['Exide', 'IN'], ['Other / Unbranded', null],
        ];
        foreach ($brands as [$name, $cc]) {
            $slug = make_slug($name);
            if ($this->db->table('brands')->where('slug', $slug)->countAllResults() === 0) {
                $this->db->table('brands')->insert(['name' => $name, 'slug' => $slug, 'country_code' => $cc, 'brand_type' => 'both', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}
