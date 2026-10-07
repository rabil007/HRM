<?php

namespace App\Support\Recruitment;

use App\Models\RecruitmentRequirement;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

final class ValidateRequirementDeadlineExtension
{
    public static function assertNewDeadline(RecruitmentRequirement $requirement, string $newDate): Carbon
    {
        $newCarbon = Carbon::parse($newDate)->startOfDay();
        $currentDeadline = $requirement->required_by_date?->copy()->startOfDay();
        $receivedDate = $requirement->request_received_date?->copy()->startOfDay();

        if ($currentDeadline !== null && $newCarbon->lte($currentDeadline)) {
            throw ValidationException::withMessages([
                'new_date' => 'The new deadline must be strictly after the current deadline.',
            ]);
        }

        if ($receivedDate !== null && $newCarbon->lt($receivedDate)) {
            throw ValidationException::withMessages([
                'new_date' => 'The new deadline must be on or after the Request Received from Client date.',
            ]);
        }

        return $newCarbon;
    }
}
