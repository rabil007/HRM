<?php

namespace App\Support\CrewMovements\Corrections;

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignmentPhase;

final class CrewMovementCorrectionFieldCatalog
{
    /**
     * @return list<string>
     */
    public function phaseFields(CrewAssignmentPhase $phase): array
    {
        $fields = ['actual_start_at', 'remarks'];

        if ($phase->status === CrewPhaseStatus::Completed) {
            $fields[] = 'actual_end_at';
        }

        if ($phase->phase_code === CrewPhaseCode::Training) {
            $fields[] = 'details.provider';
            $fields[] = 'details.course';
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    public function assignmentFields(CrewAssignmentPhase $phase): array
    {
        if ($phase->phase_code !== CrewPhaseCode::OnVessel) {
            return [];
        }

        return [
            'vessel_id',
            'rank_id',
            'client_id',
            'company_visa_type_id',
        ];
    }

    /**
     * @return list<string>
     */
    public function allowedFields(CrewAssignmentPhase $phase): array
    {
        return [
            ...$this->phaseFields($phase),
            ...$this->assignmentFields($phase),
        ];
    }

    public function isAssignmentField(string $field): bool
    {
        return in_array($field, [
            'vessel_id',
            'rank_id',
            'client_id',
            'company_visa_type_id',
        ], true);
    }

    public function isDateTimeField(string $field): bool
    {
        return in_array($field, ['actual_start_at', 'actual_end_at'], true);
    }

    public function isDetailsField(string $field): bool
    {
        return str_starts_with($field, 'details.');
    }
}
