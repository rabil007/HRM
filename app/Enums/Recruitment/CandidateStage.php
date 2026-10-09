<?php

namespace App\Enums\Recruitment;

enum CandidateStage: string
{
    case Applied = 'applied';
    case Screening = 'screening';
    case Interview = 'interview';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Applied => 'Applied',
            self::Screening => 'Screening',
            self::Interview => 'Interview',
            self::Rejected => 'Rejected',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Applied => 'secondary',
            self::Screening => 'warning',
            self::Interview => 'default',
            self::Rejected => 'destructive',
        };
    }

    /**
     * Stages shown as independent Kanban columns in Phase 1.
     *
     * @return list<self>
     */
    public static function kanbanColumns(): array
    {
        return [
            self::Applied,
            self::Screening,
            self::Interview,
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

    public function allowsRejection(): bool
    {
        return in_array($this, [self::Applied, self::Screening, self::Interview], true);
    }

    public function allowsSelection(): bool
    {
        return $this === self::Interview;
    }
}
