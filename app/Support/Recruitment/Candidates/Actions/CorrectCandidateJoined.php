<?php

namespace App\Support\Recruitment\Candidates\Actions;

use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\CandidateTransitionAction;
use App\Models\RecruitmentCandidate;
use App\Models\User;
use App\Support\Recruitment\Candidates\CandidateWorkflowAuthorization;
use App\Support\Recruitment\Candidates\RecordCandidateStageTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CorrectCandidateJoined
{
    /**
     * @param  array{
     *     reason: string,
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
        CandidateWorkflowAuthorization::assertCanCorrectJoined($actor, $candidate);

        return DB::transaction(function () use ($actor, $candidate, $data): RecruitmentCandidate {
            $graph = CandidateWorkflowAuthorization::lockCandidateGraph($candidate);
            $locked = $graph['candidate'];

            CandidateWorkflowAuthorization::assertCanCorrectJoined($actor, $locked);
            CandidateWorkflowAuthorization::assertOpenParentsForWorkflow($locked, (int) $locked->company_id);
            CandidateWorkflowAuthorization::assertExpectedLock(
                $locked,
                array_key_exists('lock_version', $data) ? (int) $data['lock_version'] : null,
                $data['expected_stage'] ?? CandidateStage::Joined->value,
                null,
            );

            if ($locked->stage !== CandidateStage::Joined) {
                throw ValidationException::withMessages([
                    'stage' => 'Only confirmed Joined candidates can have their joining corrected.',
                ]);
            }

            if ($locked->employee_id !== null) {
                throw ValidationException::withMessages([
                    'candidate' => 'Cannot correct or undo joining because a downstream employee record exists for this candidate.',
                ]);
            }

            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '' || mb_strlen($reason) < 3) {
                throw ValidationException::withMessages([
                    'reason' => 'A reason of at least 3 characters is required to correct candidate joining.',
                ]);
            }

            $prevActualDate = $locked->actual_joining_date?->toDateString();
            $prevJoinedAt = $locked->joined_at?->toIso8601String();
            $fromStage = $locked->stage;

            $locked->fill([
                'stage' => CandidateStage::Joining,
                'actual_joining_date' => null,
                'joined_at' => null,
                'joined_by' => null,
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ])->save();

            RecordCandidateStageTransition::handle(
                $locked,
                CandidateTransitionAction::JoiningCorrected,
                $fromStage,
                CandidateStage::Joining,
                $locked->interview_outcome,
                $locked->interview_outcome,
                (int) $actor->id,
                $reason,
                [
                    'previous_actual_joining_date' => $prevActualDate,
                    'previous_joined_at' => $prevJoinedAt,
                ],
            );

            return $locked->refresh();
        });
    }
}
