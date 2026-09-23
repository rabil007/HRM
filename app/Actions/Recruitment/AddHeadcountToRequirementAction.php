<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AddHeadcountToRequirementAction
{
    /**
     * @param  list<array{position_id: int, additional_headcount: int, line_notes?: string|null}>  $lines
     */
    public function execute(
        int $requirementId,
        int $companyId,
        int $userId,
        array $lines,
        string $reason,
    ): RecruitmentRequirement {
        return DB::transaction(function () use ($requirementId, $companyId, $userId, $lines, $reason): RecruitmentRequirement {
            /** @var RecruitmentRequirement|null $requirement */
            $requirement = RecruitmentRequirement::query()
                ->where('company_id', $companyId)
                ->where('id', $requirementId)
                ->lockForUpdate()
                ->first();

            if ($requirement === null) {
                throw ValidationException::withMessages([
                    'requirement' => 'The selected target requirement no longer exists or belongs to another company.',
                ]);
            }

            if (! in_array($requirement->status, [RequirementStatus::Draft, RequirementStatus::Open, RequirementStatus::OnHold], true)) {
                throw ValidationException::withMessages([
                    'requirement' => "Cannot add headcount to requirement {$requirement->requirement_number} because it is {$requirement->status->label()}.",
                ]);
            }

            $changes = [];

            foreach ($lines as $lineInput) {
                $positionId = (int) $lineInput['position_id'];
                $additional = (int) $lineInput['additional_headcount'];

                /** @var RecruitmentRequirementLine|null $existingLine */
                $existingLine = $requirement->lines()
                    ->where('position_id', $positionId)
                    ->lockForUpdate()
                    ->first();

                if ($existingLine !== null) {
                    $oldHeadcount = (int) $existingLine->required_headcount;
                    $newHeadcount = $oldHeadcount + $additional;
                    $existingLine->update([
                        'required_headcount' => $newHeadcount,
                        'status' => RequirementLineStatus::Open, // Reopen line if was filled/on_hold
                    ]);

                    $changes[] = [
                        'position' => $existingLine->position?->title ?? "Position #{$positionId}",
                        'old_headcount' => $oldHeadcount,
                        'new_headcount' => $newHeadcount,
                        'added' => $additional,
                    ];
                } else {
                    $newLine = RecruitmentRequirementLine::create([
                        'company_id' => $companyId,
                        'recruitment_requirement_id' => $requirement->id,
                        'position_id' => $positionId,
                        'required_headcount' => $additional,
                        'line_notes' => $lineInput['line_notes'] ?? null,
                        'status' => RequirementLineStatus::Open,
                    ]);

                    $changes[] = [
                        'position' => $newLine->position?->title ?? "Position #{$positionId}",
                        'old_headcount' => 0,
                        'new_headcount' => $additional,
                        'added' => $additional,
                    ];
                }
            }

            $requirement->update(['updated_by' => $userId]);

            activity('recruitment')
                ->causedBy($userId)
                ->performedOn($requirement)
                ->withProperties([
                    'company_id' => $companyId,
                    'requirement_number' => $requirement->requirement_number,
                    'changes' => $changes,
                    'reason' => $reason,
                ])
                ->log("Added headcount to {$requirement->requirement_number}. Reason: {$reason}");

            return $requirement->load(['lines.position', 'client', 'project']);
        });
    }
}
