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

final class MoveCandidateStage
{
    /**
     * @param  array{lock_version?: int|null, expected_stage?: string|null}  $guard
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

            $action = $to === CandidateStage::Screening
                ? CandidateTransitionAction::MovedToScreening
                : CandidateTransitionAction::MovedToInterview;

            $locked->fill([
                'stage' => $to,
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ]);
            $locked->save();

            RecordCandidateStageTransition::handle(
                $locked,
                $action,
                $from,
                $to,
                null,
                null,
                (int) $actor->id,
            );

            return $locked->refresh();
        });
    }
}
