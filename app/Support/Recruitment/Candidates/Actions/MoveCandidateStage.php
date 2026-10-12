<?php

namespace App\Support\Recruitment\Candidates\Actions;

use App\Enums\Recruitment\CandidateInterviewMode;
use App\Enums\Recruitment\CandidateStage;
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

final class MoveCandidateStage
{
    /**
     * @param  array{
     *     lock_version?: int|null,
     *     expected_stage?: string|null,
     *     reason?: string|null,
     *     interview_scheduled_at?: string|null,
     *     interviewer_user_id?: int|null,
     *     external_interviewer_name?: string|null,
     *     interview_mode?: string|null,
     *     interview_location?: string|null,
     *     interview_feedback?: string|null,
     * }  $guard
     */
    public function handle(User $actor, RecruitmentCandidate $candidate, array $guard = []): RecruitmentCandidate
    {
        CandidateWorkflowAuthorization::assertCanMove($actor, $candidate);

        return DB::transaction(function () use ($actor, $candidate, $guard): RecruitmentCandidate {
            $graph = CandidateWorkflowAuthorization::lockCandidateGraph($candidate);
            $locked = $graph['candidate'];

            CandidateWorkflowAuthorization::assertCanMove($actor, $locked);
            CandidateWorkflowAuthorization::assertOpenParentsForWorkflow($locked, (int) $locked->company_id);
            CandidateWorkflowAuthorization::assertExpectedLock(
                $locked,
                array_key_exists('lock_version', $guard) ? (int) $guard['lock_version'] : null,
                $guard['expected_stage'] ?? null,
                null,
            );

            $from = $locked->stage;
            $to = $from->nextStage();

            if ($to === null) {
                throw ValidationException::withMessages([
                    'stage' => "Cannot move a candidate forward from {$from->label()}.",
                ]);
            }

            if ($locked->interview_outcome !== null) {
                throw ValidationException::withMessages([
                    'stage' => 'Cannot move a candidate that already has an interview outcome.',
                ]);
            }

            $note = trim((string) ($guard['reason'] ?? ''));
            $note = $note !== '' ? $note : null;

            $interviewPayload = self::extractInterviewPayload($guard);
            if ($interviewPayload !== [] && $to !== CandidateStage::Interview) {
                throw ValidationException::withMessages([
                    'interview_scheduled_at' => 'Interview details can only be set when moving to Interview.',
                ]);
            }

            if ($interviewPayload !== []) {
                CandidateWorkflowAuthorization::assertCanUpdate($actor, $locked);
            }

            $action = $to === CandidateStage::Screening
                ? CandidateTransitionAction::MovedToScreening
                : CandidateTransitionAction::MovedToInterview;

            $fill = [
                'stage' => $to,
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ];

            $interviewChanges = [];
            if ($interviewPayload !== [] && $to === CandidateStage::Interview) {
                [$fill, $interviewChanges] = self::applyInterviewFields($locked, $interviewPayload, $fill);
            }

            $locked->fill($fill);
            $locked->save();

            RecordCandidateStageTransition::handle(
                $locked,
                $action,
                $from,
                $to,
                null,
                null,
                (int) $actor->id,
                $note,
            );

            if ($interviewChanges !== []) {
                RecordCandidateStageTransition::handle(
                    $locked,
                    CandidateTransitionAction::InterviewUpdated,
                    $to,
                    $to,
                    null,
                    null,
                    (int) $actor->id,
                    null,
                    ['changes' => $interviewChanges, 'with_stage_move' => true],
                );
            }

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $guard
     * @return array<string, mixed>
     */
    private static function extractInterviewPayload(array $guard): array
    {
        $keys = [
            'interview_scheduled_at',
            'interviewer_user_id',
            'external_interviewer_name',
            'interview_mode',
            'interview_location',
            'interview_feedback',
        ];

        $payload = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $guard)) {
                $payload[$key] = $guard[$key];
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $fill
     * @return array{0: array<string, mixed>, 1: array<string, array{from: mixed, to: mixed}>}
     */
    private static function applyInterviewFields(
        RecruitmentCandidate $locked,
        array $data,
        array $fill,
    ): array {
        $timezone = CompanyTimezone::forCompany((int) $locked->company_id);
        $storageTimezone = (string) config('app.timezone', 'UTC');
        $hasSchedule = array_key_exists('interview_scheduled_at', $data);
        $scheduledAt = null;
        $scheduledAtStorage = null;

        if ($hasSchedule && filled($data['interview_scheduled_at'] ?? null)) {
            try {
                $scheduledAt = Carbon::parse((string) $data['interview_scheduled_at'], $timezone);
                $scheduledAtStorage = $scheduledAt->copy()->setTimezone($storageTimezone);
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
            return [$fill, []];
        }

        $fill['interview_scheduled_at'] = $hasSchedule ? $scheduledAtStorage : $locked->interview_scheduled_at;
        $fill['interviewer_user_id'] = $interviewerUserId;
        $fill['external_interviewer_name'] = $externalName;
        $fill['interview_mode'] = $mode;
        $fill['interview_location'] = $location;
        $fill['interview_feedback'] = $feedback;

        return [$fill, $changes];
    }
}
