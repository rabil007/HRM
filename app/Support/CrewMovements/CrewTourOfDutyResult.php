<?php

namespace App\Support\CrewMovements;

use Carbon\CarbonInterface;

/**
 * @phpstan-type TourResultArray array{
 *     tour_of_duty_days: int|null,
 *     suggested_planned_signoff_at: string|null,
 *     timezone: string
 * }
 */
final readonly class CrewTourOfDutyResult
{
    public function __construct(
        public ?int $tourOfDutyDays,
        public ?CarbonInterface $suggestedPlannedSignoffAt,
        public string $timezone,
    ) {}

    public function hasTour(): bool
    {
        return $this->tourOfDutyDays !== null && $this->tourOfDutyDays > 0;
    }

    /**
     * @return TourResultArray
     */
    public function toArray(): array
    {
        return [
            'tour_of_duty_days' => $this->tourOfDutyDays,
            'suggested_planned_signoff_at' => $this->suggestedPlannedSignoffAt
                ?->copy()
                ->timezone($this->timezone)
                ->toDateString(),
            'timezone' => $this->timezone,
        ];
    }
}
