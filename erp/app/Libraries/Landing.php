<?php

namespace App\Libraries;

use Config\Landing as LandingConfig;
use Config\Menu;

/**
 * Picks the page a signed-in user lands on: the landing page of their
 * highest role that they may open, else the owner dashboard if allowed,
 * else their first menu page. Paths are relative to the site ('' = "/").
 */
final class Landing
{
    /** @return array<string, array{label: string, perms: list<string>}> area dashboards, then every menu page */
    public static function options(): array
    {
        $options = config(LandingConfig::class)->areas;
        foreach (config(Menu::class)->sections as $section) {
            foreach ($section['items'] as $item) {
                $path = trim($item['url'], '/');
                $options[$path] ??= ['label' => $section['label'] . ' › ' . $item['label'], 'perms' => $item['perms']];
            }
        }

        return $options;
    }

    /** True when the current user may open this landing option. */
    public static function canOpen(string $path): bool
    {
        $option = self::options()[$path] ?? null;

        return $option !== null && service('authz')->canAny($option['perms']);
    }

    public static function forCurrentUser(): string
    {
        $authz  = service('authz');
        $userId = $authz->currentUserId();
        if ($userId === null) {
            return '';
        }
        if ($authz->isSuper()) {
            return '';
        }
        $roles = db_connect()->table('user_roles ur')->select('r.landing_url')
            ->join('roles r', 'r.id = ur.role_id')
            ->where(['ur.user_id' => $userId, 'r.is_active' => 1])
            ->groupStart()->where('ur.expires_at', null)->orWhere('ur.expires_at >', date('Y-m-d H:i:s'))->groupEnd()
            ->orderBy('r.is_super', 'DESC')->orderBy('r.id', 'ASC')
            ->get()->getResultArray();
        foreach ($roles as $role) {
            if ($role['landing_url'] !== null && self::canOpen($role['landing_url'])) {
                return $role['landing_url'];
            }
        }
        if (self::canOpen('')) {
            return '';
        }

        return MenuBuilder::homeUrl() ?? '';
    }
}
