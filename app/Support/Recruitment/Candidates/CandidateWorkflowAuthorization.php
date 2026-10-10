<?php

namespace App\Support\Recruitment\Candidates;

use App\Enums\Recruitment\CandidateInterviewOutcome;
use App\Enums\Recruitment\CandidateJoiningReadinessStatus;
use App\Enums\Recruitment\CandidateOfferStatus;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateOffer;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Candidate workflow guards: permission + assigned-recruiter ownership (+ manage override).
 * CC notification recipients gain no action rights.
 */
final class CandidateWorkflowAuthorization
{
    public static function isAssignedRecruiter(User $user, ?RecruitmentRequirement $requirement): bool
    {
        if ($requirement === null || $requirement->assigned_to === null) {
            return false;
        }

        return (int) $requirement->assigned_to === (int) $user->id;
    }

    public static function hasOwnershipOrManage(User $user, ?RecruitmentRequirement $requirement): bool
    {
        if ($user->can('recruitment.candidates.manage')) {
            return true;
        }

        return self::isAssignedRecruiter($user, $requirement);
    }

    public static function canCreate(User $user, RecruitmentRequirement $requirement): bool
    {
        return $user->can('recruitment.candidates.create')
            && self::hasOwnershipOrManage($user, $requirement);
    }

    public static function canUpdate(User $user, RecruitmentCandidate $candidate): bool
    {
        return $user->can('recruitment.candidates.update')
            && self::hasOwnershipOrManage($user, $candidate->requirement);
    }

    public static function canMove(User $user, RecruitmentCandidate $candidate): bool
    {
        return $user->can('recruitment.candidates.move')
            && self::hasOwnershipOrManage($user, $candidate->requirement);
    }

    public static function canReopenRejected(User $user, RecruitmentCandidate $candidate): bool
    {
        return $user->can('recruitment.candidates.move')
            && $user->can('recruitment.candidates.manage')
            && self::hasOwnershipOrManage($user, $candidate->requirement);
    }

    public static function canDownloadCv(User $user, RecruitmentCandidate $candidate): bool
    {
        return $user->can('recruitment.candidates.view')
            && $user->can('recruitment.candidates.cv.download')
            && (int) $candidate->company_id > 0;
    }

    public static function canDownloadOfferDocuments(User $user, RecruitmentCandidate $candidate): bool
    {
        return $user->can('recruitment.candidates.view')
            && $user->can('recruitment.candidates.offer.download')
            && (int) $candidate->company_id > 0;
    }

    public static function assertCanPrepareOffer(User $user, RecruitmentCandidate $candidate): void
    {
        if (! $user->can('recruitment.candidates.offer.prepare')) {
            throw ValidationException::withMessages([
                'candidate' => 'You do not have permission to prepare offers.',
            ]);
        }

        if (! self::hasOwnershipOrManage($user, $candidate->requirement)) {
            throw ValidationException::withMessages([
                'candidate' => 'Only the assigned recruiter (or a user with management override) can prepare an offer for this candidate.',
            ]);
        }
    }

    public static function assertCanUpdateOffer(User $user, RecruitmentCandidate $candidate): void
    {
        if (! $user->can('recruitment.candidates.offer.update')) {
            throw ValidationException::withMessages([
                'candidate' => 'You do not have permission to update offers.',
            ]);
        }

        if (! self::hasOwnershipOrManage($user, $candidate->requirement)) {
            throw ValidationException::withMessages([
                'candidate' => 'Only the assigned recruiter (or a user with management override) can update this offer.',
            ]);
        }
    }

    public static function assertCanSendOffer(User $user, RecruitmentCandidate $candidate): void
    {
        if (! $user->can('recruitment.candidates.offer.send')) {
            throw ValidationException::withMessages([
                'candidate' => 'You do not have permission to mark offers as sent.',
            ]);
        }

        if (! self::hasOwnershipOrManage($user, $candidate->requirement)) {
            throw ValidationException::withMessages([
                'candidate' => 'Only the assigned recruiter (or a user with management override) can mark this offer as sent.',
            ]);
        }
    }

