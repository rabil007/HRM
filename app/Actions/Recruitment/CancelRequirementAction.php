<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Support\Recruitment\CancelPendingRequirementWorkflowRequests;
use App\Support\Recruitment\RecordRequirementStatusTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelRequirementAction
{
    public function execute(RecruitmentRequirement $requirement, int $userId, string $reason): RecruitmentRequirement
    {
        return DB::transaction(function () use ($requirement, $userId, $reason): RecruitmentRequirement {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($locked->status, [RequirementStatus::Completed, RequirementStatus::Cancelled], true)) {
                throw ValidationException::withMessages([
                    'status' => "A {$locked->status->label()} requirement cannot be cancelled.",
                ]);
            }

            $fromStatus = $locked->status;

            CancelPendingRequirementWorkflowRequests::forRequirementCancellation($locked, $userId);

            $locked->update([
                'status' => RequirementStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'updated_by' => $userId,
            ]);

            // Distinguish lines closed by requirement cancellation from lines already cancelled deliberately
            $activeLineIds = $locked->lines()
                ->where('status', '!=', RequirementLineStatus::Cancelled)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $locked->lines()
                ->whereIn('id', $activeLineIds)
                ->update([
                    'status' => RequirementLineStatus::Cancelled,
                ]);

            RecordRequirementStatusTransition::handle(
                $locked,
                $fromStatus,
                RequirementStatus::Cancelled,
                $userId,
                $reason,
                [
                    'cancelled_line_ids' => $activeLineIds,
                ],
            );

            activity('recruitment')
                ->causedBy($userId)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                    'reason' => $reason,
                    'cancelled_line_ids' => $activeLineIds,
                ])
                ->log("Requirement {$locked->requirement_number} cancelled. Reason: {$reason}");

            return $locked;
        });
    }
}
