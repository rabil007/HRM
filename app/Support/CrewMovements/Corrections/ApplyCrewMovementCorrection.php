<?php

namespace App\Support\CrewMovements\Corrections;

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Support\Positions\RankPositionBridge;
use Carbon\CarbonInterface;

final class ApplyCrewMovementCorrection
{
    public function __construct(
        private readonly CrewMovementCorrectionFieldCatalog $catalog = new CrewMovementCorrectionFieldCatalog,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public function apply(CrewAssignment $assignment, CrewAssignmentPhase $phase, array $values): void
    {
        $phaseAttributes = [];
        $assignmentAttributes = [];
        $details = $phase->details ?? [];
        $detailsChanged = false;

        foreach ($values as $field => $value) {
            $field = (string) $field;

            if ($this->catalog->isAssignmentField($field)) {
                $assignmentAttributes[$field] = $value;

                continue;
            }

            if ($this->catalog->isDetailsField($field)) {
                $key = substr($field, strlen('details.'));
                $details[$key] = $value;
                $detailsChanged = true;

                continue;
            }

            $phaseAttributes[$field] = $value;
        }

        if ($detailsChanged) {
            $phaseAttributes['details'] = $details;
        }

        if ($phaseAttributes !== []) {
            $phase->fill($phaseAttributes);
            $phase->save();
        }

        if ($assignmentAttributes !== []) {
            if (array_key_exists('rank_id', $assignmentAttributes)
                && ! array_key_exists('position_id', $assignmentAttributes)
                && $assignmentAttributes['rank_id'] !== null) {
                $mappedPositionId = RankPositionBridge::positionIdForRank(
                    (int) $assignment->company_id,
                    (int) $assignmentAttributes['rank_id'],
                );

                if ($mappedPositionId !== null) {
                    $assignmentAttributes['position_id'] = $mappedPositionId;
                }
            }

            if (array_key_exists('position_id', $assignmentAttributes)
                && $assignmentAttributes['position_id'] !== null
                && (! array_key_exists('rank_id', $assignmentAttributes)
                    || $assignmentAttributes['rank_id'] === null)) {
                $mappedRankId = RankPositionBridge::rankIdForPosition(
                    (int) $assignment->company_id,
                    (int) $assignmentAttributes['position_id'],
                );

                if ($mappedRankId !== null) {
                    $assignmentAttributes['rank_id'] = $mappedRankId;
                }
            }

            $assignment->fill($assignmentAttributes);
            $assignment->save();
        }

        $this->applyDerivedAssignmentDates($assignment, $phase);
    }

    private function applyDerivedAssignmentDates(CrewAssignment $assignment, CrewAssignmentPhase $phase): void
    {
        $phase->refresh();
        $assignment->refresh();

        if ($phase->phase_code === CrewPhaseCode::HomeRedeploy
            && $phase->status === CrewPhaseStatus::Completed
            && $phase->actual_end_at instanceof CarbonInterface) {
            $assignment->forceFill([
                'closed_at' => $phase->actual_end_at,
            ])->save();
        }
    }
}
