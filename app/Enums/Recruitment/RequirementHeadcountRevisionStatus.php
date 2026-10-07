<?php

namespace App\Enums\Recruitment;

enum RequirementHeadcountRevisionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function waitingLabel(RequirementHeadcountRevisionInitiator $initiator): string
    {
        return match ($initiator) {
            RequirementHeadcountRevisionInitiator::Recruiter => 'Waiting for requester approval',
            RequirementHeadcountRevisionInitiator::Requester => 'Waiting for recruiter approval',
        };
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }
}
