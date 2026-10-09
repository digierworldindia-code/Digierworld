<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Immutable description of "who is looking" used to enforce catalog
 * visibility, country restrictions and identity masking consistently
 * across web pages, APIs, exports, RFQ matching and sitemaps.
 */
final class Viewer
{
    public function __construct(
        public readonly ?int $userId = null,
        public readonly ?array $company = null,
        public readonly ?string $country = null,
        public readonly bool $isStaff = false,
        public readonly bool $isVerifiedBuyer = false,
        public readonly bool $canSeeRestricted = false,
    ) {
    }

    public static function guest(): self
    {
        return new self();
    }

    public function companyId(): ?int
    {
        return $this->company ? (int) $this->company['id'] : null;
    }

    public function isGuest(): bool
    {
        return $this->userId === null;
    }

    public function isBuyer(): bool
    {
        return ($this->company['company_type'] ?? null) === 'buyer';
    }

    public function isSupplier(): bool
    {
        return ($this->company['company_type'] ?? null) === 'supplier';
    }

    public function owns(array $product): bool
    {
        return $this->companyId() !== null && (int) $product['company_id'] === $this->companyId();
    }
}
