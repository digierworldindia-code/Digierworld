<?php

declare(strict_types=1);

namespace App\Controllers\Api;

/**
 * Inbound payment-provider webhooks (CSRF exempt; authenticated by provider signature).
 */
class Webhooks extends BaseApi
{
    public function payments(string $provider)
    {
        if (! preg_match('/^[a-z_]{2,40}$/', $provider)) {
            return $this->error('Unknown provider', 404);
        }
        $headers = [];
        foreach ($this->request->headers() as $name => $h) {
            $headers[strtolower($name)] = $h->getValueLine();
        }
        $result = service('billing')->handleWebhook($provider, (string) $this->request->getBody(), $headers);

        return $result['ok'] ? $this->ok($result) : $this->error('Invalid signature or payload', 400);
    }
}
