<?php

namespace App\Enums\Recruitment;

enum CandidateOfferStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::Sent => 'warning',
            self::Accepted => 'success',
            self::Rejected => 'destructive',
        };
    }

    public function isTerminalDecision(): bool
    {
        return in_array($this, [self::Accepted, self::Rejected], true);
    }

    public function allowsSilentOverwrite(): bool
    {
        return $this === self::Draft;
    }
}
