<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewPlannedSignoffSource;
use App\Exceptions\CrewMovementException;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Applies the user's Join Vessel Planned Sign-Off choice against a resolved Tour.
 *
 * Choice values:
 * - tour_of_duty: use Tour of Duty suggestion
 * - existing_plan: keep existing planned date
 * - manual_override: enter another date (requires reason)
 */
final class CrewJoinVesselSignoffApplier
{
    public const CHOICE_TOUR = 'tour_of_duty';

    public const CHOICE_EXISTING = 'existing_plan';

    public const CHOICE_MANUAL = 'manual_override';

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     planned_signoff_at: CarbonInterface|null,
     *     planned_signoff_source: CrewPlannedSignoffSource|null,
     *     planned_signoff_override_reason: string|null,
     *     tour_of_duty_days: int|null,
     *     tour_of_duty_source: string|null
     * }
     */
    public function apply(
        CrewTourOfDutyResult $tour,
        array $payload,
        ?CarbonInterface $existingPlannedSignoff,
        CarbonInterface $actualJoinAt,
    ): array {
        $choice = $this->resolveChoice($payload, $tour, $existingPlannedSignoff);
        $explicitChoice = isset($payload['planned_signoff_choice'])
            && trim((string) $payload['planned_signoff_choice']) !== '';
        $overrideReason = isset($payload['planned_signoff_override_reason'])
            ? trim((string) $payload['planned_signoff_override_reason'])
            : null;

        $planned = null;
        $source = null;
        $reason = null;

        if ($choice === self::CHOICE_TOUR) {
            $planned = $tour->suggestedPlannedSignoffAt;
            $source = CrewPlannedSignoffSource::TourOfDuty;
        } elseif ($choice === self::CHOICE_EXISTING) {
            $planned = $existingPlannedSignoff;
            $source = CrewPlannedSignoffSource::ExistingPlan;
        } elseif ($choice === self::CHOICE_MANUAL) {
            if ($explicitChoice) {
                // Explicit manual override is authoritative: date + reason are required.
                $planned = $this->parseManualDate($payload, $tour->timezone);
                $source = CrewPlannedSignoffSource::ManualOverride;
                $reason = $overrideReason;

                if ($reason === null || $reason === '') {
                    throw ValidationException::withMessages([
                        'planned_signoff_override_reason' => 'A reason is required when entering another Planned Sign-Off date.',
                    ]);
                }
            } elseif (isset($payload['planned_signoff_at']) && filled($payload['planned_signoff_at'])) {
                // Legacy callers that omit planned_signoff_choice but send a date.
                $planned = $this->parseManualDate($payload, $tour->timezone);
                $source = CrewPlannedSignoffSource::ManualOverride;
                $reason = $overrideReason;
            } else {
                // No tour and no date: allow join with a missing Planned Sign-Off warning.
                $planned = null;
                $source = null;
                $reason = null;
            }
        }

        if ($choice === self::CHOICE_EXISTING && $existingPlannedSignoff === null) {
            throw ValidationException::withMessages([
                'planned_signoff_choice' => 'There is no existing planned sign-off date to keep.',
            ]);
        }

        if ($choice === self::CHOICE_TOUR && ! $tour->hasTour()) {
            throw ValidationException::withMessages([
                'planned_signoff_choice' => 'No Tour of Duty suggestion is available for this rank.',
            ]);
        }

        if ($planned !== null) {
            $joinDate = $actualJoinAt->copy()->timezone($tour->timezone)->startOfDay();
            $signoffDate = $planned->copy()->timezone($tour->timezone)->startOfDay();

            if ($signoffDate->lt($joinDate)) {
                throw ValidationException::withMessages([
                    'planned_signoff_at' => 'The planned sign-off cannot be before the actual vessel join date.',
                ]);
            }
        }

        return [
            'planned_signoff_at' => $planned,
            'planned_signoff_source' => $source,
            'planned_signoff_override_reason' => ($choice === self::CHOICE_MANUAL && $planned !== null)
                ? $reason
                : null,
            'tour_of_duty_days' => $tour->tourOfDutyDays,
            'tour_of_duty_source' => $tour->tourOfDutySource?->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveChoice(
        array $payload,
        CrewTourOfDutyResult $tour,
        ?CarbonInterface $existingPlannedSignoff,
    ): string {
        $raw = isset($payload['planned_signoff_choice'])
            ? trim((string) $payload['planned_signoff_choice'])
            : '';

        if ($raw !== '') {
            if (! in_array($raw, [self::CHOICE_TOUR, self::CHOICE_EXISTING, self::CHOICE_MANUAL], true)) {
                throw ValidationException::withMessages([
                    'planned_signoff_choice' => 'Invalid Planned Sign-Off choice.',
                ]);
            }

            return $raw;
        }

        // Legacy / default behaviour when choice omitted:
        // prefer explicit planned_signoff_at as manual, else existing, else tour suggestion.
        if (isset($payload['planned_signoff_at']) && filled($payload['planned_signoff_at'])) {
            return self::CHOICE_MANUAL;
        }

        if ($existingPlannedSignoff !== null) {
            return self::CHOICE_EXISTING;
        }

        if ($tour->hasTour()) {
            return self::CHOICE_TOUR;
        }

        return self::CHOICE_MANUAL;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function parseManualDate(array $payload, string $timezone): CarbonInterface
    {
        if (! isset($payload['planned_signoff_at']) || ! filled($payload['planned_signoff_at'])) {
            throw ValidationException::withMessages([
                'planned_signoff_at' => 'Please enter a Planned Sign-Off date.',
            ]);
        }

        try {
            return Carbon::parse((string) $payload['planned_signoff_at'], $timezone)->startOfDay();
        } catch (\Throwable $e) {
            throw CrewMovementException::make(
                'Invalid planned sign-off date.',
                'invalid_timestamp',
                previous: $e,
            );
        }
    }
}
