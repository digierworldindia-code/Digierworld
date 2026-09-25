<?php

namespace Tests\Unit;

use App\Libraries\Catalogue;
use App\Libraries\Seo;
use App\Libraries\Settings;
use Tests\Support\PolyfixTestCase;

/**
 * Website content, SEO and the one place the brand is defined.
 *
 * @internal
 */
final class ContentAndBrandTest extends PolyfixTestCase
{
    public function testTheBrandHasOneDefinitionAndItIsSpeltCorrectly(): void
    {
        $this->assertSame('POLYFIX MATTRESS', brand('name'));
        $this->assertSame('POLYFIX', brand('shortName'));
        $this->assertSame('CLF', brand('serialPrefix'), 'serials issued by the previous platform keep their prefix');
        $this->assertSame('Manesar', brand('location')['locality']);
        $this->assertSame('Haryana', brand('location')['region']);
        $this->assertSame('IN', brand('location')['countryCode']);
    }

    public function testTheWordmarkIsMarkupAndStaysEscaped(): void
    {
        $this->assertSame('POLY<span>FIX</span>', brand_wordmark());
        $this->assertStringContainsString('Console', brand_wordmark('Console'));
    }

    public function testOnlyPublishedContentReachesTheWebsite(): void
    {
        $catalogue = new Catalogue($this->db);
        $published = $this->makeProduct();
        $draft     = $this->makeProduct(10, ['status' => 'DRAFT']);

        $slugs = array_column($catalogue->products(), 'slug');
        $this->assertContains($this->slugOf($published['product']), $slugs);
        $this->assertNotContains($this->slugOf($draft['product']), $slugs);
        $this->assertNull($catalogue->product($this->slugOf($draft['product'])), 'a draft product has no public page');
    }

    public function testADeletedProductLeavesTheWebsite(): void
    {
        $catalogue = new Catalogue($this->db);
        $product   = $this->makeProduct();
        $slug      = $this->slugOf($product['product']);

        $this->assertNotNull($catalogue->product($slug));
        $this->db->table('products')->where('id', $product['product'])->update([
            'deleted_at' => utc_now(), 'delete_reason' => 'Withdrawn from sale',
        ]);
        $this->assertNull($catalogue->product($slug));
    }

    public function testAPageIsOnlyServedWhenPublished(): void
    {
        $catalogue = new Catalogue($this->db);
        $this->insert('website_pages', [
            'id' => uuid4(), 'slug' => 'about', 'title' => 'About', 'status' => 'DRAFT',
            'hero' => '{}', 'sections' => '[]',
        ]);

        $this->assertNull($catalogue->page('about'));
        $this->db->table('website_pages')->where('slug', 'about')->update(['status' => 'PUBLISHED']);
        $this->assertNotNull($catalogue->page('about'));
    }

    public function testSeoMetadataIsUsedExactlyAsStored(): void
    {
        $this->insert('seo_metadata', [
            'id' => uuid4(), 'scope' => 'PAGE', 'entity_key' => 'home',
            'title' => 'A stored title | POLYFIX MATTRESS', 'description' => 'A stored description, long enough to be realistic.',
            'canonical_path' => '/', 'keywords' => json_encode(['mattress', 'hybrid']), 'robots_index' => 1, 'robots_follow' => 1,
            'sitemap_include' => 1, 'sitemap_priority' => 1.0, 'sitemap_changefreq' => 'weekly', 'twitter_card' => 'summary_large_image',
        ]);

        $meta = Seo::meta('PAGE', 'home', 'A fallback title', 'A fallback description.', '/');

        $this->assertSame('A stored title | POLYFIX MATTRESS', $meta['title']);
        $this->assertSame(['mattress', 'hybrid'], $meta['keywords']);
        $this->assertStringContainsString('index', $meta['robots']);
        $this->assertStringNotContainsString('noindex', $meta['robots']);
    }

    public function testAPageMarkedNoindexSaysSo(): void
    {
        $this->insert('seo_metadata', [
            'id' => uuid4(), 'scope' => 'PAGE', 'entity_key' => 'private-page',
            'title' => 'Private', 'description' => 'A description that is long enough to be realistic.',
            'keywords' => '[]', 'robots_index' => 0, 'robots_follow' => 0,
            'sitemap_include' => 0, 'sitemap_priority' => 0.1, 'sitemap_changefreq' => 'yearly', 'twitter_card' => 'summary',
        ]);

        $meta = Seo::meta('PAGE', 'private-page', 'Fallback', 'Fallback description.', '/private-page');
        $this->assertStringContainsString('noindex', $meta['robots']);
        $this->assertStringContainsString('nofollow', $meta['robots']);
    }

    public function testIndexingCanBeTurnedOffForTheWholeSite(): void
    {
        $this->db->table('system_settings')->where('key', 'seo.robots_allow_indexing')->update(['value' => 'false']);
        Settings::forget();

        $meta = Seo::meta('PAGE', 'home', 'A title', 'A description that is long enough.', '/');
        $this->assertStringContainsString('noindex', $meta['robots'], 'a staging copy must not be indexed whatever the page says');
    }

    public function testStructuredDataCannotBreakOutOfItsScriptElement(): void
    {
        $json = Seo::jsonLd(['@type' => 'Thing', 'name' => '</script><script>alert(1)</script>']);

        $this->assertStringNotContainsString('</script><script>', $json);
        $this->assertStringContainsString('application/ld+json', $json);
    }

    public function testTheOrganisationSchemaCarriesTheRealAddress(): void
    {
        $schema = Seo::organization();

        $this->assertSame('POLYFIX MATTRESS', $schema['name']);
        $this->assertSame('Manesar', $schema['address']['addressLocality']);
        $this->assertSame('IN', $schema['address']['addressCountry']);
    }

    public function testTheProductSchemaPricesInRupees(): void
    {
        $product   = $this->makeProduct();
        $catalogue = (new Catalogue($this->db))->product($this->slugOf($product['product']));
        $schema    = Seo::product($catalogue, $catalogue['sizes'], $catalogue['images']);

        $this->assertSame('INR', $schema['offers']['priceCurrency']);
        $this->assertSame('21500.00', $schema['offers']['lowPrice']);
        $this->assertSame('POLYFIX MATTRESS', $schema['brand']['name']);
    }

    public function testMoneyIsFormattedTheIndianWay(): void
    {
        $this->assertSame('₹21,500', inr(21500));
        $this->assertSame('₹1,25,000', inr(125000), 'lakhs are grouped as lakhs');
        $this->assertSame('₹0', inr(0));
    }

    private function slugOf(string $productId): string
    {
        return $this->db->table('products')->select('slug')->where('id', $productId)->get()->getRow()->slug;
    }
}
