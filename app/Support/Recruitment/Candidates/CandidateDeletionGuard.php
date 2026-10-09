<?php

namespace App\Support\Recruitment\Candidates;

use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use Illuminate\Validation\ValidationException;

final class CandidateDeletionGuard
{
    public static function assertRequirementMayBeForceDeleted(RecruitmentRequirement $requirement): void
    {
        $exists = RecruitmentCandidate::query()
            ->where('recruitment_requirement_id', $requirement->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'requirement' => 'This requirement has candidates and cannot be permanently deleted.',
            ]);
        }
    }

    public static function assertLineMayBeDeleted(RecruitmentRequirementLine $line): void
    {
        $exists = RecruitmentCandidate::query()
            ->where('recruitment_requirement_line_id', $line->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'lines' => 'A position line with candidates cannot be removed. Keep the line or cancel it instead.',
            ]);
        }
    }
}
