<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementHeadcountRevisionInitiator;
use App\Enums\Recruitment\RequirementHeadcountRevisionStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\Position;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementHeadcountRevision;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use App\Support\Recruitment\RequirementWorkflowAuthorization;
use App\Support\Recruitment\SendRequirementHeadcountRevisionEmails;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChangeHeadcountAction
{
    /**
     * @param  list<array{id: int, required_headcount: int}>  $lines
     * @return array{requirement: RecruitmentRequirement, mode: 'direct'|'request'}
     */
    public function execute(
        RecruitmentRequirement $requirement,
        User $actor,
        array $lines,
        ?string $reason,
    ): array {
        if ($requirement->status->isEditable()) {
            return [
                'requirement' => $this->applyDirect($requirement, $actor, $lines, $reason),
                'mode' => 'direct',
            ];
        }

        if (in_array($requirement->status, [RequirementStatus::Open, RequirementStatus::OnHold], true)) {
            return [
                'requirement' => $this->requestRevision($requirement, $actor, $lines, $reason),
                'mode' => 'request',
            ];
        }

        throw ValidationException::withMessages([
            'status' => "Headcount cannot be revised for {$requirement->status->label()} requirement.",
        ]);
    }

    /**
     * @param  list<array{id: int, required_headcount: int}>  $lines
     */
    private function applyDirect(
        RecruitmentRequirement $requirement,
        User $actor,
        array $lines,
        ?string $reason,
    ): RecruitmentRequirement {
        $trimmedReason = trim((string) $reason);

        return DB::transaction(function () use ($requirement, $actor, $lines, $trimmedReason): RecruitmentRequirement {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            RequirementWorkflowAuthorization::assertCanDirectlyReviseHeadcount($actor, $locked);

            $changes = $this->changedLines($locked, $lines, rejectWhenUnchanged: false);

            if ($changes !== []) {
                foreach ($changes as $change) {
                    $change['line']->update(['required_headcount' => $change['requested_headcount']]);
                }

                $locked->update(['updated_by' => $actor->id]);

                activity('recruitment')
                    ->causedBy($actor)
                    ->performedOn($locked)
                    ->withProperties([
                        'company_id' => $locked->company_id,
                        'requirement_number' => $locked->requirement_number,
                        'changes' => $this->activityChanges($changes),
                        'reason' => $trimmedReason,
                    ])
                    ->log("Headcount updated. Reason: {$trimmedReason}");
            }

            return $locked->fresh(['lines.position']) ?? $locked;
        });
    }

    /**
     * @param  list<array{id: int, required_headcount: int}>  $lines
     */
    private function requestRevision(
        RecruitmentRequirement $requirement,
        User $actor,
        array $lines,
        ?string $reason,
    ): RecruitmentRequirement {
        $trimmedReason = trim((string) $reason);
        $initiator = $this->initiatorFor($actor, $requirement);

        if ($initiator === RequirementHeadcountRevisionInitiator::Recruiter && $trimmedReason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required when requesting a headcount revision.',
            ]);
        }

        return DB::transaction(function () use ($requirement, $actor, $lines, $trimmedReason, $initiator): RecruitmentRequirement {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCanRequest($actor, $locked, $initiator);

            $alreadyPending = RecruitmentRequirementHeadcountRevision::query()
                ->where('recruitment_requirement_id', $locked->id)
                ->where('company_id', $locked->company_id)
                ->pending()
                ->lockForUpdate()
                ->exists();

            if ($alreadyPending) {
                throw ValidationException::withMessages([
                    'status' => 'A headcount revision is already waiting for approval.',
                ]);
            }

            $changes = $this->changedLines($locked, $lines, rejectWhenUnchanged: true);

            $revision = RecruitmentRequirementHeadcountRevision::query()->create([
                'company_id' => $locked->company_id,
                'recruitment_requirement_id' => $locked->id,
                'requested_by' => $actor->id,
                'initiator' => $initiator,
                'status' => RequirementHeadcountRevisionStatus::Pending,
                'reason' => $trimmedReason !== '' ? $trimmedReason : null,
            ]);

            foreach ($changes as $change) {
                $revision->lines()->create([
                    'company_id' => $locked->company_id,
                    'recruitment_requirement_line_id' => $change['line']->id,
                    'position_id' => $change['line']->position_id,
                    'position_title' => $change['position_title'],
                    'old_headcount' => $change['old_headcount'],
                    'requested_headcount' => $change['requested_headcount'],
                ]);
            }

            activity('recruitment')
                ->causedBy($actor)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                    'headcount_revision_id' => $revision->id,
                    'initiator' => $initiator->value,
                    'changes' => $this->activityChanges($changes),
                    'reason' => $trimmedReason !== '' ? $trimmedReason : null,
                    'status' => RequirementHeadcountRevisionStatus::Pending->value,
                ])
                ->log('Headcount revision requested.');

            $fresh = $locked->fresh(['creator', 'assignedRecruiter', 'notificationRecipients']) ?? $locked;
            $freshRevision = $revision->fresh('lines') ?? $revision;
            SendRequirementHeadcountRevisionEmails::requested($fresh, $freshRevision);

            return $fresh;
        });
    }

    private function initiatorFor(User $actor, RecruitmentRequirement $requirement): RequirementHeadcountRevisionInitiator
    {
        if (RequirementWorkflowAuthorization::blocksSelfApproval($requirement)) {
            throw ValidationException::withMessages([
                'status' => 'The requester cannot also be the assigned recruiter. A headcount revision cannot be self-approved.',
            ]);
        }

        if (RequirementWorkflowAuthorization::canProposeHeadcountRevisionAsRequester($actor, $requirement)) {
            return RequirementHeadcountRevisionInitiator::Requester;
        }

        if (RequirementWorkflowAuthorization::canProposeHeadcountRevisionAsRecruiter($actor, $requirement)) {
            return RequirementHeadcountRevisionInitiator::Recruiter;
        }

        if (
            RequirementWorkflowAuthorization::isCreator($actor, $requirement)
            && $requirement->assigned_to === null
        ) {
            throw ValidationException::withMessages([
                'status' => 'An assigned recruiter is required before a headcount revision can be submitted.',
            ]);
        }

        throw ValidationException::withMessages([
            'status' => 'You are not allowed to revise headcount for this requirement.',
        ]);
    }

    private function assertCanRequest(
        User $actor,
        RecruitmentRequirement $requirement,
        RequirementHeadcountRevisionInitiator $initiator,
    ): void {
        if (! in_array($requirement->status, [RequirementStatus::Open, RequirementStatus::OnHold], true)) {
            throw ValidationException::withMessages([
                'status' => "Headcount cannot be revised for {$requirement->status->label()} requirement.",
            ]);
        }

        $allowed = $initiator === RequirementHeadcountRevisionInitiator::Requester
            ? RequirementWorkflowAuthorization::canProposeHeadcountRevisionAsRequester($actor, $requirement)
            : RequirementWorkflowAuthorization::canProposeHeadcountRevisionAsRecruiter($actor, $requirement);

        if (! $allowed) {
            throw ValidationException::withMessages([
                'status' => 'You are not allowed to revise headcount for this requirement.',
            ]);
        }
    }

    /**
     * @param  list<array{id: int, required_headcount: int}>  $lines
     * @return list<array{line: RecruitmentRequirementLine, position_title: string, old_headcount: int, requested_headcount: int}>
     */
    private function changedLines(RecruitmentRequirement $requirement, array $lines, bool $rejectWhenUnchanged): array
    {
        $ids = array_map(fn (array $line): int => (int) $line['id'], $lines);

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'lines' => 'Each position can only be revised once in the same request.',
            ]);
        }

        $changes = [];

        foreach ($lines as $lineInput) {
            /** @var RecruitmentRequirementLine|null $line */
            $line = RecruitmentRequirementLine::query()
                ->where('id', (int) $lineInput['id'])
                ->where('recruitment_requirement_id', $requirement->id)
                ->where('company_id', $requirement->company_id)
                ->lockForUpdate()
                ->first();

            if ($line === null) {
                throw ValidationException::withMessages([
                    'lines' => 'One or more positions do not belong to this requirement.',
                ]);
            }

            $position = Position::query()->whereKey($line->position_id)->first();
            if ($position !== null && (int) $position->company_id !== (int) $requirement->company_id) {
                throw ValidationException::withMessages([
                    'lines' => 'One or more positions do not belong to the active company.',
                ]);
            }

            // No authoritative committed-candidate count exists yet. Progress "filled" is unused,
            // so a reduction is limited only by the existing minimum headcount of 1.
            $oldHeadcount = (int) $line->required_headcount;
            $requestedHeadcount = (int) $lineInput['required_headcount'];

            if ($oldHeadcount === $requestedHeadcount) {
                continue;
            }

            $changes[] = [
                'line' => $line,
                'position_title' => $position?->title ?? "Position #{$line->position_id}",
                'old_headcount' => $oldHeadcount,
                'requested_headcount' => $requestedHeadcount,
            ];
        }

        if ($rejectWhenUnchanged && $changes === []) {
            throw ValidationException::withMessages([
                'lines' => 'No headcount changes were made.',
            ]);
        }

        return $changes;
    }

    /**
     * @param  list<array{line: RecruitmentRequirementLine, position_title: string, old_headcount: int, requested_headcount: int}>  $changes
     * @return list<array{position: string, old_headcount: int, new_headcount: int}>
     */
    private function activityChanges(array $changes): array
    {
        return array_map(fn (array $change): array => [
            'position' => $change['position_title'],
            'old_headcount' => $change['old_headcount'],
            'new_headcount' => $change['requested_headcount'],
        ], $changes);
    }
}
