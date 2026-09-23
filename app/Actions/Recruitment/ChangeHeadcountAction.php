<?php

namespace App\Actions\Recruitment;

use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChangeHeadcountAction
{
    /**
     * @param  list<array{id: int, required_headcount: int}>  $lines
     */
    public function execute(
        RecruitmentRequirement $requirement,
        int $userId,
        array $lines,
        string $reason,
    ): RecruitmentRequirement {
        if (! $requirement->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => "Headcount cannot be updated for {$requirement->status->label()} requirement.",
            ]);
        }

        return DB::transaction(function () use ($requirement, $userId, $lines, $reason): RecruitmentRequirement {
            $changes = [];

            foreach ($lines as $lineInput) {
                /** @var RecruitmentRequirementLine|null $line */
                $line = $requirement->lines()->find($lineInput['id']);
                if ($line === null) {
                    continue;
                }

                $oldHeadcount = (int) $line->required_headcount;
                $newHeadcount = (int) $lineInput['required_headcount'];

                if ($oldHeadcount !== $newHeadcount) {
                    $line->update(['required_headcount' => $newHeadcount]);
                    $changes[] = [
                        'position' => $line->position?->title ?? "Position #{$line->position_id}",
                        'old_headcount' => $oldHeadcount,
                        'new_headcount' => $newHeadcount,
                    ];
                }
            }

            if ($changes !== []) {
                $requirement->update(['updated_by' => $userId]);

                activity('recruitment')
                    ->causedBy($userId)
                    ->performedOn($requirement)
                    ->withProperties([
                        'company_id' => $requirement->company_id,
                        'requirement_number' => $requirement->requirement_number,
                        'changes' => $changes,
                        'reason' => $reason,
                    ])
                    ->log("Headcount updated. Reason: {$reason}");
            }

            return $requirement->load('lines.position');
        });
    }
}
