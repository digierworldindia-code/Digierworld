<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

abstract class AdminController extends BaseController
{
    protected function page(string $view, array $data = []): string
    {
        return view('admin/' . $view, $data + ['area' => 'admin']);
    }

    protected function can(string $perm): bool
    {
        return auth()->user()->can($perm);
    }

    /**
     * Staff users for assignment dropdowns.
     *
     * @param list<string> $groups
     */
    protected function staffOptions(array $groups): array
    {
        $rows = db_connect()->table('auth_groups_users g')->select('u.id, ai.secret AS email, g.group')
            ->join('users u', 'u.id = g.user_id')->join('auth_identities ai', "ai.user_id = u.id AND ai.type = 'email_password'")
            ->whereIn('g.group', array_merge($groups, ['superadmin']))->where('u.active', 1)->orderBy('ai.secret')->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['id']] = $r['email'] . ' (' . str_replace('_', ' ', $r['group']) . ')';
        }

        return $out;
    }
}
