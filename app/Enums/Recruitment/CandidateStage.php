<?php

namespace App\Enums\Recruitment;

enum CandidateStage: string
{
    case Applied = 'applied';
    case Screening = 'screening';
    case Interview = 'interview';
    case OfferJol = 'offer_jol';
    /**
     * Historical compatibility only. No new candidates enter Joining.
     * Active workflow is Offer/JOL → Joined; joining preparation lives on Offer/JOL
     * when the current offer is Accepted.
     */
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
     * Stages shown as independent Kanban columns (active pipeline).
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
            self::Joined,
            self::Rejected,
        ];
    }

    /**
     * Stages available for active filters and reporting groups.
     * Historical Joining is omitted so it cannot be selected as a live stage.
     *
     * @return list<self>
     */
    public static function activeStages(): array
    {
        return self::kanbanColumns();
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
        return in_array($this, [self::OfferJol, self::Joined], true)
            || $this === self::Joining; // historical rows still count as offer-workflow
    }

    public function isHistoricalOnly(): bool
    {
        return $this === self::Joining;
    }
}
