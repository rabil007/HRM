<?php

namespace App\Support\Recruitment\Candidates;

use App\Models\RecruitmentCandidate;
use App\Support\Settings\CompanyTimezone;

/**
 * Helpers for preserving and resetting the active interview field set across corrections.
 */
final class CandidateInterviewCycle
{
    /**
     * @return array{
     *     interview_scheduled_at: string|null,
     *     interviewer_user_id: int|null,
     *     external_interviewer_name: string|null,
     *     interview_mode: string|null,
     *     interview_location: string|null,
     *     interview_notes: string|null,
     *     interview_feedback: string|null,
     *     interview_outcome: string|null,
     * }
     */
    public static function snapshot(RecruitmentCandidate $candidate): array
    {
        $timezone = CompanyTimezone::forCompany((int) $candidate->company_id);

        return [
            'interview_scheduled_at' => $candidate->interview_scheduled_at
                ?->copy()
                ->timezone($timezone)
                ->format('Y-m-d H:i:s'),
            'interviewer_user_id' => $candidate->interviewer_user_id,
            'external_interviewer_name' => $candidate->external_interviewer_name,
            'interview_mode' => $candidate->interview_mode?->value,
            'interview_location' => $candidate->interview_location,
            'interview_notes' => $candidate->interview_notes,
            'interview_feedback' => $candidate->interview_feedback,
            'interview_outcome' => $candidate->interview_outcome?->value,
        ];
    }

    /**
     * Clear active interview cycle fields (history must already be recorded separately).
     *
     * @return array<string, mixed>
     */
    public static function clearedActiveFields(): array
    {
        return [
            'interview_scheduled_at' => null,
            'interviewer_user_id' => null,
            'external_interviewer_name' => null,
            'interview_mode' => null,
            'interview_location' => null,
            'interview_notes' => null,
            'interview_feedback' => null,
            'interview_outcome' => null,
        ];
    }

    public static function hasActiveDetails(RecruitmentCandidate $candidate): bool
    {
        return $candidate->interview_scheduled_at !== null
            || $candidate->interviewer_user_id !== null
            || filled($candidate->external_interviewer_name)
            || $candidate->interview_mode !== null
            || filled($candidate->interview_location)
            || filled($candidate->interview_notes)
            || filled($candidate->interview_feedback)
            || $candidate->interview_outcome !== null;
    }
}
