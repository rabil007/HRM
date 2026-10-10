<?php

namespace App\Support\Recruitment\Candidates\Actions;

use App\Enums\Recruitment\CandidateJoiningReadinessStatus;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\CandidateTransitionAction;
use App\Models\RecruitmentCandidate;
use App\Models\User;
use App\Support\Recruitment\Candidates\CandidateOfferDateValidation;
use App\Support\Recruitment\Candidates\CandidateWorkflowAuthorization;
use App\Support\Recruitment\Candidates\RecordCandidateStageTransition;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class UpdateCandidateJoiningReadiness
{
    /**
     * @param  array{
     *     expected_joining_date?: string|null,
     *     joining_readiness_status?: string|null,
     *     joining_readiness_notes?: string|null,
     *     joining_blocker_notes?: string|null,
     *     reason?: string|null,
     *     lock_version?: int|null,
     *     expected_stage?: string|null,
     * }  $data
     */
    public function handle(
        User $actor,
        RecruitmentCandidate $candidate,
        array $data,
    ): RecruitmentCandidate {
        $candidate->loadMissing('requirement');
        CandidateWorkflowAuthorization::assertCanUpdateReadiness($actor, $candidate);

        return DB::transaction(function () use ($actor, $candidate, $data): RecruitmentCandidate {
            $graph = CandidateWorkflowAuthorization::lockCandidateGraph($candidate);
            $locked = $graph['candidate'];

            CandidateWorkflowAuthorization::assertCanUpdateReadiness($actor, $locked);
            CandidateWorkflowAuthorization::assertOpenParentsForWorkflow($locked, (int) $locked->company_id);
            CandidateWorkflowAuthorization::assertExpectedLock(
                $locked,
                array_key_exists('lock_version', $data) ? (int) $data['lock_version'] : null,
                $data['expected_stage'] ?? CandidateStage::Joining->value,
                null,
            );

            if ($locked->stage !== CandidateStage::Joining) {
                throw ValidationException::withMessages([
                    'stage' => 'Candidate joining readiness can only be updated in Joining stage.',
                ]);
            }

            $timezone = CompanyTimezone::forCompany((int) $locked->company_id);
            $reason = trim((string) ($data['reason'] ?? ''));

            $hasExpectedDate = array_key_exists('expected_joining_date', $data);
            $expectedJoiningDate = $locked->expected_joining_date?->toDateString();

            if ($hasExpectedDate) {
                $rawDate = trim((string) ($data['expected_joining_date'] ?? ''));
                if ($rawDate === '') {
                    $expectedJoiningDate = null;
                } else {
                    $extracted = CandidateOfferDateValidation::extractDateOnlyString($rawDate);
                    if ($extracted === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $extracted)) {
                        throw ValidationException::withMessages([
                            'expected_joining_date' => 'The expected joining date format is invalid.',
                        ]);
                    }

                    try {
                        $expectedJoiningDate = CarbonImmutable::createFromFormat('!Y-m-d', $extracted, $timezone)->toDateString();
                    } catch (Throwable) {
                        throw ValidationException::withMessages([
                            'expected_joining_date' => 'The expected joining date format is invalid.',
                        ]);
                    }
                }
            }

            $hasReadiness = array_key_exists('joining_readiness_status', $data);
            $readinessStatus = $locked->joining_readiness_status;

            if ($hasReadiness && filled($data['joining_readiness_status'])) {
                try {
                    $readinessStatus = CandidateJoiningReadinessStatus::from((string) $data['joining_readiness_status']);
                } catch (Throwable) {
                    throw ValidationException::withMessages([
                        'joining_readiness_status' => 'Invalid readiness status value.',
                    ]);
                }
            }

            $hasReadinessNotes = array_key_exists('joining_readiness_notes', $data);
            $readinessNotes = $hasReadinessNotes
                ? (trim((string) ($data['joining_readiness_notes'] ?? '')) ?: null)
                : $locked->joining_readiness_notes;

            $hasBlockerNotes = array_key_exists('joining_blocker_notes', $data);
            $blockerNotes = $hasBlockerNotes
                ? (trim((string) ($data['joining_blocker_notes'] ?? '')) ?: null)
                : $locked->joining_blocker_notes;

            $compare = [
                'expected_joining_date' => [
                    $locked->expected_joining_date?->toDateString(),
                    $expectedJoiningDate,
                ],
                'joining_readiness_status' => [
                    $locked->joining_readiness_status?->value,
                    $readinessStatus?->value,
                ],
                'joining_readiness_notes' => [
                    $locked->joining_readiness_notes,
                    $readinessNotes,
                ],
                'joining_blocker_notes' => [
                    $locked->joining_blocker_notes,
                    $blockerNotes,
                ],
            ];

            $changes = [];
            foreach ($compare as $field => [$before, $after]) {
                if ($before !== $after) {
                    $changes[$field] = ['from' => $before, 'to' => $after];
                }
            }

            if ($changes === []) {
                return $locked;
            }

            $locked->fill([
                'expected_joining_date' => $expectedJoiningDate,
                'joining_readiness_status' => $readinessStatus,
                'joining_readiness_notes' => $readinessNotes,
                'joining_blocker_notes' => $blockerNotes,
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ])->save();

            RecordCandidateStageTransition::handle(
                $locked,
                CandidateTransitionAction::JoiningReadinessUpdated,
                $locked->stage,
                $locked->stage,
                $locked->interview_outcome,
                $locked->interview_outcome,
                (int) $actor->id,
                $reason ?: null,
                ['changes' => $changes],
            );

            return $locked->refresh();
        });
    }
}
