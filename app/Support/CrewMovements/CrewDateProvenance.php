<?php

namespace App\Support\CrewMovements;

use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use Carbon\CarbonInterface;

/**
 * Resolves provenance for planned/actual/payroll dates so UI never labels
 * payroll allocations or movement timestamps as user-entered planned dates.
 */
final class CrewDateProvenance
{
    public const UserEntered = 'user_entered';

    public const CrewPlanning = 'crew_planning';

    public const MovementActual = 'movement_actual';

    public const SystemDerived = 'system_derived';

    public const PayrollAllocation = 'payroll_allocation';

    public const WarningRange = 'warning_range';

    /**
     * @return array{value: string|null, origin: string|null, origin_label: string|null}
     */
    public static function plannedJoin(CrewAssignment $assignment, string $timezone): array
    {
        $raw = $assignment->planned_join_at;
        $origin = self::resolveAssignmentPlannedJoinOrigin($assignment);

        if ($origin === self::MovementActual) {
            return [
                'value' => null,
                'origin' => $origin,
                'origin_label' => self::label($origin),
            ];
        }

        return [
            'value' => self::toDateString($raw, $timezone),
            'origin' => $raw === null ? null : $origin,
            'origin_label' => $raw === null ? null : self::label($origin),
        ];
    }

    /**
     * @return array{value: string|null, origin: string|null, origin_label: string|null}
     */
    public static function plannedSignoff(CrewAssignment $assignment, string $timezone): array
    {
        $raw = $assignment->planned_signoff_at;

        if ($raw === null) {
            return [
                'value' => null,
                'origin' => null,
                'origin_label' => null,
            ];
        }

        $origin = $assignment->source === 'crew_planning'
            ? self::CrewPlanning
            : self::UserEntered;

        return [
            'value' => self::toDateString($raw, $timezone),
            'origin' => $origin,
            'origin_label' => self::label($origin),
        ];
    }

    /**
     * @return array{value: string|null, origin: string|null, origin_label: string|null}
     */
    public static function plannedTravel(CrewAssignment $assignment, string $timezone): array
    {
        $raw = $assignment->planned_travel_at;

        if ($raw === null) {
            return [
                'value' => null,
                'origin' => null,
                'origin_label' => null,
            ];
        }

        $origin = $assignment->source === 'crew_planning'
            ? self::CrewPlanning
            : self::UserEntered;

        return [
            'value' => self::toDateString($raw, $timezone),
            'origin' => $origin,
            'origin_label' => self::label($origin),
        ];
    }

    /**
     * @return array{start: string|null, end: string|null, origin: string|null, origin_label: string|null}
     */
    public static function phasePlanned(?CrewAssignmentPhase $phase, ?CrewAssignment $assignment, string $timezone): array
    {
        if ($phase === null || ($phase->planned_start_at === null && $phase->planned_end_at === null)) {
            return [
                'start' => null,
                'end' => null,
                'origin' => null,
                'origin_label' => null,
            ];
        }

        $origin = match (true) {
            $assignment?->source === 'crew_planning' => self::CrewPlanning,
            default => self::UserEntered,
        };

        return [
            'start' => self::toDateString($phase->planned_start_at, $timezone),
            'end' => self::toDateString($phase->planned_end_at, $timezone),
            'origin' => $origin,
            'origin_label' => self::label($origin),
        ];
    }

    /**
     * @return array{start: string|null, end: string|null, origin: string, origin_label: string}
     */
    public static function phaseActual(?CrewAssignmentPhase $phase, string $timezone): array
    {
        return [
            'start' => self::toDateString($phase?->actual_start_at, $timezone),
            'end' => self::toDateString($phase?->actual_end_at, $timezone),
            'origin' => self::MovementActual,
            'origin_label' => self::label(self::MovementActual),
        ];
    }

    public static function label(?string $origin): ?string
    {
        return match ($origin) {
            self::UserEntered => 'Entered on assignment',
            self::CrewPlanning => 'From Crew Planning',
            self::MovementActual => 'Derived from actual movement',
            self::SystemDerived => 'System derived',
            self::PayrollAllocation => 'Payroll allocation',
            self::WarningRange => 'Warning affected period',
            default => null,
        };
    }

    public static function resolveAssignmentPlannedJoinOrigin(CrewAssignment $assignment): ?string
    {
        if ($assignment->planned_join_at === null) {
            return null;
        }

        if (
            in_array($assignment->source, ['vessel_transfer', 'redeployment'], true)
            && self::matchesMovementTimestamp($assignment)
        ) {
            return self::MovementActual;
        }

        if ($assignment->source === 'crew_planning') {
            return self::CrewPlanning;
        }

        return self::UserEntered;
    }

    private static function matchesMovementTimestamp(CrewAssignment $assignment): bool
    {
        $planned = $assignment->planned_join_at;

        if ($planned === null) {
            return false;
        }

        if ($assignment->started_at !== null && $planned->equalTo($assignment->started_at)) {
            return true;
        }

        if (! $assignment->relationLoaded('phases')) {
            return false;
        }

        $firstActual = $assignment->phases
            ->sortBy('sequence')
            ->first(fn (CrewAssignmentPhase $phase): bool => $phase->actual_start_at !== null)
            ?->actual_start_at;

        return $firstActual !== null && $planned->equalTo($firstActual);
    }

    private static function toDateString(?CarbonInterface $value, string $timezone): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value->copy()->timezone($timezone)->toDateString();
    }
}
