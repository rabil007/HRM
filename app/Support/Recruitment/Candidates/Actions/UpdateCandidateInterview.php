<?php

namespace App\Support\Recruitment\Candidates\Actions;

use App\Enums\Recruitment\CandidateInterviewMode;
use App\Enums\Recruitment\CandidateTransitionAction;
use App\Models\RecruitmentCandidate;
use App\Models\User;
use App\Support\Recruitment\Candidates\CandidateWorkflowAuthorization;
use App\Support\Recruitment\Candidates\RecordCandidateStageTransition;
use App\Support\Recruitment\CompanyUserOptionsQuery;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateCandidateInterview
{
    /**
     * @param  array{
     *     interview_scheduled_at?: string|null,
     *     interviewer_user_id?: int|null,
     *     external_interviewer_name?: string|null,
     *     interview_mode?: string|null,
     *     interview_location?: string|null,
     *     interview_feedback?: string|null,
     *     lock_version?: int|null,
     *     expected_stage?: string|null,
     * }  $data
     */
    public function handle(User $actor, RecruitmentCandidate $candidate, array $data): RecruitmentCandidate
    {
        CandidateWorkflowAuthorization::assertCanUpdate($actor, $candidate);

        return DB::transaction(function () use ($actor, $candidate, $data): RecruitmentCandidate {
            $graph = CandidateWorkflowAuthorization::lockCandidateGraph($candidate);
            $locked = $graph['candidate'];

            CandidateWorkflowAuthorization::assertCanUpdate($actor, $locked);
            CandidateWorkflowAuthorization::assertOpenParentsForWorkflow($locked, (int) $locked->company_id);
            CandidateWorkflowAuthorization::assertExpectedLock(
                $locked,
                array_key_exists('lock_version', $data) ? (int) $data['lock_version'] : null,
                $data['expected_stage'] ?? null,
                null,
            );

            $timezone = CompanyTimezone::forCompany((int) $locked->company_id);
            $scheduledAt = null;
            $hasSchedule = array_key_exists('interview_scheduled_at', $data);

            if ($hasSchedule && filled($data['interview_scheduled_at'] ?? null)) {
                try {
                    $scheduledAt = Carbon::parse((string) $data['interview_scheduled_at'], $timezone);
                } catch (\Throwable) {
                    throw ValidationException::withMessages([
                        'interview_scheduled_at' => 'Provide a valid interview date and time.',
                    ]);
                }
            }

            $interviewerUserId = array_key_exists('interviewer_user_id', $data)
                ? ($data['interviewer_user_id'] !== null && $data['interviewer_user_id'] !== ''
                    ? (int) $data['interviewer_user_id']
                    : null)
                : $locked->interviewer_user_id;

            if ($interviewerUserId !== null
                && ! CompanyUserOptionsQuery::isActiveCompanyMember($interviewerUserId, (int) $locked->company_id)
            ) {
                throw ValidationException::withMessages([
                    'interviewer_user_id' => 'The selected interviewer must be an active member of the current company.',
                ]);
            }

            $externalName = array_key_exists('external_interviewer_name', $data)
                ? (trim((string) ($data['external_interviewer_name'] ?? '')) ?: null)
                : $locked->external_interviewer_name;

            $mode = array_key_exists('interview_mode', $data)
                ? (filled($data['interview_mode'] ?? null)
                    ? CandidateInterviewMode::from((string) $data['interview_mode'])
                    : null)
                : $locked->interview_mode;

            $location = array_key_exists('interview_location', $data)
                ? (trim((string) ($data['interview_location'] ?? '')) ?: null)
                : $locked->interview_location;

            $feedback = array_key_exists('interview_feedback', $data)
                ? (trim((string) ($data['interview_feedback'] ?? '')) ?: null)
                : $locked->interview_feedback;

            if ($scheduledAt !== null && $mode === null) {
                throw ValidationException::withMessages([
                    'interview_mode' => 'Select an interview mode when scheduling an interview.',
                ]);
            }

            $changes = [];
            $compare = [
                'interview_scheduled_at' => [
                    $locked->interview_scheduled_at?->copy()->timezone($timezone)->format('Y-m-d H:i:s'),
                    $hasSchedule
                        ? ($scheduledAt?->format('Y-m-d H:i:s'))
                        : $locked->interview_scheduled_at?->copy()->timezone($timezone)->format('Y-m-d H:i:s'),
                ],
                'interviewer_user_id' => [$locked->interviewer_user_id, $interviewerUserId],
                'external_interviewer_name' => [$locked->external_interviewer_name, $externalName],
                'interview_mode' => [$locked->interview_mode?->value, $mode?->value],
                'interview_location' => [$locked->interview_location, $location],
                'interview_feedback' => [$locked->interview_feedback, $feedback],
            ];

            foreach ($compare as $field => [$before, $after]) {
                if ($before !== $after) {
                    $changes[$field] = ['from' => $before, 'to' => $after];
                }
            }

            if ($changes === []) {
                return $locked;
            }

            $locked->fill([
                'interview_scheduled_at' => $hasSchedule ? $scheduledAt : $locked->interview_scheduled_at,
                'interviewer_user_id' => $interviewerUserId,
                'external_interviewer_name' => $externalName,
                'interview_mode' => $mode,
                'interview_location' => $location,
                'interview_feedback' => $feedback,
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ]);
            $locked->save();

            RecordCandidateStageTransition::handle(
                $locked,
                CandidateTransitionAction::InterviewUpdated,
                $locked->stage,
                $locked->stage,
                $locked->interview_outcome,
                $locked->interview_outcome,
                (int) $actor->id,
                null,
                ['changes' => $changes],
            );

            return $locked->refresh();
        });
    }
}