    public static function assertCanDecideOffer(User $user, RecruitmentCandidate $candidate): void
    {
        if (! $user->can('recruitment.candidates.offer.decide')) {
            throw ValidationException::withMessages([
                'candidate' => 'You do not have permission to record offer decisions.',
            ]);
        }

        if (! self::hasOwnershipOrManage($user, $candidate->requirement)) {
            throw ValidationException::withMessages([
                'candidate' => 'Only the assigned recruiter (or a user with management override) can decide this offer.',
            ]);
        }
    }

    public static function assertCanReviseOffer(User $user, RecruitmentCandidate $candidate): void
    {
        if ($candidate->stage === CandidateStage::Joined) {
            throw ValidationException::withMessages([
                'candidate' => 'Offers cannot be revised while candidate is in Joined stage. Undo joined first if revision is required.',
            ]);
        }

        if (! $user->can('recruitment.candidates.offer.revise') || ! $user->can('recruitment.candidates.manage')) {
            throw ValidationException::withMessages([
                'candidate' => 'Revising a sent, accepted, or rejected offer requires revise permission and management override.',
            ]);
        }

        if (! self::hasOwnershipOrManage($user, $candidate->requirement)) {
            throw ValidationException::withMessages([
                'candidate' => 'Only the assigned recruiter (or a user with management override) can revise this offer.',
            ]);
        }
    }

    public static function assertOfferExpectedLock(
        RecruitmentCandidateOffer $offer,
        ?int $expectedLockVersion,
        ?string $expectedStatus = null,
    ): void {
        if ($expectedLockVersion === null) {
            throw ValidationException::withMessages([
                'offer_lock_version' => 'An offer lock version is required. Refresh and try again.',
            ]);
        }

        if ((int) $offer->lock_version !== $expectedLockVersion) {
            throw ValidationException::withMessages([
                'offer_lock_version' => 'This offer was updated by someone else. Refresh and try again.',
            ]);
        }

        if ($expectedStatus !== null && $offer->status->value !== $expectedStatus) {
            throw ValidationException::withMessages([
                'offer_status' => 'This offer is no longer in the expected status. Refresh and try again.',
            ]);
        }
    }

    /**
     * Lock requirement → line → candidate → current offer (when present).
     *
     * @return array{
     *     candidate: RecruitmentCandidate,
     *     requirement: ?RecruitmentRequirement,
     *     line: ?RecruitmentRequirementLine,
     *     offer: ?RecruitmentCandidateOffer
     * }
     */
    public static function lockCandidateOfferGraph(RecruitmentCandidate $candidate): array
    {
        $graph = self::lockCandidateGraph($candidate);
        $locked = $graph['candidate'];

        $offer = RecruitmentCandidateOffer::query()
            ->where('recruitment_candidate_id', $locked->id)
            ->where('is_current', true)
            ->lockForUpdate()
            ->first();

        $locked->setRelation('currentOffer', $offer);

        return [
            'candidate' => $locked,
            'requirement' => $graph['requirement'],
            'line' => $graph['line'],
            'offer' => $offer,
        ];
    }

    public static function assertCanCreate(User $user, RecruitmentRequirement $requirement): void
    {
        if (! $user->can('recruitment.candidates.create')) {
            throw ValidationException::withMessages([
                'candidate' => 'You do not have permission to create candidates.',
            ]);
        }

        if (! self::hasOwnershipOrManage($user, $requirement)) {
            throw ValidationException::withMessages([
                'candidate' => 'Only the assigned recruiter (or a user with management override) can add candidates to this requirement.',
            ]);
        }
    }

    public static function assertCanUpdate(User $user, RecruitmentCandidate $candidate): void
    {
        if (! $user->can('recruitment.candidates.update')) {
            throw ValidationException::withMessages([
                'candidate' => 'You do not have permission to update candidates.',
            ]);
        }

        if (! self::hasOwnershipOrManage($user, $candidate->requirement)) {
            throw ValidationException::withMessages([
                'candidate' => 'Only the assigned recruiter (or a user with management override) can update this candidate.',
            ]);
        }
    }

