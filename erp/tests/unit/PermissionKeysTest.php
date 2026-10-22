<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Every permission key the code checks must exist in Config\Permissions.
 * An unregistered key always answers "no" and writes an ERROR line to the
 * log on every page view (found on a production install:
 * billing.note.view_own, billing.estimate.cancel).
 */
final class PermissionKeysTest extends CIUnitTestCase
{
    public function testEveryPermissionKeyUsedInTheCodeIsRegistered(): void
    {
        $registry = config('Permissions')->registry ?? null;
        $src      = (string) file_get_contents(APPPATH . 'Config/Permissions.php');
        preg_match_all("/'([a-z_]+(?:\\.[a-z_]+)+)'\\s*=>\\s*\\[/", $src, $m);
        $known = array_flip(is_array($registry) ? array_keys($registry) : $m[1]);

        $used = [];
        $add  = static function (string $key, string $where) use (&$used): void {
            if (preg_match('/^[a-z_]+(\.[a-z_]+)+$/', $key)) {
                $used[$key][] = $where;
            }
        };
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(APPPATH, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (! str_ends_with($path, '.php') || str_contains($path, '/Config/Permissions.php') || str_contains($path, '/Database/')) {
                continue;
            }
            $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));
            $where = substr($path, strlen(str_replace('\\', '/', APPPATH)));
            preg_match_all('/perm:([a-z_.,]+)/', $code, $filters);
            foreach ($filters[1] as $list) {
                foreach (explode(',', $list) as $key) {
                    $add($key, $where);
                }
            }
            preg_match_all("/(?:can|canAny)\\(\\s*(\\[[^\\]]*\\]|'[^']+')/", $code, $calls);
            preg_match_all("/'perms'\\s*=>\\s*(\\[[^\\]]*\\])/", $code, $menus);
            foreach (array_merge($calls[1], $menus[1]) as $expr) {
                preg_match_all("/'([^']+)'/", $expr, $keys);
                foreach ($keys[1] as $key) {
                    $add($key, $where);
                }
            }
        }

        // billing permissions built at run time: billing.<namespace>.<action> (BillingAccess::can)
        $actions = [];
        foreach (glob(APPPATH . 'Controllers/Billing/*.php') as $file) {
            preg_match_all("/access\\(\\)->can\\('([a-z_]+)'/", (string) file_get_contents($file), $found);
            $actions = array_merge($actions, $found[1]);
        }
        foreach (config('Billing')->docTypes as $type => $def) {
            foreach (array_unique($actions) as $action) {
                // finalized estimates/quotations/proformas are never cancelled (DocumentsController checks the type first)
                if ($action === 'cancel' && $def['perm'] === 'estimate') {
                    continue;
                }
                $add($def['perm'] === 'note' && $action === 'finalize' ? 'billing.issue_credit_note' : "billing.{$def['perm']}.{$action}", "BillingAccess ({$type})");
            }
        }

        $missing = array_diff_key($used, $known);
        $this->assertSame([], array_map(static fn ($w) => implode(', ', array_unique($w)), $missing), 'Permission keys checked in code but not registered in Config\Permissions');
        $this->assertGreaterThan(100, count($used), 'scan found the permission checks');
    }
}
