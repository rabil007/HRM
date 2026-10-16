<?php

namespace App\Support\CrewAccommodation;

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Enums\CrewPhaseCode;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Support\CrewMovements\CrewArrivalResolver;
use App\Support\Settings\CompanyTimezone;
use App\Support\Settings\PlatformDateFormatter;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ValidateCrewAccommodationStayEdit
{
    /**
     * Validate the proposed hotel stay against actual movement boundaries,
     * reopen rules, and overlapping hotel intervals on the same assignment.
     */
    public function assert(
        CrewAssignment $assignment,
        CrewAccommodationStay $stay,
        CarbonInterface $checkInDate,
        ?CarbonInterface $checkOutDate,
    ): void {
        if ($stay->accommodation_status !== CrewAccommodationStatus::Hotel) {
            throw ValidationException::withMessages([
                'hotel_id' => 'Only hotel accommodation records can be edited.',
            ]);
        }

        $timezone = CompanyTimezone::forCompanyId((int) $assignment->company_id);

        if ($checkOutDate !== null && $checkOutDate->lt($checkInDate)) {
            throw ValidationException::withMessages([
                'check_out_date' => 'Check-out date cannot be before check-in date.',
            ]);
        }

        $assignment->loadMissing('phases');

        $bounds = $this->resolveMovementBounds($assignment, $stay, $timezone);

        if ($bounds['min_check_in'] !== null && $checkInDate->lt($bounds['min_check_in'])) {
            throw ValidationException::withMessages([
                'check_in_date' => $bounds['min_check_in_message'],
            ]);
        }

        if ($bounds['max_check_out'] !== null) {
            if ($checkOutDate === null) {
                throw ValidationException::withMessages([
                    'check_out_date' => $bounds['checkout_required_message'],
                ]);
            }

            if ($checkOutDate->gt($bounds['max_check_out'])) {
                throw ValidationException::withMessages([
                    'check_out_date' => $bounds['max_check_out_message'],
                ]);
            }
        }

        $this->assertNoInvalidOverlap($assignment, $stay, $checkInDate, $checkOutDate);
    }

    /**
     * @return array{
     *     min_check_in: CarbonInterface|null,
     *     min_check_in_message: string,
     *     max_check_out: CarbonInterface|null,
     *     max_check_out_message: string,
     *     checkout_required_message: string
     * }
     */
    private function resolveMovementBounds(
        CrewAssignment $assignment,
        CrewAccommodationStay $stay,
        string $timezone,
    ): array {
        $formatter = PlatformDateFormatter::make();

        if ($stay->stay_type === CrewAccommodationStayType::PreJoin) {
            $arrival = $this->resolvePreJoinArrival($assignment, $stay, $timezone);
            $join = $this->resolvePreJoinVesselJoin($assignment, $stay, $timezone);

            return [
                'min_check_in' => $arrival,
                'min_check_in_message' => $arrival !== null
                    ? 'Check-in cannot be earlier than the actual arrival date ('.$formatter->date($arrival).').'
                    : 'Check-in cannot be earlier than the actual arrival date.',
                'max_check_out' => $join,
                'max_check_out_message' => $join !== null
                    ? 'Check-out cannot be later than the actual vessel joining date ('.$formatter->date($join).').'
                    : 'Check-out cannot be later than the actual vessel joining date.',
                'checkout_required_message' => $join !== null
                    ? 'Check-out is required because the crew member has already joined the vessel ('.$formatter->date($join).').'
                    : 'Check-out is required because the crew member has already joined the vessel.',
            ];
        }

        $disembarkation = $this->resolvePostSignoffDisembarkation($assignment, $stay, $timezone);
        $returnHome = $this->resolvePostSignoffReturnHome($assignment, $stay, $timezone);

        return [
            'min_check_in' => $disembarkation,
            'min_check_in_message' => $disembarkation !== null
                ? 'Check-in cannot be earlier than the actual disembarkation date ('.$formatter->date($disembarkation).').'
                : 'Check-in cannot be earlier than the actual disembarkation date.',
            'max_check_out' => $returnHome,
            'max_check_out_message' => $returnHome !== null
                ? 'Check-out cannot be later than the actual Home/Redeployment date ('.$formatter->date($returnHome).').'
                : 'Check-out cannot be later than the actual Home/Redeployment date.',
            'checkout_required_message' => $returnHome !== null
                ? 'Check-out is required because Home/Redeployment has already been completed ('.$formatter->date($returnHome).').'
                : 'Check-out is required because Home/Redeployment has already been completed.',
        ];
    }

    private function resolvePreJoinArrival(
        CrewAssignment $assignment,
        CrewAccommodationStay $stay,
        string $timezone,
    ): ?CarbonInterface {
        $startedFrom = $this->phaseById($assignment, $stay->started_from_phase_id);

        // Only P1A JoinStandby is authoritative employee arrival. P0 Pre-Arrival
        // activation must never be treated as arrival.
        if (
            $startedFrom !== null
            && $startedFrom->phase_code === CrewPhaseCode::JoinStandby
            && $startedFrom->actual_start_at !== null
        ) {
            return $startedFrom->actual_start_at->copy()->timezone($timezone)->startOfDay();
        }

        // Prefer the first authoritative P1A arrival on the assignment.
        $arrival = CrewArrivalResolver::timestamp($assignment);

        if ($arrival !== null) {
            return $arrival->copy()->timezone($timezone)->startOfDay();
        }

        // Historical / incomplete timelines with no recorded P1A arrival have no
        // lower check-in bound from movement dates. Callers must not invent one
        // from planned dates or P0 activation.
        return null;
    }

    private function resolvePreJoinVesselJoin(
        CrewAssignment $assignment,
        CrewAccommodationStay $stay,
        string $timezone,
    ): ?CarbonInterface {
        $phases = $this->sortedPhases($assignment);
        $startedFrom = $this->phaseById($assignment, $stay->started_from_phase_id);
        $minSequence = $startedFrom !== null ? (int) $startedFrom->sequence : null;

        $onVessel = $phases->first(function (CrewAssignmentPhase $phase) use ($minSequence): bool {
            if ($phase->phase_code !== CrewPhaseCode::OnVessel || $phase->actual_start_at === null) {
                return false;
            }

            if ($minSequence === null) {
                return true;
            }

            return (int) $phase->sequence > $minSequence;
        });

        return $onVessel?->actual_start_at?->copy()->timezone($timezone)->startOfDay();
    }

    private function resolvePostSignoffDisembarkation(
        CrewAssignment $assignment,
        CrewAccommodationStay $stay,
        string $timezone,
    ): ?CarbonInterface {
        $phases = $this->sortedPhases($assignment);
        $startedFrom = $this->phaseById($assignment, $stay->started_from_phase_id);

        if (
            $startedFrom !== null
            && $startedFrom->phase_code === CrewPhaseCode::DemobStandby
            && $startedFrom->actual_start_at !== null
        ) {
            return $startedFrom->actual_start_at->copy()->timezone($timezone)->startOfDay();
        }

        $maxSequence = $startedFrom !== null ? (int) $startedFrom->sequence : null;

        $onVessel = $phases
            ->filter(function (CrewAssignmentPhase $phase) use ($maxSequence): bool {
                if ($phase->phase_code !== CrewPhaseCode::OnVessel || $phase->actual_end_at === null) {
                    return false;
                }

                if ($maxSequence === null) {
                    return true;
                }

                return (int) $phase->sequence < $maxSequence;
            })
            ->sortByDesc(fn (CrewAssignmentPhase $phase): int => (int) $phase->sequence)
            ->first();

        return $onVessel?->actual_end_at?->copy()->timezone($timezone)->startOfDay();
    }

    private function resolvePostSignoffReturnHome(
        CrewAssignment $assignment,
        CrewAccommodationStay $stay,
        string $timezone,
    ): ?CarbonInterface {
        $phases = $this->sortedPhases($assignment);
        $startedFrom = $this->phaseById($assignment, $stay->started_from_phase_id);
        $minSequence = $startedFrom !== null ? (int) $startedFrom->sequence : null;

        if (
            $startedFrom !== null
            && $startedFrom->phase_code === CrewPhaseCode::DemobStandby
            && $startedFrom->actual_end_at !== null
        ) {
            return $startedFrom->actual_end_at->copy()->timezone($timezone)->startOfDay();
        }

        $home = $phases->first(function (CrewAssignmentPhase $phase) use ($minSequence): bool {
            if ($phase->phase_code !== CrewPhaseCode::HomeRedeploy || $phase->actual_start_at === null) {
                return false;
            }

            if ($minSequence === null) {
                return true;
            }

            return (int) $phase->sequence > $minSequence;
        });

        return $home?->actual_start_at?->copy()->timezone($timezone)->startOfDay();
    }

    private function assertNoInvalidOverlap(
        CrewAssignment $assignment,
        CrewAccommodationStay $stay,
        CarbonInterface $checkInDate,
        ?CarbonInterface $checkOutDate,
    ): void {
        $siblings = CrewAccommodationStay::query()
            ->where('company_id', $assignment->company_id)
            ->where('crew_assignment_id', $assignment->id)
            ->where('accommodation_status', CrewAccommodationStatus::Hotel)
            ->whereKeyNot($stay->id)
            ->whereNotNull('check_in_date')
            ->get(['id', 'check_in_date', 'check_out_date']);

        foreach ($siblings as $sibling) {
            if ($this->hotelIntervalsOverlap(
                $checkInDate,
                $checkOutDate,
                $sibling->check_in_date,
                $sibling->check_out_date,
            )) {
                throw ValidationException::withMessages([
                    'check_in_date' => 'This hotel stay overlaps another accommodation record on the same assignment.',
                ]);
            }
        }
    }

    /**
     * Inclusive hotel-day counting (checkout − check-in + 1) still allows a
     * same-day hotel transfer: checkout on day D and next check-in on day D is
     * a shared boundary, not an interior overlap. Interior positive overlaps
     * (for example 1–5 overlapping 4–7) remain invalid. Open stays are
     * unbounded on the open side.
     */
    private function hotelIntervalsOverlap(
        CarbonInterface $leftIn,
        ?CarbonInterface $leftOut,
        CarbonInterface $rightIn,
        ?CarbonInterface $rightOut,
    ): bool {
        $leftStart = $leftIn->copy()->startOfDay();
        $rightStart = $rightIn->copy()->startOfDay();
        $leftOutDay = $leftOut?->copy()->startOfDay();
        $rightOutDay = $rightOut?->copy()->startOfDay();

        // Half-open comparison on checkout day: shared boundary day is allowed.
        $leftEndsAfterRightStarts = $leftOutDay === null || $rightStart->lt($leftOutDay);
        $rightEndsAfterLeftStarts = $rightOutDay === null || $leftStart->lt($rightOutDay);

        return $leftEndsAfterRightStarts && $rightEndsAfterLeftStarts;
    }

    /**
     * @return Collection<int, CrewAssignmentPhase>
     */
    private function sortedPhases(CrewAssignment $assignment): Collection
    {
        $companyId = (int) $assignment->company_id;

        return $assignment->phases
            ->filter(function (CrewAssignmentPhase $phase) use ($companyId): bool {
                if (! array_key_exists('company_id', $phase->getAttributes()) && ! isset($phase->company_id)) {
                    return true;
                }

                return (int) $phase->company_id === $companyId;
            })
            ->sortBy(fn (CrewAssignmentPhase $phase): int => (int) $phase->sequence)
            ->values();
    }

    private function phaseById(CrewAssignment $assignment, ?int $phaseId): ?CrewAssignmentPhase
    {
        if ($phaseId === null) {
            return null;
        }

        return $this->sortedPhases($assignment)->first(
            fn (CrewAssignmentPhase $phase): bool => (int) $phase->id === $phaseId,
        );
    }
}
