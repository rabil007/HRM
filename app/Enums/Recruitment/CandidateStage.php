<?php

namespace App\Enums\Recruitment;

enum CandidateStage: string
{
    case Applied = 'applied';
    case Screening = 'screening';
    case Interview = 'interview';
    case OfferJol = 'offer_jol';
    case Joining = 'joining';
    case Joined = 'joined';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Applied => 'Applied',
            self::Screening => 'Screening',
            self::Interview => 'Interview',
            self::OfferJol => 'Offer/JOL',
            self::Joining => 'Joining',
            self::Joined => 'Joined',
            self::Rejected => 'Rejected',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Applied => 'secondary',
            self::Screening => 'warning',
            self::Interview => 'default',
            self::OfferJol => 'warning',
            self::Joining => 'warning',
            self::Joined => 'success',
            self::Rejected => 'destructive',
        };
    }

    /**
     * Stages shown as independent Kanban columns.
     *
     * @return list<self>
     */
    public static function kanbanColumns(): array
    {
        return [
            self::Applied,
            self::Screening,
            self::Interview,
            self::OfferJol,
            self::Joining,
            self::Joined,
            self::Rejected,
        ];
    }

    public function allowsForwardMove(): bool
    {
        return in_array($this, [self::Applied, self::Screening], true);
    }

    public function nextStage(): ?self
    {
        return match ($this) {
            self::Applied => self::Screening,
            self::Screening => self::Interview,
            default => null,
        };
    }

    /**
     * Interview-path rejection (sets interview_outcome when from Interview).
     * Offer decline uses the offer decide action instead.
     */
    public function allowsRejection(): bool
    {
        return in_array($this, [self::Applied, self::Screening, self::Interview], true);
    }

    public function allowsSelection(): bool
    {
        return $this === self::Interview;
    }

    public function isOfferWorkflowStage(): bool
    {
        return in_array($this, [self::OfferJol, self::Joining, self::Joined], true);
    }
}
