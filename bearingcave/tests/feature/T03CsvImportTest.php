<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ImportJobModel;
use App\Models\ProductModel;
use App\Services\ImportService;
use Tests\Support\BCTestCase;

/**
 * TEST 3: Verified supplier uploads inventory from CSV → validation →
 * import → product visible only in permitted countries.
 */
final class T03CsvImportTest extends BCTestCase
{
    public function testCsvImportWithValidationDuplicatesAndCountryRules(): void
    {
        $s = $this->verifiedSupplier('IN');
        $this->product($s['company'], ['sku' => 'EXISTING-1']);
        $csv = "SKU,Product name,Part number,Category,Condition,Qty,Received,Lot size,Lot price,Allowed countries\n"
            . "IMP-1,Tapered bearing,30205,Tapered Roller Bearings,new,800,2025-06-01,800,64000,AE\n"
            . "IMP-2,Needle bearing,HK2016,needle-roller-bearings,new,1000,2024-01-15,1000,21000,\n"
            . "EXISTING-1,Duplicate SKU,6204-2RS,Ball Bearings,new,10,2025-01-01,10,100,\n"
            . "IMP-3,Broken row,,Unknown Category,new,abc,2099-01-01,,,ZZ\n";
        $file = $this->tempFile('inventory.csv', $csv);

        $this->actingAs($s['user']);
        $job = service('imports')->upload($file, 'products', (int) $s['company']['id'], (int) $s['user']->id);
        $map = service('imports')->autoMap(json_decode($job['headers'], true), ImportService::PRODUCT_FIELDS);
        // Custom headings need manual mapping, as a user would do on the mapping screen.
        $map += [0 => 'sku', 1 => 'name', 2 => 'part_number', 3 => 'category', 4 => 'item_condition', 5 => 'quantity', 6 => 'received_on', 7 => 'lot_quantity', 8 => 'lot_price', 9 => 'allowed_countries'];

        $this->postAs($s['user'], 'supplier/imports/' . $job['uuid'] . '/map', ['map' => $map])->assertRedirect();
        $job = model(ImportJobModel::class)->find($job['id']);
        $this->assertSame('validated', $job['status']);
        $this->assertSame(2, (int) $job['valid_rows']);
        $this->assertSame(1, (int) $job['duplicate_rows']);
        $this->assertSame(1, (int) $job['error_rows']);
        $__r = $this->getAs($s['user'], 'supplier/imports/' . $job['uuid']);
        $__r->assertOK();
        $__r->assertSee('SKU already exists');
        $__r->assertSee('Unknown category');

        $this->postAs($s['user'], 'supplier/imports/' . $job['uuid'] . '/confirm', ['action' => 'import', 'publish' => '1'])->assertRedirect();
        $this->assertSame(2, (int) model(ImportJobModel::class)->find($job['id'])['imported_rows']);
        $imp1 = model(ProductModel::class)->where('company_id', $s['company']['id'])->where('sku', 'IMP-1')->first();
        $this->assertSame('allow_list', $imp1['country_mode']);
        $this->assertSame(800, (int) $imp1['stock_on_hand']);
        $this->assertSame('import', db_connect()->table('inventory_movements')->where('product_id', $imp1['id'])->get()->getRow()->movement_type);

        // Approve the imported listings (review policy "all").
        $pm = $this->staff('procurement_manager');
        foreach (model(ProductModel::class)->where('status', 'pending_review')->findAll() as $p) {
            $this->postAs($pm, "admin/products/{$p['id']}/review", ['decision' => 'approve'])->assertRedirect();
        }

        $buyerAE = $this->registerCompany('buyer', 'AE');
        $buyerIN = $this->registerCompany('buyer', 'IN');
        $__r = $this->getAs($buyerAE['user'], 'marketplace?q=30205');
        $__r->assertOK();
        $__r->assertSee('Tapered bearing');
        $__r = $this->getAs($buyerIN['user'], 'marketplace?q=30205');
        $__r->assertOK();
        $__r->assertDontSee('Tapered bearing');
        $__r = $this->getAs(null, 'marketplace?q=30205');
        $__r->assertOK();
        $__r->assertDontSee('Tapered bearing');
        $this->assertNotFoundFor($buyerIN['user'], 'product/' . $imp1['slug']);
        $__r = $this->getAs(null, 'api/v1/products?q=30205');
        $__r->assertOK();
        $__r->assertJSONFragment(['meta' => ['total' => 0, 'page' => 1, 'per_page' => 24, 'pages' => 1]]);
        $this->assertStringNotContainsString($imp1['slug'], (string) $this->getAs(null, 'sitemap.xml')->getBody(), 'restricted listings are excluded from the sitemap');
        // The unrestricted import is public.
        $__r = $this->getAs(null, 'marketplace?q=HK2016');
        $__r->assertOK();
        $__r->assertSee('Needle bearing');
    }
}
