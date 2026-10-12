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
     * @param  array{
     *     lock_version?: int|null,
     *     expected_stage?: string|null,
     *     expected_outcome?: string|null,
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

            $previousFeedback = $locked->interview_feedback;
            $feedbackChanged = false;
            $nextFeedback = $previousFeedback;

            // Empty optional feedback must never wipe existing notes.
            if (array_key_exists('interview_feedback', $guard)) {
                $submitted = trim((string) ($guard['interview_feedback'] ?? ''));

                if ($submitted !== '' && $submitted !== (string) ($previousFeedback ?? '')) {
                    $nextFeedback = $submitted;
                    $feedbackChanged = true;
                }
            }

            $locked->fill([
                'interview_outcome' => CandidateInterviewOutcome::Selected,
                'interview_feedback' => $nextFeedback,
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ]);
            $locked->save();

            $context = null;

            if ($feedbackChanged) {
                $context = [
                    'interview_feedback' => [
                        'from' => $previousFeedback,
                        'to' => $nextFeedback,
                    ],
                ];
            }

            RecordCandidateStageTransition::handle(
                $locked,
                CandidateTransitionAction::Selected,
                CandidateStage::Interview,
                CandidateStage::Interview,
                null,
                CandidateInterviewOutcome::Selected,
                (int) $actor->id,
                null,
                $context,
            );

            return $locked->refresh();
        });
    }
}
