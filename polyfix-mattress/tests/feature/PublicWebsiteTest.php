<?php

namespace Tests\Feature;

use App\Libraries\Settings;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\PolyfixTestCase;

/**
 * The public website, over HTTP.
 *
 * @internal
 */
final class PublicWebsiteTest extends PolyfixTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->page('home', 'POLYFIX MATTRESS — Premium Mattresses');
        $this->page('warranty', 'Verify your mattress');
    }

    public function testTheHomePageRendersFromTheContentInTheDatabase(): void
    {
        $this->makeProduct();
        $result = $this->get('/');

        $result->assertStatus(200);
        $body = $result->getBody();
        $this->assertStringContainsString('<title>', $body);
        $this->assertStringContainsString(brand('name'), $body);
        $this->assertStringContainsString('<meta name="description"', $body);
        $this->assertStringContainsString('application/ld+json', $body, 'structured data is published');
    }

    public function testAProductPageShowsItsSizesAndPrices(): void
    {
        $product = $this->makeProduct();
        $slug    = $this->db->table('products')->select('slug')->where('id', $product['product'])->get()->getRow()->slug;

        $result = $this->get('mattresses/' . $slug);
        $result->assertStatus(200);
        $result->assertSee('Queen 78 x 60');
        $result->assertSee('21,500');
    }

    public function testAnUnknownProductIsA404(): void
    {
        // The framework signals this by exception; the router turns it into a
        // 404 page in a real request.
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->get('mattresses/no-such-mattress');
    }

    public function testTheWarrantyCheckAnswersWithoutAnAccount(): void
    {
        $product = $this->makeProduct();
        $unit    = $this->makeMattress($product['variant']);

        $result = $this->get('warranty/verify?serial=' . $unit['serial']);
        $result->assertStatus(200);
        $result->assertSee($unit['serial']);
        $result->assertSee('Genuine');
        $result->assertHeader('X-Robots-Tag');
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'), 'a result page is never cached');
    }

    public function testAnUnknownSerialSaysSoPlainly(): void
    {
        $result = $this->get('warranty/verify?serial=' . brand('serialPrefix') . '98765432');
        $result->assertStatus(200);
        $result->assertSee('no record');
    }

    public function testEveryPublicResponseCarriesTheSecurityHeaders(): void
    {
        $result = $this->get('/');

        foreach (['Content-Security-Policy', 'X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy'] as $header) {
            $result->assertHeader($header);
        }
        $this->assertStringContainsString("script-src 'self'", $result->response()->getHeaderLine('Content-Security-Policy'));
        $this->assertStringContainsString("frame-ancestors 'none'", $result->response()->getHeaderLine('Content-Security-Policy'));
    }

    public function testTheSitemapListsOnlyIndexablePages(): void
    {
        $this->db->table('seo_metadata')->insert([
            'id' => uuid4(), 'scope' => 'PAGE', 'entity_key' => 'home', 'title' => 'Home | POLYFIX MATTRESS',
            'description' => 'A description long enough to be realistic for a search result.',
            'canonical_path' => '/', 'keywords' => '[]', 'robots_index' => 1, 'robots_follow' => 1,
            'sitemap_include' => 1, 'sitemap_priority' => 1.0, 'sitemap_changefreq' => 'weekly', 'twitter_card' => 'summary_large_image',
        ]);
        $this->db->table('seo_metadata')->insert([
            'id' => uuid4(), 'scope' => 'PAGE', 'entity_key' => 'warranty', 'title' => 'Warranty | POLYFIX MATTRESS',
            'description' => 'Another description long enough to be realistic here.',
            'canonical_path' => '/warranty', 'keywords' => '[]', 'robots_index' => 0, 'robots_follow' => 1,
            'sitemap_include' => 1, 'sitemap_priority' => 0.9, 'sitemap_changefreq' => 'monthly', 'twitter_card' => 'summary_large_image',
        ]);

        $result = $this->get('sitemap.xml');
        $result->assertStatus(200);
        $body = $result->getBody();

        $this->assertStringContainsString('<urlset', $body);
        $this->assertStringContainsString('<loc>', $body);
        $this->assertStringNotContainsString('/warranty<', $body, 'a page marked noindex is left out');
        $this->assertStringNotContainsString('/admin', $body, 'nothing private is ever listed');
        $this->assertStringNotContainsString('/dealer', $body);
    }

    public function testRobotsKeepsCrawlersOutOfThePrivateAreas(): void
    {
        $body = $this->get('robots.txt')->getBody();

        foreach (['/admin', '/dealer/', '/account', '/media/', '/warranty/verify'] as $path) {
            $this->assertStringContainsString('Disallow: ' . $path, $body);
        }
        $this->assertStringContainsString('Sitemap:', $body);
    }

    public function testIndexingCanBeClosedOffSiteWide(): void
    {
        $this->db->table('system_settings')->where('key', 'seo.robots_allow_indexing')->update(['value' => 'false']);
        Settings::forget();

        $this->assertStringContainsString("Disallow: /\n", $this->get('robots.txt')->getBody());
        $this->get('sitemap.xml')->assertStatus(404);
    }

    public function testTheOldBrandAddressStillRedirects(): void
    {
        $this->get('why-colifees')->assertStatus(301);
    }

    /** A published page with the sections the site expects. */
    private function page(string $slug, string $heading): void
    {
        $this->insert('website_pages', [
            'id' => uuid4(), 'slug' => $slug, 'title' => $heading, 'status' => 'PUBLISHED',
            'hero' => json_encode(['eyebrow' => 'POLYFIX', 'heading' => $heading, 'body' => 'A line of copy.']),
            'sections' => json_encode($slug === 'home'
                ? [['type' => 'featured-products', 'heading' => 'The range']]
                : [['type' => 'verify-form', 'heading' => 'Check a serial number']]),
            'published_at' => utc_now(),
        ]);
    }
}
