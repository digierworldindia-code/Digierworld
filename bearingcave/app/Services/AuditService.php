<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLogModel;

/**
 * Append-only audit trail for business and security events.
 */
class AuditService
{
    public function __construct(private AuditLogModel $model = new AuditLogModel())
    {
    }

    /**
     * @param array{severity?:string,entity_type?:string,entity_id?:int,description?:string,old_values?:array,new_values?:array,company_id?:int,user_id?:int} $context
     */
    public function log(string $event, array $context = []): void
    {
        $request = service('request');
        $userId  = $context['user_id'] ?? (function_exists('auth') && auth()->loggedIn() ? (int) auth()->id() : null);

        $this->model->insert([
            'user_id'     => $userId,
            'company_id'  => $context['company_id'] ?? null,
            'event'       => $event,
            'severity'    => $context['severity'] ?? 'info',
            'entity_type' => $context['entity_type'] ?? null,
            'entity_id'   => $context['entity_id'] ?? null,
            'description' => isset($context['description']) ? mb_substr($context['description'], 0, 255) : null,
            'old_values'  => isset($context['old_values']) ? json_encode($this->redact($context['old_values'])) : null,
            'new_values'  => isset($context['new_values']) ? json_encode($this->redact($context['new_values'])) : null,
            'ip_address'  => method_exists($request, 'getIPAddress') ? $request->getIPAddress() : null,
            'user_agent'  => method_exists($request, 'getUserAgent') ? mb_substr((string) $request->getUserAgent(), 0, 255) : null,
        ]);
    }

    /**
     * Logs an access-control violation (e.g. trying to open another company's record).
     */
    public function security(string $event, string $description, array $context = []): void
    {
        $this->log($event, ['severity' => 'security', 'description' => $description] + $context);
    }

    private function redact(array $values): array
    {
        foreach ($values as $k => $v) {
            if (is_string($k) && preg_match('/(password|secret|_enc$|token|account_number)/i', $k)) {
                $values[$k] = '[redacted]';
            }
        }

        return $values;
    }
}
