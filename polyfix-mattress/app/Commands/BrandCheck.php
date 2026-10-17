<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Checks how the brand is spelt, in the code and in the database.
 *
 *   php spark polyfix:brand-check
 *   php spark polyfix:brand-check --data       (also scan the database)
 *
 * The brand is defined once, in app/Config/Brand.php. This looks for the
 * spellings that must never appear, and for the name written out in code where
 * the configuration should have been used instead.
 *
 * It reports; it never edits. Historical records — audit entries, customer
 * text, the old platform's database name — are left exactly as they are.
 */
class BrandCheck extends BaseCommand
{
    protected $group       = 'POLYFIX';
    protected $name        = 'polyfix:brand-check';
    protected $description = 'Report misspellings of the brand, and the name hard-coded where config belongs.';
    protected $usage       = 'polyfix:brand-check [--data]';
    protected $options     = ['--data' => 'Also scan content in the database (read-only)'];

    /**
     * Spellings that are always wrong.
     *
     * POLYFIX is on this list: it was the name before the correction to
     * POLYFIZZ. It survives only where it is a historical fact — the database
     * name chosen at install, the application's database accounts, the
     * Config\Polyfix class and the polyfix.* settings prefix. Those are in
     * ALLOWED_FRAGMENTS; everywhere else it is a defect.
     */
    private const WRONG = [
        'COLIFEES', 'Colifees',
        'POLYFIX', 'Polyfix', 'PolyFix',
        'POLY FIX', 'Poly Fix',
        'POLY FIZZ', 'Poly Fizz',
        'POLYFIZZZ', 'Polyfizzz',
        'POLYFIZZ MATTRESS', 'Polyfizz Mattress',
    ];

    /**
     * Occurrences of a wrong spelling that are not the brand at all: the
     * database name, its accounts, the config class and the settings prefix.
     * Renaming any of these would be churn at best and an outage at worst.
     *
     * @var list<string>
     */
    private const ALLOWED_FRAGMENTS = [
        'polyfix_mattress', 'polyfix_app', 'polyfix_owner', 'polyfix_backup', 'polyfix_test',
        'polyfix_preview', 'polyfix_zipcheck', 'polyfix_final', 'polyfix_uf', 'polyfix_no2fa',
        'Config\\Polyfix', 'Config/Polyfix', "config('Polyfix')", 'Polyfix::class',
        'PolyfixRules', 'PolyfixTestCase', 'polyfix.', 'polyfix:', 'Polyfix $config',
        'class Polyfix', 'polyfix-mattress', 'Polyfix extends', 'use Config\\Polyfix',
        // A migration name recorded in the database when the previous rename
        // ran. It is history, not branding, and rewriting it would falsify
        // the record of what was applied.
        '0006_brand_rename_polyfix', 'brand_rename_backup',
    ];

    /** Where a literal is expected and not a fault. */
    private const ALLOWED_PATHS = [
        'app/Config/Brand.php',            // the definition itself
        'app/Commands/BrandCheck.php',     // this file
        'app/Commands/ImportPostgres.php', // names the previous platform's database
        // Must contain the old spelling: it is the string the migration
        // searches for when correcting stored content.
        'app/Database/Migrations/2026-10-17-000001_RebrandToPolyfizz.php',
        'app/Config/Routes.php',           // keeps the old public address working
        'tests/',                          // tests assert the exact spelling
        'docs/',                           // documentation quotes both names
        'README.md',
        'LICENSE',
        'composer.json',                   // the package's own description
        'public/assets/vendor/README.md',
        // Shown when the install is unfinished, so it must not call brand() —
        // the config is one of the things that may be broken at that point.
        'app/Views/errors/html/setup.php',
    ];

    public function run(array $params)
    {
        $problems = $this->scanFiles();
        if (CLI::getOption('data') !== null || isset($params['data'])) {
            $problems += $this->scanDatabase();
        }

        if ($problems === []) {
            CLI::write('Brand check passed: the name is spelt ' . brand('name') . ' everywhere it appears, and comes from configuration.', 'green');

            return EXIT_SUCCESS;
        }

        foreach ($problems as $problem) {
            CLI::write($problem, 'yellow');
        }
        CLI::write(count($problems) . ' thing(s) to look at. Nothing was changed.', 'yellow');

        return EXIT_SUCCESS;
    }

    /** @return list<string> */
    private function scanFiles(): array
    {
        $problems = [];
        $root     = rtrim(ROOTPATH, DIRECTORY_SEPARATOR);
        $files    = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $file): bool {
                $path = $file->getPathname();
                // vendor and writable are not ours; delivery holds built
                // archives, which quote the brand by design.
                foreach (['/vendor', '/writable', '/.git', '/node_modules', '/public/assets/vendor', '/delivery'] as $skip) {
                    if (str_contains($path, $skip)) {
                        return false;
                    }
                }

                return $file->isDir() || preg_match('/\.(php|md|css|js|json|sql|sh|xml|html)$/', $file->getFilename()) === 1;
            },
        ));

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace($root, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            $contents = (string) file_get_contents($file->getPathname());

            // Strip the occurrences that are not the brand — the database
            // name, its accounts, the config class, the settings prefix —
            // before looking for a misspelling in what is left. Checking the
            // whole file would flag every line that merely mentions
            // config('Polyfix').
            $searchable = str_replace(self::ALLOWED_FRAGMENTS, '', $contents);

            foreach (self::WRONG as $wrong) {
                if (str_contains($searchable, $wrong) && ! $this->allowed($relative)) {
                    $problems[] = "{$relative}: misspelling \"{$wrong}\"";
                }
            }

            // The name written out where brand() should have been called.
            // A command's CLI group and an environment variable name are
            // identifiers, not the brand being displayed, so they are left be.
            if (! $this->allowed($relative) && preg_match_all('/[\'"]POLYFIX[^\'"]*[\'"]/', $contents, $matches)) {
                foreach (array_unique($matches[0]) as $literal) {
                    $value = trim($literal, '\'"');
                    if (preg_match('/^POLYFIX(_[A-Z0-9_]+)?$/', $value) === 1) {
                        continue;
                    }
                    $problems[] = "{$relative}: {$literal} is written out; use brand('name') or brand('shortName')";
                }
            }
        }

        return $problems;
    }

    /** Content an administrator can edit. Reported, never rewritten. */
    private function scanDatabase(): array
    {
        $problems = [];
        $db       = db_connect();
        $targets  = [
            'website_pages'  => ['slug', ['title', 'hero', 'sections']],
            'products'       => ['slug', ['name', 'tagline', 'short_description', 'description']],
            'seo_metadata'   => ['entity_key', ['title', 'description', 'og_title', 'og_description']],
            'faqs'           => ['id', ['question', 'answer']],
            'system_settings' => ['key', ['value']],
        ];

        foreach ($targets as $table => [$label, $columns]) {
            foreach ($db->table($table)->get()->getResultArray() as $row) {
                foreach ($columns as $column) {
                    foreach (self::WRONG as $wrong) {
                        if (str_contains((string) ($row[$column] ?? ''), $wrong)) {
                            $problems[] = "{$table}.{$column} [{$row[$label]}]: misspelling \"{$wrong}\" — correct it in the console";
                        }
                    }
                }
            }
        }

        return $problems;
    }

    private function allowed(string $relative): bool
    {
        foreach (self::ALLOWED_PATHS as $allowed) {
            if (str_starts_with($relative, $allowed)) {
                return true;
            }
        }

        return false;
    }
}
