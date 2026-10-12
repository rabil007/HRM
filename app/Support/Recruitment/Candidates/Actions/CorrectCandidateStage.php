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

final class CorrectCandidateStage
{
    /**
     * Allowed audited corrections (earlier pipeline stages only).
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'screening' => ['applied'],
        'interview' => ['screening', 'applied'],
    ];

    /**
     * @param  array{
     *     to_stage: string,
     *     reason: string,
     *     lock_version?: int|null,
     *     expected_stage?: string|null,
     *     expected_outcome?: string|null,
     * }  $data
     */
    public function handle(User $actor, RecruitmentCandidate $candidate, array $data): RecruitmentCandidate
    {
        $candidate->loadMissing('requirement', 'currentOffer');
        CandidateWorkflowAuthorization::assertCanCorrectStage($actor, $candidate);

        return DB::transaction(function () use ($actor, $candidate, $data): RecruitmentCandidate {
            $graph = CandidateWorkflowAuthorization::lockCandidateGraph($candidate);
            $locked = $graph['candidate'];
            $locked->loadMissing('currentOffer');

            CandidateWorkflowAuthorization::assertCanCorrectStage($actor, $locked);
            CandidateWorkflowAuthorization::assertOpenParentsForWorkflow($locked, (int) $locked->company_id);
            CandidateWorkflowAuthorization::assertExpectedLock(
                $locked,
                array_key_exists('lock_version', $data) ? (int) $data['lock_version'] : null,
                $data['expected_stage'] ?? null,
                array_key_exists('expected_outcome', $data) ? $data['expected_outcome'] : null,
            );

            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '' || mb_strlen($reason) < 3) {
                throw ValidationException::withMessages([
                    'reason' => 'A correction reason of at least 3 characters is required.',
                ]);
            }

            $from = $locked->stage;
            $to = CandidateStage::tryFrom((string) ($data['to_stage'] ?? ''));

            if ($to === null) {
                throw ValidationException::withMessages([
                    'to_stage' => 'Provide a valid destination stage.',
                ]);
            }

            $allowed = self::ALLOWED[$from->value] ?? [];
            if (! in_array($to->value, $allowed, true)) {
                throw ValidationException::withMessages([
                    'to_stage' => "Cannot correct a candidate from {$from->label()} to {$to->label()}.",
                ]);
            }

            if ($locked->currentOffer !== null) {
                throw ValidationException::withMessages([
                    'to_stage' => 'This candidate already has an Offer/JOL. Use the offer revision or management correction workflows instead of a stage correction.',
                ]);
            }

            if (in_array($from, [CandidateStage::OfferJol, CandidateStage::Joined, CandidateStage::Rejected], true)) {
                throw ValidationException::withMessages([
                    'to_stage' => 'Use the dedicated offer, joining, or reopen workflows for this stage.',
                ]);
            }

            $fromOutcome = $locked->interview_outcome;
            $toOutcome = $fromOutcome;
            $clearedSelection = false;

            if ($from === CandidateStage::Interview && $fromOutcome !== null) {
                // Preserve interview field history; clear outcome so the earlier stage is actionable again.
                $toOutcome = null;
                $clearedSelection = $fromOutcome === CandidateInterviewOutcome::Selected;
                $locked->interview_outcome = null;
            }

            $locked->fill([
                'stage' => $to,
                'updated_by' => $actor->id,
                'lock_version' => (int) $locked->lock_version + 1,
            ]);
            $locked->save();

            RecordCandidateStageTransition::handle(
                $locked,
                CandidateTransitionAction::StageCorrected,
                $from,
                $to,
                $fromOutcome,
                $toOutcome,
                (int) $actor->id,
                $reason,
                [
                    'correction' => true,
                    'cleared_interview_outcome' => $fromOutcome?->value,
                    'cleared_selection' => $clearedSelection,
                ],
            );

            return $locked->refresh();
        });
    }

    /**
     * @return list<CandidateStage>
     */
    public static function allowedDestinations(CandidateStage $from): array
    {
        $values = self::ALLOWED[$from->value] ?? [];

        return array_values(array_filter(
            array_map(fn (string $value): ?CandidateStage => CandidateStage::tryFrom($value), $values),
        ));
    }
}
