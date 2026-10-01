<?php

namespace App\Enums\Recruitment;

enum RequirementStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Returned = 'returned';
    case Open = 'open';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending Approval',
            self::Returned => 'Returned',
            self::Open => 'Open',
            self::OnHold => 'On Hold',
            self::Completed => 'Filled',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::PendingApproval => 'warning',
            self::Returned => 'destructive',
            self::Open => 'success',
            self::OnHold => 'warning',
            self::Completed => 'default',
            self::Cancelled => 'destructive',
        };
    }

    /**
     * Generic form editing (not audited headcount/deadline actions).
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Returned], true);
    }

    /**
     * Audited operational adjustments (extend deadline / change headcount).
     */
    public function allowsAuditedAdjustments(): bool
    {
        return in_array($this, [
            self::Draft,
            self::Returned,
            self::Open,
            self::OnHold,
        ], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [
            self::Draft,
            self::PendingApproval,
            self::Returned,
            self::Open,
        ], true);
    }

    public function allowsPendingReassignment(): bool
    {
        return $this === self::PendingApproval;
    }

    /**
     * @return list<self>
     */
    public static function activeListStatuses(): array
    {
        return [
            self::Draft,
            self::PendingApproval,
            self::Returned,
            self::Open,
        ];
    }

    /**
     * @return list<self>
     */
    public static function historyListStatuses(): array
    {
        return [
            self::Completed,
            self::Cancelled,
        ];
    }
}
