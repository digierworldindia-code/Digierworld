<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApi;

class Account extends BaseApi
{
    public function me()
    {
        $u = auth('tokens')->user();
        $c = service('companyContext')->company((int) $u->id);

        return $this->ok(['id' => (int) $u->id, 'email' => $u->email, 'groups' => $u->getGroups(),
            'company' => $c ? ['name' => $c['legal_name'], 'type' => $c['company_type'], 'verification_status' => $c['verification_status'], 'plan' => service('entitlements')->plan($c)['code'] ?? null] : null]);
    }

    public function notifications()
    {
        $rows = db_connect()->table('notifications')->select('id, type, title, body, link, read_at, created_at')->where('user_id', auth('tokens')->id())->orderBy('id', 'DESC')->limit(50)->get()->getResultArray();

        return $this->ok($rows);
    }
}
