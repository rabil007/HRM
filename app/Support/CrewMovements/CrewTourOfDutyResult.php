<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewTourOfDutySource;
use Carbon\CarbonInterface;

/**
 * @phpstan-type TourResultArray array{
 *     tour_of_duty_days: int|null,
 *     tour_of_duty_source: string|null,
 *     suggested_planned_signoff_at: string|null,
 *     timezone: string
 * }
 */
final readonly class CrewTourOfDutyResult
{
    public function __construct(
        public ?int $tourOfDutyDays,
        public ?CrewTourOfDutySource $tourOfDutySource,
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
            'tour_of_duty_source' => $this->tourOfDutySource?->value,
            'suggested_planned_signoff_at' => $this->suggestedPlannedSignoffAt
                ?->copy()
                ->timezone($this->timezone)
                ->toDateString(),
            'timezone' => $this->timezone,
        ];
    }
}
