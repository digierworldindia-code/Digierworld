<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guards the approved black · white · gold theme and the frontend asset
 * policy so later modules cannot drift: no light-only Bootstrap utilities,
 * no inline styles or colours in screens, no CDN or jQuery, readable
 * contrast for every text token.
 */
final class ThemeComplianceTest extends CIUnitTestCase
{
    /** @return array<string, string> screen views (printed PDF templates and the framework's dev error page excluded) */
    private function screenViews(): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(APPPATH . 'Views', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = str_replace('\\', '/', $f->getPathname());
            if (! str_ends_with($path, '.php') || str_contains($path, '/Views/pdf/') || str_ends_with($path, 'error_exception.php') || str_ends_with($path, 'welcome_message.php')) {
                continue;
            }
            $out[substr($path, strlen(APPPATH))] = (string) file_get_contents($path);
        }

        return $out;
    }

    private static function tokens(string $css): array
    {
        preg_match_all('/--(dgr-[a-z0-9-]+):\s*(#[0-9A-Fa-f]{6})\b/', $css, $m);

        return array_combine($m[1], $m[2]);
    }

    private static function contrast(string $a, string $b): float
    {
        $lum = static function (string $hex): float {
            $c = array_map(static fn ($v) => hexdec($v) / 255, str_split(ltrim($hex, '#'), 2));
            $c = array_map(static fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        [$l1, $l2] = [$lum($a), $lum($b)];

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    public function testScreensUseOnlyTheDesignSystem(): void
    {
        $forbidden = '/(?<![\w-])(bg-white|bg-light|bg-dark|text-dark|text-black|btn-dark|btn-outline-dark|btn-light|btn-gold|table-light|table-dark|alert-light|text-bg-[a-z]+|bg-(?:primary|secondary|success|danger|warning|info))(?![\w-])/';
        $problems  = [];
        foreach ($this->screenViews() as $file => $src) {
            if (preg_match_all($forbidden, $src, $m)) {
                $problems[] = $file . ': ' . implode(', ', array_unique($m[1]));
            }
            if (preg_match('/\sstyle\s*=\s*["\']/i', $src) || stripos($src, '<style') !== false) {
                $problems[] = $file . ': inline style (blocked by CSP style-src \'self\'; use a class in custom.css)';
            }
            // colours belong in dgr-theme.css; allowed: the browser theme-color meta and colour pickers that style printed PDFs
            $noMeta = preg_replace('/^.*(<meta name="theme-color"|type="color").*$/m', '', $src);
            if (preg_match('/#[0-9a-fA-F]{6}\b/', (string) $noMeta)) {
                $problems[] = $file . ': hard-coded colour';
            }
        }
        $this->assertSame([], $problems, "Screens must use dgr-theme.css tokens and components:\n" . implode("\n", $problems));
    }

    public function testEveryPageShellIsDarkAndLoadsOnlyLocalAssets(): void
    {
        foreach (['layouts/app.php', 'auth/layout.php', 'errors/html/_themed.php'] as $shell) {
            $this->assertStringContainsString('data-bs-theme="dark"', (string) file_get_contents(APPPATH . 'Views/' . $shell), $shell);
        }
        $problems = [];
        foreach ($this->screenViews() as $file => $src) {
            if (preg_match('#<(script|link)[^>]+(src|href)\s*=\s*["\'](https?:)?//#i', $src)) {
                $problems[] = $file . ': external script or stylesheet';
            }
            if (stripos($src, 'jquery') !== false) {
                $problems[] = $file . ': jQuery is not used in DGR Office';
            }
        }
        foreach (array_merge(glob(FCPATH . 'assets/js/*.js'), glob(FCPATH . 'assets/css/*.css')) as $f) {
            $src = (string) file_get_contents($f);
            if (preg_match('#(https?:)?//(?!www\.w3\.org/2000/svg)[a-z0-9.-]+\.[a-z]{2,}/#i', preg_replace('#/\*.*?\*/#s', '', $src))) {
                $problems[] = basename($f) . ': external URL';
            }
            if (stripos($src, '@import') !== false) {
                $problems[] = basename($f) . ': @import';
            }
            if (str_ends_with($f, '.js') && preg_match('/#[0-9a-fA-F]{6}\b/', $src)) {
                $problems[] = basename($f) . ': hard-coded colour (read tokens via DGR.chartTheme)';
            }
        }
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function testApprovedPaletteIsDefinedOnceAndReadable(): void
    {
        $css = (string) file_get_contents(FCPATH . 'assets/css/dgr-theme.css');
        $t   = self::tokens($css);
        $this->assertSame(
            ['dgr-bg' => '#0B0B0D', 'dgr-sidebar' => '#111114', 'dgr-card' => '#1A1A1E', 'dgr-text' => '#FFFFFF', 'dgr-text-2' => '#A1A1AA', 'dgr-gold' => '#D4AF37'],
            array_intersect_key($t, array_flip(['dgr-bg', 'dgr-sidebar', 'dgr-card', 'dgr-text', 'dgr-text-2', 'dgr-gold'])),
            'The approved black theme palette must not change.'
        );
        $custom = (string) preg_replace(['/\/\*.*?\*\//s', '/url\("data:[^"]*"\)/'], '', (string) file_get_contents(FCPATH . 'assets/css/custom.css'));
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $custom, 'custom.css must use dgr-theme.css tokens, not colours');

        // WCAG AA (4.5:1) for every text colour on every surface it is used on
        foreach (['dgr-text', 'dgr-text-2', 'dgr-gold', 'dgr-text-3'] as $fg) {
            foreach (['dgr-bg', 'dgr-sidebar', 'dgr-card', 'dgr-inset', 'dgr-raised'] as $bg) {
                $this->assertGreaterThanOrEqual(4.5, self::contrast($t[$fg], $t[$bg]), "{$fg} on {$bg}");
            }
        }
        $this->assertGreaterThanOrEqual(4.5, self::contrast($t['dgr-on-gold'], $t['dgr-gold']), 'text on gold buttons');
        foreach (['dgr-success', 'dgr-warning', 'dgr-danger', 'dgr-info'] as $status) {
            $this->assertGreaterThanOrEqual(4.5, self::contrast($t[$status], $t['dgr-card']), "{$status} on cards");
        }
    }
}
