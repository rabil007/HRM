<?php

namespace App\Support\MasterData;

final readonly class MasterDataUsageSummary
{
    public function __construct(
        public int $usageCount,
        public ?string $usageLabel,
    ) {}

    public static function none(): self
    {
        return new self(0, null);
    }

    public function isInUse(): bool
    {
        return $this->usageCount > 0;
    }

    public function tooltip(): ?string
    {
        if (! $this->isInUse()) {
            return null;
        }

        if ($this->usageLabel !== null) {
            return "Used by {$this->usageLabel}. Delete is unavailable.";
        }

        $noun = $this->usageCount === 1 ? 'record' : 'records';

        return "Used by {$this->usageCount} {$noun}. Delete is unavailable.";
    }

    public function blockingMessage(string $displayName): string
    {
        if ($this->usageLabel !== null) {
            return "“{$displayName}” cannot be deleted because it is used by {$this->usageLabel}.";
        }

        return "“{$displayName}” cannot be deleted because it is currently in use.";
    }

    /**
     * @return array{
     *     is_in_use: bool,
     *     can_delete: bool,
     *     usage_count: int,
     *     usage_label: string|null
     * }
     */
    public function flags(bool $canDeletePermission): array
    {
        $inUse = $this->isInUse();

        return [
            'is_in_use' => $inUse,
            'can_delete' => $canDeletePermission && ! $inUse,
            'usage_count' => $this->usageCount,
            'usage_label' => $this->usageLabel,
        ];
    }
}
