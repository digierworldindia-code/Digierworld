<?php

namespace Tests\Feature;

use App\Database\Seeds\WebsiteContent;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\PolyfixTestCase;

/**
 * The public website as a freshly installed database serves it.
 *
 * Six public addresses read their heading and sections from `website_pages`,
 * and a missing row is a 404 rather than an empty page. A fresh install had
 * no rows, so the home page, the dealer page, the contact page, About, Why
 * POLYFIZZ — and /warranty/verify, the address printed on every QR label —
 * all answered 404 until somebody wrote the content by hand. The
 * WebsiteContent seeder is the fix, and this is what keeps it honest: if a
 * controller is ever given a new CMS page to read, the seeder has to learn
 * about it in the same change.
 *
 * @internal
 */
final class FreshInstallWebsiteTest extends PolyfixTestCase
{
    use FeatureTestTrait;

    /** Exactly what a fresh installation runs: the migrations, then both seeders. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWebsiteContent();
    }

    private function seedWebsiteContent(): void
    {
        $seeder = new WebsiteContent(config(\Config\Database::class), $this->db);
        $seeder->run();
    }

    /**
     * Every slug a controller asks the CMS for, read out of the controllers
     * themselves rather than listed here, so the list cannot drift.
     *
     * @return list<string>
     */
    private function requiredSlugs(): array
    {
        $slugs = [];

        foreach (glob(APPPATH . 'Controllers/Site/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match_all("/cmsPage\('([a-z0-9-]+)'\)/", $source, $matches)) {
                $slugs = array_merge($slugs, $matches[1]);
            }
        }

        // Pages::show() takes its slug from the route rather than a literal.
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        if (preg_match_all("/Site\\\\Pages::show\/([a-z0-9-]+)'/", $routes, $matches)) {
            foreach ($matches[1] as $slug) {
                // privacy and terms are legal text in the codebase, not CMS pages.
                if (! in_array($slug, ['privacy', 'terms'], true)) {
                    $slugs[] = $slug;
                }
            }
        }

        return array_values(array_unique($slugs));
    }

    public function testTheSeederCoversEveryPageAControllerAsksFor(): void
    {
        $required = $this->requiredSlugs();
        $this->assertNotSame([], $required, 'the slugs could not be read out of the controllers');

        $seeded = array_column(
            $this->db->table('website_pages')->select('slug')->where('status', 'PUBLISHED')->get()->getResultArray(),
            'slug',
        );

        foreach ($required as $slug) {
            $this->assertContains(
                $slug,
                $seeded,
                "a fresh install has no published \"{$slug}\" page, so that address answers 404",
            );
        }
    }

    /** @dataProvider publicAddresses */
    public function testEveryPublicAddressAnswersOnAFreshInstall(string $path): void
    {
        $result = $this->get($path);

        $this->assertSame(200, $result->response()->getStatusCode(), "{$path} did not render on a fresh install");
        $this->assertStringContainsString(brand('name'), (string) $result->response()->getBody());
    }

    public static function publicAddresses(): array
    {
        return [
            'home'     => ['/'],
            'range'    => ['mattresses'],
            'about'    => ['about'],
            'why'      => ['why-polyfizz'],
            'warranty' => ['warranty'],
            'dealers'  => ['dealers'],
            'contact'  => ['contact'],
            'privacy'  => ['privacy'],
            'terms'    => ['terms'],
        ];
    }

    /**
     * The one that matters most: a label in a customer's home points here,
     * and an empty catalogue is no excuse for a 404.
     */
    public function testTheAddressPrintedOnEveryLabelAnswersOnAFreshInstall(): void
    {
        $result = $this->get('warranty/verify?serial=' . brand('serialPrefix') . '-2026-000001');

        $result->assertStatus(200);
        $result->assertSee('no record');
    }

    /** Nothing seeded carries the old spelling, on any page. */
    public function testNoSeededPageCarriesTheOldBrand(): void
    {
        foreach ($this->db->table('website_pages')->get()->getResultArray() as $page) {
            foreach (['POLYFIX', 'Polyfix', 'COLIFEES', 'Colifees'] as $wrong) {
                $this->assertStringNotContainsString($wrong, $page['title'] . $page['hero'] . $page['sections'], "the {$page['slug']} page is misspelt");
            }
        }
    }

    /** An administrator's edits survive the seeder being run again. */
    public function testRunningTheSeederAgainDoesNotOverwriteAnEditedPage(): void
    {
        $this->db->table('website_pages')->where('slug', 'about')
            ->update(['title' => 'Our story', 'hero' => json_encode(['heading' => 'Our story'])]);

        $this->seedWebsiteContent();

        $page = $this->db->table('website_pages')->where('slug', 'about')->get()->getRowArray();
        $this->assertSame('Our story', $page['title'], 'the seeder overwrote content an administrator had changed');
        $this->assertSame(
            1,
            $this->db->table('website_pages')->where('slug', 'about')->countAllResults(),
            'the seeder inserted a second row for the same page',
        );
    }
}
