<?php

namespace App\Services\Access;

use App\Services\Security\Authorization;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Billing;

/**
 * Record scope for billing documents. Each document type maps to a
 * permission namespace (billing.invoice / cash_memo / estimate / note) with
 * view_own (created_by = me) or view_all.
 */
class BillingAccess
{
    public function __construct(private readonly BaseConnection $db, private readonly Authorization $authz)
    {
    }

    public function ns(string $docType): string
    {
        return config(Billing::class)->docTypes[$docType]['perm'] ?? 'invalid';
    }

    public function can(string $action, string $docType): bool
    {
        $key = 'billing.' . $this->ns($docType) . '.' . $action;
        if ($this->ns($docType) === 'note' && $action === 'finalize') {
            $key = 'billing.issue_credit_note';
        }

        return $this->authz->can($key);
    }

    /** @return 'all'|'own'|'none' */
    public function viewMode(string $docType): string
    {
        $ns = $this->ns($docType);
        if ($this->authz->can("billing.{$ns}.view_all")) {
            return 'all';
        }
        // credit/debit notes have no "own" scope (view_all only), so only ask for keys that exist
        if ($this->authz->isRegistered("billing.{$ns}.view_own") && $this->authz->can("billing.{$ns}.view_own")) {
            return 'own';
        }

        return 'none';
    }

    /** Doc types the user can see at all. @return list<string> */
    public function visibleTypes(): array
    {
        return array_values(array_filter(array_keys(config(Billing::class)->docTypes), fn ($t) => $this->viewMode($t) !== 'none'));
    }

    /** Restrict a billing_documents query (alias $a) to what the user may see. */
    public function applyScope(BaseBuilder $builder, string $a = 'd', ?string $docType = null): BaseBuilder
    {
        $types = $docType !== null ? [$docType] : array_keys(config(Billing::class)->docTypes);
        $all   = [];
        $own   = [];
        foreach ($types as $t) {
            match ($this->viewMode($t)) {
                'all'   => $all[] = $t,
                'own'   => $own[] = $t,
                default => null,
            };
        }
        if ($all === [] && $own === []) {
            return $builder->where('1 = 0', null, false);
        }
        $uid = (int) $this->authz->currentUserId();
        $builder->groupStart();
        if ($all !== []) {
            $builder->whereIn("{$a}.doc_type", $all);
        }
        if ($own !== []) {
            $builder->orGroupStart()->whereIn("{$a}.doc_type", $own)->where("{$a}.created_by", $uid)->groupEnd();
        }

        return $builder->groupEnd();
    }

    /** @param array<string, mixed> $doc */
    public function canView(array $doc): bool
    {
        return match ($this->viewMode($doc['doc_type'])) {
            'all'   => true,
            'own'   => (int) $doc['created_by'] === (int) $this->authz->currentUserId(),
            default => false,
        };
    }

    public function canViewDocumentId(int $id): bool
    {
        $doc = $this->db->table('billing_documents')->select('id, doc_type, created_by')->where('id', $id)->get()->getRowArray();

        return $doc !== null && $this->canView($doc);
    }
}
