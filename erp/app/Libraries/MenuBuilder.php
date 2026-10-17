<?php

namespace App\Libraries;

use Config\Menu;

/** Builds the permission-filtered sidebar with active-state detection. */
final class MenuBuilder
{
    /** @return list<array{label: string, icon: string, active: bool, items: list<array<string, mixed>>}> */
    public static function build(): array
    {
        $authz   = service('authz');
        $request = service('request');
        $path    = trim($request->getUri()->getPath(), '/');
        $query   = (string) $request->getUri()->getQuery();
        $current = $path . ($query !== '' ? '?' . $query : '');

        $out = [];
        foreach (config(Menu::class)->sections as $section) {
            $items = [];
            foreach ($section['items'] as $item) {
                if (! $authz->canAny($item['perms'])) {
                    continue;
                }
                $url    = trim($item['url'], '/');
                $active = self::isActive($url, $path, $current);
                $items[] = $item + ['active' => $active, 'href' => site_url($url === '' ? '/' : $url)];
            }
            if ($items !== []) {
                $out[] = [
                    'label'  => $section['label'],
                    'icon'   => $section['icon'],
                    'active' => in_array(true, array_column($items, 'active'), true),
                    'items'  => $items,
                ];
            }
        }

        return $out;
    }

    private static function isActive(string $url, string $path, string $current): bool
    {
        if ($url === '') {
            return $path === '';
        }
        if (str_contains($url, '?')) {
            return $current === $url || str_starts_with($current, $url . '&');
        }

        return $path === $url || str_starts_with($path, $url . '/');
    }
}
