<?php

namespace App\Support\Recruitment\Candidates;

use App\Models\RecruitmentCandidate;
use App\Models\User;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Helpers for preserving and resetting the active interview field set across corrections.
 */
final class CandidateInterviewCycle
{
    /**
     * @return array{
     *     interview_scheduled_at: string|null,
     *     interviewer_user_id: int|null,
     *     interviewer_user_name: string|null,
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
        $candidate->loadMissing('interviewerUser:id,name');

        $interviewerName = $candidate->interviewerUser?->name;
        $interviewerName = is_string($interviewerName) && $interviewerName !== ''
            ? $interviewerName
            : null;

        return [
            'interview_scheduled_at' => $candidate->interview_scheduled_at
                ?->copy()
                ->timezone($timezone)
                ->format('Y-m-d H:i:s'),
            'interviewer_user_id' => $candidate->interviewer_user_id,
            // Preserve display name at snapshot time for historical accuracy.
            'interviewer_user_name' => $interviewerName,
            'external_interviewer_name' => $candidate->external_interviewer_name,
            'interview_mode' => $candidate->interview_mode?->value,
            'interview_location' => $candidate->interview_location,
            'interview_notes' => $candidate->interview_notes,
            'interview_feedback' => $candidate->interview_feedback,
            'interview_outcome' => $candidate->interview_outcome?->value,
        ];
    }

    /**
     * Resolve interviewer display names for historical snapshots without N+1 queries.
     * Strictly company-scoped; omits users that do not belong to the company.
     *
     * @param  list<int>  $userIds
     * @return array<int, string>
     */
    public static function resolveInterviewerNamesForCompany(array $userIds, int $companyId): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $userIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === [] || $companyId <= 0) {
            return [];
        }

        return User::query()
            ->whereIn('users.id', $ids)
            ->whereNull('users.deleted_at')
            ->where(function (Builder $query) use ($companyId): void {
                $query->whereExists(function ($inner) use ($companyId): void {
                    $inner->select(DB::raw(1))
                        ->from('company_user')
                        ->whereColumn('company_user.user_id', 'users.id')
                        ->where('company_user.company_id', $companyId);
                })->orWhere('users.company_id', $companyId);
            })
            ->get(['users.id', 'users.name'])
            ->mapWithKeys(static fn (User $user): array => [
                (int) $user->id => (string) $user->name,
            ])
            ->all();
    }

    /**
     * @param  iterable<int, array<string, mixed>|null>  $contexts
     * @return list<int>
     */
    public static function collectInterviewerIdsFromContexts(iterable $contexts): array
    {
        $ids = [];

        foreach ($contexts as $context) {
            if (! is_array($context)) {
                continue;
            }

            foreach (['interview_cycle_snapshot', 'prior_interview_cycle_snapshot'] as $key) {
                $snapshot = $context[$key] ?? null;
                if (! is_array($snapshot)) {
                    continue;
                }

                $id = $snapshot['interviewer_user_id'] ?? null;
                if (is_numeric($id) && (int) $id > 0) {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($ids));
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
