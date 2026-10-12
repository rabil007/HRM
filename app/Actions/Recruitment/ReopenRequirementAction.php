<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Support\Recruitment\RecordRequirementStatusTransition;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

final class ReopenRequirementAction
{
    public function execute(
        RecruitmentRequirement $requirement,
        int $userId,
        string $reason,
        ?string $newRequiredByDate = null,
    ): RecruitmentRequirement {
        return DB::transaction(function () use ($requirement, $userId, $reason, $newRequiredByDate): RecruitmentRequirement {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, [RequirementStatus::Completed, RequirementStatus::Cancelled], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only completed or cancelled requirements can be reopened.',
                ]);
            }

            if ($newRequiredByDate !== null) {
                $newCarbon = Carbon::parse($newRequiredByDate)->startOfDay();
                $receivedDate = $locked->request_received_date?->copy()->startOfDay();
                if ($receivedDate !== null && $newCarbon->lt($receivedDate)) {
                    throw ValidationException::withMessages([
                        'new_required_by_date' => 'The new deadline must be on or after the original Request Received from Client date.',
                    ]);
                }
            }

            $fromStatus = $locked->status;
            $originalCancelledAt = $locked->cancelled_at;
            if ($originalCancelledAt === null && $fromStatus === RequirementStatus::Cancelled) {
                $latestCancelTransition = $locked->statusTransitions()
                    ->where('to_status', RequirementStatus::Cancelled->value)
                    ->latest('id')
                    ->first();
                $originalCancelledAt = $latestCancelTransition?->created_at;
            }

            $isPreviouslyApproved = $locked->approved_at !== null || $locked->opened_at !== null;
            $targetStatus = $isPreviouslyApproved ? RequirementStatus::Open : RequirementStatus::Draft;

            $updateData = [
                'status' => $targetStatus,
                'completed_at' => null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'updated_by' => $userId,
            ];

            if (! $isPreviouslyApproved) {
                $updateData['submitted_at'] = null;
                $updateData['submitted_by'] = null;
                $updateData['returned_at'] = null;
                $updateData['returned_by'] = null;
                $updateData['return_reason'] = null;
            }

            if ($newRequiredByDate !== null) {
                $updateData['required_by_date'] = $newRequiredByDate;
            }

            // Preserve historical approval metadata for previously approved requirements; clock resumes from approved_at/opened_at.
            // For unapproved requirements, approved_at/approved_by/opened_at remain null.
            $locked->update($updateData);

            if ($fromStatus === RequirementStatus::Completed) {
                // Completed requirements: restore lines that were closed by completion (Filled or Closed).
                // Deliberately cancelled lines remain Cancelled.
                $locked->lines()
                    ->whereIn('status', [RequirementLineStatus::Filled, RequirementLineStatus::Closed])
                    ->update([
                        'status' => RequirementLineStatus::Open,
                    ]);
            } elseif ($fromStatus === RequirementStatus::Cancelled) {
                // Cancelled requirements: restore only lines closed by the requirement-level cancellation.
                $hasCancelledLines = $locked->lines()
                    ->where('status', RequirementLineStatus::Cancelled)
                    ->exists();

                $linesToReopen = $this->resolveLinesToReopenFromCancelled($locked, $originalCancelledAt);
                if ($hasCancelledLines && $linesToReopen->isEmpty()) {
                    throw ValidationException::withMessages([
                        'status' => 'Cannot reopen requirement because all position lines were deliberately cancelled prior to requirement cancellation.',
                    ]);
                }

                if ($linesToReopen->isNotEmpty()) {
                    $locked->lines()
                        ->whereIn('id', $linesToReopen->pluck('id'))
                        ->where('status', RequirementLineStatus::Cancelled)
                        ->update([
                            'status' => RequirementLineStatus::Open,
                        ]);
                }
            }

            RecordRequirementStatusTransition::handle(
                $locked,
                $fromStatus,
                $targetStatus,
                $userId,
                $reason,
            );

            activity('recruitment')
                ->causedBy($userId)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                    'from_status' => $fromStatus->value,
                    'to_status' => $targetStatus->value,
                    'reason' => $reason,
                    'new_required_by_date' => $newRequiredByDate,
                ])
                ->log("Requirement {$locked->requirement_number} reopened. Reason: {$reason}");

            return $locked;
        });
    }

    /**
     * Resolve position lines that were closed by the requirement cancellation.
     *
     * @return Collection<int, RecruitmentRequirementLine>
     */
    private function resolveLinesToReopenFromCancelled(RecruitmentRequirement $requirement, ?CarbonInterface $originalCancelledAt): Collection
    {
        // 1. Check if the latest cancellation status transition has recorded cancelled_line_ids
        $cancellationTransition = $requirement->statusTransitions()
            ->where('to_status', RequirementStatus::Cancelled->value)
            ->latest('id')
            ->first();

        if (
            $cancellationTransition !== null
            && is_array($cancellationTransition->context)
            && isset($cancellationTransition->context['cancelled_line_ids'])
            && is_array($cancellationTransition->context['cancelled_line_ids'])
        ) {
            $ids = array_map(fn (mixed $id): int => (int) $id, $cancellationTransition->context['cancelled_line_ids']);

            return $requirement->lines()
                ->whereIn('id', $ids)
                ->where('status', RequirementLineStatus::Cancelled)
                ->get();
        }

        // 2. Check activity log for requirement cancellation metadata
        $cancelActivity = Activity::query()
            ->where('subject_type', RecruitmentRequirement::class)
            ->where('subject_id', $requirement->id)
            ->where('company_id', $requirement->company_id)
            ->whereNotNull('properties->cancelled_line_ids')
            ->latest('id')
            ->first();

        if (
            $cancelActivity !== null
            && is_array($cancelActivity->properties->get('cancelled_line_ids'))
        ) {
            $ids = array_map(fn (mixed $id): int => (int) $id, $cancelActivity->properties->get('cancelled_line_ids'));

            return $requirement->lines()
                ->whereIn('id', $ids)
                ->where('status', RequirementLineStatus::Cancelled)
                ->get();
        }

        // 3. Historical fallback:
        // Exclude lines that had deliberate position-level cancellation recorded before the requirement was cancelled.
        $cancelledLines = $requirement->lines()
            ->where('status', RequirementLineStatus::Cancelled)
            ->get();

        if ($originalCancelledAt === null) {
            throw ValidationException::withMessages([
                'status' => 'Cannot safely resolve historical cancellation timing for this requirement. Please contact support.',
            ]);
        }

        return $cancelledLines->filter(function (RecruitmentRequirementLine $line) use ($requirement, $originalCancelledAt): bool {
            $hasPriorLineCancellation = Activity::query()
                ->where('subject_type', RecruitmentRequirementLine::class)
                ->where('subject_id', $line->id)
                ->where('company_id', $requirement->company_id)
                ->where('created_at', '<', $originalCancelledAt)
                ->where(function (Builder $q): void {
                    $q->where('properties->attributes->status', RequirementLineStatus::Cancelled->value)
                        ->orWhere('description', 'like', '%cancelled%');
                })
                ->exists();

            return ! $hasPriorLineCancellation;
        });
    }
}
