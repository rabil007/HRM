<?php

namespace App\Actions\Recruitment;

use App\Models\RecruitmentRequirement;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExtendDeadlineAction
{
    public function execute(
        RecruitmentRequirement $requirement,
        int $userId,
        string $newDate,
        string $reason,
    ): RecruitmentRequirement {
        return DB::transaction(function () use ($requirement, $userId, $newDate, $reason): RecruitmentRequirement {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isEditable()) {
                throw ValidationException::withMessages([
                    'status' => "Deadline cannot be extended for {$locked->status->label()} requirement.",
                ]);
            }

            $newCarbon = Carbon::parse($newDate)->startOfDay();
            $currentDeadline = $locked->required_by_date?->copy()->startOfDay();
            $receivedDate = $locked->request_received_date?->copy()->startOfDay();

            if ($currentDeadline !== null && $newCarbon->lte($currentDeadline)) {
                throw ValidationException::withMessages([
                    'new_date' => 'The new deadline must be strictly after the current deadline.',
                ]);
            }

            if ($receivedDate !== null && $newCarbon->lt($receivedDate)) {
                throw ValidationException::withMessages([
                    'new_date' => 'The new deadline must be on or after the request received date.',
                ]);
            }

            $oldDate = $locked->required_by_date?->format('Y-m-d');

            $locked->update([
                'required_by_date' => $newDate,
                'updated_by' => $userId,
            ]);

            activity('recruitment')
                ->causedBy($userId)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                    'old_required_by_date' => $oldDate,
                    'new_required_by_date' => $newDate,
                    'reason' => $reason,
                ])
                ->log("Deadline extended from {$oldDate} to {$newDate}. Reason: {$reason}");

            return $locked;
        });
    }
}
