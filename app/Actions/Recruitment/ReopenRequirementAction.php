<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReopenRequirementAction
{
    public function execute(
        RecruitmentRequirement $requirement,
        int $userId,
        string $reason,
        ?string $newRequiredByDate = null,
    ): RecruitmentRequirement {
        return DB::transaction(function () use ($requirement, $userId, $reason, $newRequiredByDate): RecruitmentRequirement {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, [RequirementStatus::Completed, RequirementStatus::Cancelled], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only completed or cancelled requirements can be reopened.',
                ]);
            }

            if ($newRequiredByDate !== null) {
                $newCarbon = Carbon::parse($newRequiredByDate)->startOfDay();
                $receivedDate = $locked->request_received_date?->copy()->startOfDay();
                if ($receivedDate !== null && $newCarbon->lt($receivedDate)) {
                    throw ValidationException::withMessages([
                        'new_required_by_date' => 'The new deadline must be on or after the original request received date.',
                    ]);
                }
            }

            $updateData = [
                'status' => RequirementStatus::Open,
                'completed_at' => null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'updated_by' => $userId,
            ];

            if ($newRequiredByDate !== null) {
                $updateData['required_by_date'] = $newRequiredByDate;
            }

            $locked->update($updateData);

            $locked->lines()->whereIn('status', [RequirementLineStatus::Filled, RequirementLineStatus::Cancelled])->update([
                'status' => RequirementLineStatus::Open,
            ]);

            activity('recruitment')
                ->causedBy($userId)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                    'reason' => $reason,
                    'new_required_by_date' => $newRequiredByDate,
                ])
                ->log("Requirement {$locked->requirement_number} reopened. Reason: {$reason}");

            return $locked;
        });
    }
}
