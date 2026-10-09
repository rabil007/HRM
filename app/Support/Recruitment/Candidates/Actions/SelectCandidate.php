<?php

namespace App\Support\Recruitment\Candidates\Actions;

use App\Enums\Recruitment\CandidateInterviewOutcome;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\CandidateTransitionAction;
use App\Models\RecruitmentCandidate;
use App\Models\User;
use App\Support\Recruitment\Candidates\CandidateWorkflowAuthorization;
use App\Support\Recruitment\Candidates\RecordCandidateStageTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SelectCandidate
{
    /**
     * @param  array{lock_version?: int|null, expected_stage?: string|null, expected_outcome?: string|null}  $guard
     */
    public function handle(User $actor, RecruitmentCandidate $candidate, array $guard = []): RecruitmentCandidate
    {
        CandidateWorkflowAuthorization::assertCanMove($actor, $candidate);
        CandidateWorkflowAuthorization::assertOpenParentsForWorkflow($candidate, (int) $candidate->company_id);

        return DB::transaction(function () use ($actor, $candidate, $guard): RecruitmentCandidate {
            /** @var RecruitmentCandidate $locked */
            $locked = RecruitmentCandidate::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->firstOrFail();

            CandidateWorkflowAuthorization::assertExpectedLock(
                $locked,
                isset($guard['lock_version']) ? (int) $guard['lock_version'] : null,
                $guard['expected_stage'] ?? null,
                $guard['expected_outcome'] ?? null,
            );

            if ($locked->stage !== CandidateStage::Interview) {
                throw ValidationException::withMessages([
                    'stage' => 'Only Interview-stage candidates can be selected.',
                ]);
            }

            if ($locked->interview_outcome !== null) {
                throw ValidationException::withMessages([
                    'interview_outcome' => 'This candidate already has an interview outcome.',
                ]);
            }

            $locked->fill([
                'interview_outcome' => CandidateInterviewOutcome::Selected,
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ]);
            $locked->save();

            RecordCandidateStageTransition::handle(
                $locked,
                CandidateTransitionAction::Selected,
                CandidateStage::Interview,
                CandidateStage::Interview,
                null,
                CandidateInterviewOutcome::Selected,
                (int) $actor->id,
            );

            return $locked->refresh();
        });
    }
}
