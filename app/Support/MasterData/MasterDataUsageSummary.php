<?php

namespace App\Support\MasterData;

final readonly class MasterDataUsageSummary
{
    public function __construct(
        public int $globalUsageCount,
        public int $scopedUsageCount,
        public ?string $scopedUsageLabel,
        public bool $tenantScoped,
    ) {}

    public static function none(): self
    {
        return new self(0, 0, null, false);
    }

    public function isInUse(): bool
    {
        return $this->globalUsageCount > 0;
    }

    public function exposesUsageDetails(?int $companyId): bool
    {
        if (! $this->isInUse()) {
            return false;
        }

        if ($this->tenantScoped) {
            return $this->scopedUsageCount > 0;
        }

        if ($companyId === null) {
            return false;
        }

        return $this->scopedUsageCount === $this->globalUsageCount;
    }

    public function tooltip(?int $companyId): ?string
    {
        if (! $this->isInUse()) {
            return null;
        }

        if (! $this->exposesUsageDetails($companyId)) {
            return 'This record is currently in use. Delete is unavailable.';
        }

        if ($this->scopedUsageLabel !== null) {
            return "Used by {$this->scopedUsageLabel}. Delete is unavailable.";
        }

        $noun = $this->scopedUsageCount === 1 ? 'record' : 'records';

        return "Used by {$this->scopedUsageCount} {$noun}. Delete is unavailable.";
    }

    public function blockingMessage(string $displayName, ?int $companyId): string
    {
        if ($this->scopedUsageLabel !== null && $this->exposesUsageDetails($companyId)) {
            return "“{$displayName}” cannot be deleted because it is used by {$this->scopedUsageLabel}.";
        }

        return "“{$displayName}” cannot be deleted because it is currently in use.";
    }

    /**
     * @return array{
     *     is_in_use: bool,
     *     can_delete: bool,
     *     usage_count: int|null,
     *     usage_label: string|null
     * }
     */
    public function flags(bool $canDeletePermission, ?int $companyId): array
    {
        $inUse = $this->isInUse();
        $exposeDetails = $this->exposesUsageDetails($companyId);

        return [
            'is_in_use' => $inUse,
            'can_delete' => $canDeletePermission && ! $inUse,
            'usage_count' => $exposeDetails ? $this->scopedUsageCount : null,
            'usage_label' => $exposeDetails ? $this->scopedUsageLabel : null,
        ];
    }
}
