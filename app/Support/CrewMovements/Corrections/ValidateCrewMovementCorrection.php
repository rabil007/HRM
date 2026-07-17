<?php

namespace App\Support\CrewMovements\Corrections;

use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyVisaType;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\Rank;
use App\Models\Vessel;
use App\Support\CrewMovements\CrewMovementMasterDataGuard;
use Carbon\Carbon;
use Carbon\CarbonInterface;

final class ValidateCrewMovementCorrection
{
    public function __construct(
        private readonly CrewMovementCorrectionFieldCatalog $catalog = new CrewMovementCorrectionFieldCatalog,
        private readonly CrewMovementCorrectionValueSnapshot $snapshot = new CrewMovementCorrectionValueSnapshot,
        private readonly CrewMovementMasterDataGuard $masterDataGuard = new CrewMovementMasterDataGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $proposed
     * @return array<string, mixed>
     */
    public function validateProposed(
        CrewAssignment $assignment,
        CrewAssignmentPhase $phase,
        array $proposed,
        ?int $ignoreCorrectionId = null,
    ): array {
        $this->assertPhaseBelongsToAssignment($assignment, $phase);
        $this->assertPhaseIsCorrectable($phase);
        $this->assertNoPendingConflict($phase, $ignoreCorrectionId);

        if ($proposed === []) {
            throw CrewMovementException::make('At least one proposed change is required.', 'correction_empty');
        }

        $allowed = $this->catalog->allowedFields($phase);
        $normalized = [];

        foreach ($proposed as $field => $value) {
            $field = (string) $field;

            if (! in_array($field, $allowed, true)) {
                throw CrewMovementException::make(
                    sprintf('Field [%s] cannot be corrected on this phase.', $field),
                    'correction_unsupported_field',
                );
            }

            if ($this->isTopologyField($field)) {
                throw CrewMovementException::make(
                    'Structural phase fields cannot be corrected.',
                    'correction_topology_forbidden',
                );
            }

            $normalizedValue = $this->normalizeProposedValue($assignment, $phase, $field, $value);
            $current = $this->snapshot->serializeValue(
                $field,
                $this->snapshot->rawValue($assignment, $phase, $field),
            );

            if ($this->valuesEqual($current, $normalizedValue)) {
                continue;
            }

            if ($normalizedValue === null && $current !== null) {
                throw CrewMovementException::make(
                    sprintf('Field [%s] cannot be cleared via correction.', $field),
                    'correction_null_forbidden',
                );
            }

            if ($field === 'actual_end_at' && $phase->status === CrewPhaseStatus::Active) {
                throw CrewMovementException::make(
                    'Active phases cannot receive an actual end via correction.',
                    'correction_active_end_forbidden',
                );
            }

            $this->assertMasterData($assignment, $field, $normalizedValue);

            $normalized[$field] = $normalizedValue;
        }

        if ($normalized === []) {
            throw CrewMovementException::make(
                'Proposed values match the current record. No correction is needed.',
                'correction_noop',
            );
        }

        $this->assertTimeline($assignment, $phase, $normalized);

        return $normalized;
    }

    public function assertPending(CrewMovementCorrection $correction): void
    {
        if ($correction->status !== CrewMovementCorrectionStatus::Pending) {
            throw CrewMovementException::make(
                'Only pending corrections can be decided.',
                'correction_not_pending',
            );
        }
    }

    public function assertTenant(CrewMovementCorrection $correction, int $companyId): void
    {
        if ((int) $correction->company_id !== $companyId) {
            throw CrewMovementException::make(
                'Correction does not belong to this company.',
                'correction_company_mismatch',
            );
        }
    }

    private function assertPhaseBelongsToAssignment(CrewAssignment $assignment, CrewAssignmentPhase $phase): void
    {
        if ((int) $phase->crew_assignment_id !== (int) $assignment->id
            || (int) $phase->company_id !== (int) $assignment->company_id) {
            throw CrewMovementException::make(
                'Phase does not belong to this assignment.',
                'correction_phase_mismatch',
            );
        }
    }

    private function assertPhaseIsCorrectable(CrewAssignmentPhase $phase): void
    {
        if (! in_array($phase->status, [CrewPhaseStatus::Active, CrewPhaseStatus::Completed], true)) {
            throw CrewMovementException::make(
                'Only active or completed phases can be corrected.',
                'correction_phase_status',
            );
        }

        if ($phase->actual_start_at === null) {
            throw CrewMovementException::make(
                'Only recorded phases with an actual start can be corrected.',
                'correction_phase_unrecorded',
            );
        }
    }

    private function assertNoPendingConflict(CrewAssignmentPhase $phase, ?int $ignoreCorrectionId): void
    {
        $query = CrewMovementCorrection::query()
            ->where('crew_assignment_phase_id', $phase->id)
            ->where('status', CrewMovementCorrectionStatus::Pending);

        if ($ignoreCorrectionId !== null) {
            $query->whereKeyNot($ignoreCorrectionId);
        }

        if ($query->exists()) {
            throw CrewMovementException::make(
                'A pending correction already exists for this phase.',
                'correction_pending_exists',
            );
        }
    }

    private function isTopologyField(string $field): bool
    {
        return in_array($field, [
            'phase_code',
            'status',
            'sequence',
            'current_phase_id',
            'employee_id',
            'company_id',
        ], true);
    }

    private function normalizeProposedValue(
        CrewAssignment $assignment,
        CrewAssignmentPhase $phase,
        string $field,
        mixed $value,
    ): mixed {
        if ($this->catalog->isDateTimeField($field)) {
            if ($value === null || $value === '') {
                return null;
            }

            return $this->parseTimestamp((int) $assignment->company_id, (string) $value);
        }

        if ($this->catalog->isDetailsField($field) || $field === 'remarks') {
            if ($value === null) {
                return null;
            }

            $trimmed = trim((string) $value);

            return $trimmed === '' ? null : $trimmed;
        }

        if ($this->catalog->isAssignmentField($field)) {
            if ($value === null || $value === '') {
                return null;
            }

            return (int) $value;
        }

        return $this->snapshot->serializeValue($field, $value);
    }

    private function assertMasterData(CrewAssignment $assignment, string $field, mixed $value): void
    {
        if ($value === null || ! $this->catalog->isAssignmentField($field)) {
            return;
        }

        $map = [
            'vessel_id' => [Vessel::class, 'vessel'],
            'rank_id' => [Rank::class, 'rank'],
            'client_id' => [Client::class, 'client'],
            'company_visa_type_id' => [CompanyVisaType::class, 'company visa type'],
        ];

        [$modelClass, $label] = $map[$field];
        $this->masterDataGuard->assertUsable((int) $assignment->company_id, $modelClass, (int) $value, $label);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function assertTimeline(
        CrewAssignment $assignment,
        CrewAssignmentPhase $phase,
        array $normalized,
    ): void {
        $start = array_key_exists('actual_start_at', $normalized)
            ? $normalized['actual_start_at']
            : $phase->actual_start_at;
        $end = array_key_exists('actual_end_at', $normalized)
            ? $normalized['actual_end_at']
            : $phase->actual_end_at;

        if ($start instanceof CarbonInterface && $end instanceof CarbonInterface && $end->lt($start)) {
            throw CrewMovementException::make(
                'Phase actual end cannot be before actual start.',
                'correction_phase_actual_range',
            );
        }

        $assignment->loadMissing('phases');

        $previous = $assignment->phases
            ->filter(fn (CrewAssignmentPhase $candidate): bool => $candidate->sequence < $phase->sequence
                && $candidate->status !== CrewPhaseStatus::Cancelled
                && $candidate->id !== $phase->id)
            ->sortByDesc(fn (CrewAssignmentPhase $candidate): int => (int) $candidate->sequence)
            ->first();

        $next = $assignment->phases
            ->filter(fn (CrewAssignmentPhase $candidate): bool => $candidate->sequence > $phase->sequence
                && $candidate->status !== CrewPhaseStatus::Cancelled
                && $candidate->id !== $phase->id)
            ->sortBy(fn (CrewAssignmentPhase $candidate): int => (int) $candidate->sequence)
            ->first();

        if ($previous?->actual_end_at !== null
            && $start instanceof CarbonInterface
            && $start->lt($previous->actual_end_at)) {
            throw CrewMovementException::make(
                'Corrected start cannot be before the previous phase end.',
                'correction_previous_boundary',
            );
        }

        if ($next?->actual_start_at !== null
            && $end instanceof CarbonInterface
            && $end->gt($next->actual_start_at)) {
            throw CrewMovementException::make(
                'Corrected end cannot be after the next phase start.',
                'correction_next_boundary',
            );
        }

        if ($next?->actual_start_at !== null
            && $end === null
            && $start instanceof CarbonInterface
            && $start->gt($next->actual_start_at)) {
            throw CrewMovementException::make(
                'Corrected start cannot be after the next phase start.',
                'correction_next_start_boundary',
            );
        }

        if ($phase->phase_code === CrewPhaseCode::OnVessel
            && $phase->status === CrewPhaseStatus::Completed
            && ($start === null || $end === null)) {
            throw CrewMovementException::make(
                'Completed on-vessel phases must keep both actual start and end.',
                'correction_p4_incomplete',
            );
        }
    }

    private function valuesEqual(mixed $left, mixed $right): bool
    {
        if ($left instanceof CarbonInterface && $right instanceof CarbonInterface) {
            return $left->equalTo($right);
        }

        if ($left instanceof CarbonInterface) {
            $left = $left->toIso8601String();
        }

        if ($right instanceof CarbonInterface) {
            $right = $right->toIso8601String();
        }

        return (string) ($left ?? '') === (string) ($right ?? '');
    }

    private function parseTimestamp(int $companyId, string $value): CarbonInterface
    {
        $timezone = (string) (Company::query()->whereKey($companyId)->value('timezone')
            ?? config('app.timezone', 'UTC'));

        try {
            return Carbon::parse($value, $timezone);
        } catch (\Throwable $e) {
            throw CrewMovementException::make('Invalid timestamp value.', 'invalid_timestamp', previous: $e);
        }
    }
}