    public static function assertCanMove(User $user, RecruitmentCandidate $candidate): void
    {
        if (! $user->can('recruitment.candidates.move')) {
            throw ValidationException::withMessages([
                'candidate' => 'You do not have permission to move candidates.',
            ]);
        }

        if (! self::hasOwnershipOrManage($user, $candidate->requirement)) {
            throw ValidationException::withMessages([
                'candidate' => 'Only the assigned recruiter (or a user with management override) can move this candidate.',
            ]);
        }
    }

    public static function assertCanReopenRejected(User $user, RecruitmentCandidate $candidate): void
    {
        if (! $user->can('recruitment.candidates.move') || ! $user->can('recruitment.candidates.manage')) {
            throw ValidationException::withMessages([
                'candidate' => 'Reopening a rejected candidate requires move permission and management override.',
            ]);
        }

        if (! self::hasOwnershipOrManage($user, $candidate->requirement)) {
            throw ValidationException::withMessages([
                'candidate' => 'Only the assigned recruiter (or a user with management override) can reopen this candidate.',
            ]);
        }
    }

    /**
     * Parents must be present, Open requirement + Open line, and belong together / to company.
     *
     * @return array{requirement: RecruitmentRequirement, line: RecruitmentRequirementLine}
     */
    public static function assertOpenParentsForWorkflow(
        RecruitmentCandidate $candidate,
        int $companyId,
    ): array {
        $requirement = $candidate->requirement;
        $line = $candidate->line;

        if ($requirement === null || $line === null) {
            throw ValidationException::withMessages([
                'candidate' => 'This candidate is missing a linked requirement or position line. Workflow actions are disabled until a valid parent exists.',
            ]);
        }

        if ((int) $requirement->company_id !== $companyId || (int) $line->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'candidate' => 'Candidate parent records do not belong to the active company.',
            ]);
        }

        if ((int) $line->recruitment_requirement_id !== (int) $requirement->id) {
            throw ValidationException::withMessages([
                'candidate' => 'The candidate position line does not belong to the linked requirement.',
            ]);
        }

        if ($requirement->status !== RequirementStatus::Open) {
            throw ValidationException::withMessages([
                'candidate' => 'Workflow actions require an Open requirement.',
            ]);
        }

        if ($line->status !== RequirementLineStatus::Open) {
            throw ValidationException::withMessages([
                'candidate' => 'Workflow actions require an Open position line.',
            ]);
        }

        return ['requirement' => $requirement, 'line' => $line];
    }

    public static function assertParentsAllowCreate(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementLine $line,
        int $companyId,
    ): void {
        if ((int) $requirement->company_id !== $companyId || (int) $line->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'recruitment_requirement_id' => 'The selected requirement does not belong to the active company.',
            ]);
        }

        if ((int) $line->recruitment_requirement_id !== (int) $requirement->id) {
            throw ValidationException::withMessages([
                'recruitment_requirement_line_id' => 'The selected position line does not belong to the requirement.',
            ]);
        }

        if ($requirement->status !== RequirementStatus::Open) {
            throw ValidationException::withMessages([
                'recruitment_requirement_id' => 'Candidates can only be added to Open requirements.',
            ]);
        }

        if ($line->status !== RequirementLineStatus::Open) {
            throw ValidationException::withMessages([
                'recruitment_requirement_line_id' => 'Candidates can only be added to Open position lines.',
            ]);
        }
    }

    public static function hasValidParentsForActions(RecruitmentCandidate $candidate): bool
    {
        $requirement = $candidate->requirement;
        $line = $candidate->line;

        if ($requirement === null || $line === null) {
            return false;
        }

        if ((int) $line->recruitment_requirement_id !== (int) $requirement->id) {
            return false;
        }

        return $requirement->status === RequirementStatus::Open
            && $line->status === RequirementLineStatus::Open;
    }

    public static function assertExpectedLock(
        RecruitmentCandidate $candidate,
        ?int $expectedLockVersion,
        ?string $expectedStage,
        ?string $expectedOutcome,
    ): void {
        if ($expectedLockVersion === null) {
            throw ValidationException::withMessages([
                'lock_version' => 'A lock version is required. Refresh and try again.',
            ]);
        }

        if ((int) $candidate->lock_version !== $expectedLockVersion) {
            throw ValidationException::withMessages([
                'lock_version' => 'This candidate was updated by someone else. Refresh and try again.',
            ]);
        }

        if ($expectedStage !== null && $candidate->stage->value !== $expectedStage) {
            throw ValidationException::withMessages([
                'stage' => 'This candidate is no longer in the expected stage. Refresh and try again.',
            ]);
        }

        $currentOutcome = $candidate->interview_outcome?->value;

        if ($expectedOutcome !== null) {
            $normalizedExpected = $expectedOutcome === '' ? null : $expectedOutcome;

            if ($currentOutcome !== $normalizedExpected) {
                throw ValidationException::withMessages([
                    'interview_outcome' => 'This candidate outcome changed. Refresh and try again.',
                ]);
            }
        }
    }

    /**
     * Lock requirement → line → candidate to match requirement reassignment/status lock order.
     *
     * @return array{candidate: RecruitmentCandidate, requirement: ?RecruitmentRequirement, line: ?RecruitmentRequirementLine}
     */
    public static function lockCandidateGraph(RecruitmentCandidate $candidate): array
    {
        $requirementId = $candidate->recruitment_requirement_id;
        $lineId = $candidate->recruitment_requirement_line_id;

        $requirement = $requirementId !== null
            ? RecruitmentRequirement::query()->whereKey($requirementId)->lockForUpdate()->first()
            : null;

        $line = $lineId !== null
            ? RecruitmentRequirementLine::query()->whereKey($lineId)->lockForUpdate()->first()
            : null;

        /** @var RecruitmentCandidate $locked */
        $locked = RecruitmentCandidate::query()
            ->whereKey($candidate->id)
            ->lockForUpdate()
            ->firstOrFail();

        $locked->setRelation('requirement', $requirement);
        $locked->setRelation('line', $line);

        return [
            'candidate' => $locked,
            'requirement' => $requirement,
            'line' => $line,
        ];
    }

    /**
     * @return array{requirement: RecruitmentRequirement, line: RecruitmentRequirementLine}
     */
    public static function lockParentsForCreate(int $requirementId, int $lineId): array
    {
        /** @var RecruitmentRequirement $requirement */
        $requirement = RecruitmentRequirement::query()
            ->whereKey($requirementId)
            ->lockForUpdate()
            ->firstOrFail();

        /** @var RecruitmentRequirementLine $line */
        $line = RecruitmentRequirementLine::query()
            ->whereKey($lineId)
            ->lockForUpdate()
            ->firstOrFail();

        return [
            'requirement' => $requirement,
            'line' => $line,
        ];
    }

    public static function canSelect(RecruitmentCandidate $candidate): bool
    {
        return $candidate->stage === CandidateStage::Interview
            && $candidate->interview_outcome !== CandidateInterviewOutcome::Selected
            && $candidate->interview_outcome !== CandidateInterviewOutcome::NotSelected
            && $candidate->currentOffer === null;
    }

    public static function canUndoSelected(RecruitmentCandidate $candidate): bool
    {
        return $candidate->stage === CandidateStage::Interview
            && $candidate->interview_outcome === CandidateInterviewOutcome::Selected
            && $candidate->currentOffer === null;
    }

    public static function canReject(RecruitmentCandidate $candidate): bool
    {
        return $candidate->stage->allowsRejection();
    }

    public static function canPrepareOffer(RecruitmentCandidate $candidate): bool
    {
        return $candidate->stage === CandidateStage::Interview
            && $candidate->interview_outcome === CandidateInterviewOutcome::Selected
            && $candidate->currentOffer === null;
    }

    public static function canUpdateOffer(?RecruitmentCandidateOffer $offer): bool
    {
        return $offer !== null && $offer->is_current && $offer->status === CandidateOfferStatus::Draft;
    }

    public static function canSendOffer(?RecruitmentCandidateOffer $offer): bool
    {
        return $offer !== null && $offer->is_current && $offer->status === CandidateOfferStatus::Draft;
    }

    public static function canDecideOffer(?RecruitmentCandidateOffer $offer, RecruitmentCandidate $candidate): bool
    {
        return $offer !== null
            && $offer->is_current
            && $offer->status === CandidateOfferStatus::Sent
            && $candidate->stage === CandidateStage::OfferJol;
    }

    public static function canReviseOffer(?RecruitmentCandidateOffer $offer, RecruitmentCandidate $candidate): bool
    {
        if ($offer === null || ! $offer->is_current) {
            return false;
        }

        if ($offer->status === CandidateOfferStatus::Draft) {
            return false;
        }

        if ($candidate->stage === CandidateStage::Rejected || $candidate->stage === CandidateStage::Joined) {
            return false;
        }

        return in_array($offer->status, [
            CandidateOfferStatus::Sent,
            CandidateOfferStatus::Accepted,
            CandidateOfferStatus::Rejected,
        ], true);
    }

    public static function canUpdateReadiness(User $user, RecruitmentCandidate $candidate): bool
    {
        return $user->can('recruitment.candidates.update')
            && self::hasOwnershipOrManage($user, $candidate->requirement)
            && self::hasValidParentsForActions($candidate)
            && $candidate->stage === CandidateStage::Joining;
    }

    public static function assertCanUpdateReadiness(User $user, RecruitmentCandidate $candidate): void
    {
        if (! $user->can('recruitment.candidates.update')) {
            throw ValidationException::withMessages([
                'candidate' => 'You do not have permission to update candidate joining readiness.',
            ]);
        }

        if (! self::hasOwnershipOrManage($user, $candidate->requirement)) {
            throw ValidationException::withMessages([
                'candidate' => 'Only the assigned recruiter (or a user with management override) can update joining readiness.',
            ]);
        }
    }

    public static function canConfirmJoined(User $user, RecruitmentCandidate $candidate): bool
    {
        $currentOffer = $candidate->relationLoaded('currentOffer')
            ? $candidate->currentOffer
            : $candidate->currentOffer()->first();

        return $user->can('recruitment.candidates.joining.confirm')
            && self::hasOwnershipOrManage($user, $candidate->requirement)
            && self::hasValidParentsForActions($candidate)
            && $candidate->stage === CandidateStage::Joining
            && $candidate->joining_readiness_status === CandidateJoiningReadinessStatus::Ready
            && $currentOffer !== null
            && $currentOffer->status === CandidateOfferStatus::Accepted;
    }

    public static function assertCanConfirmJoined(User $user, RecruitmentCandidate $candidate): void
    {
        if (! $user->can('recruitment.candidates.joining.confirm')) {
            throw ValidationException::withMessages([
                'candidate' => 'You do not have permission to confirm candidate joining.',
            ]);
        }

        if (! self::hasOwnershipOrManage($user, $candidate->requirement)) {
            throw ValidationException::withMessages([
                'candidate' => 'Only the assigned recruiter (or a user with management override) can confirm candidate joining.',
            ]);
        }
    }

    public static function canCorrectJoined(User $user, RecruitmentCandidate $candidate): bool
    {
        return $user->can('recruitment.candidates.manage')
            && $user->can('recruitment.candidates.joining.confirm')
            && $candidate->stage === CandidateStage::Joined
            && $candidate->employee_id === null;
    }

    public static function assertCanCorrectJoined(User $user, RecruitmentCandidate $candidate): void
    {
        if (! $user->can('recruitment.candidates.manage') || ! $user->can('recruitment.candidates.joining.confirm')) {
            throw ValidationException::withMessages([
                'candidate' => 'You do not have permission to correct or undo confirmed candidate joining.',
            ]);
        }
    }
}
