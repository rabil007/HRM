<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementHeadcountRevisionInitiator;
use App\Models\RecruitmentRequirementHeadcountRevision;
use App\Models\RecruitmentRequirementHeadcountRevisionLine;
use Illuminate\Support\Collection;

final class RequirementHeadcountRevisionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(RecruitmentRequirementHeadcountRevision $revision): array
    {
        $revision->loadMissing('lines', 'requestedBy:id,name', 'decidedBy:id,name');

        return [
            'id' => (int) $revision->id,
            'initiator' => $revision->initiator->value,
            'initiator_label' => $revision->initiator->label(),
            'status' => $revision->status->value,
            'status_label' => $revision->status->isPending()
                ? $revision->status->waitingLabel($revision->initiator)
                : $revision->status->label(),
            'note_label' => $revision->initiator === RequirementHeadcountRevisionInitiator::Requester ? 'Note' : 'Reason',
            'reason' => filled($revision->reason) ? (string) $revision->reason : null,
            'decision_note' => filled($revision->decision_note) ? (string) $revision->decision_note : null,
            'requested_by' => $revision->requested_by !== null ? (int) $revision->requested_by : null,
            'requested_by_name' => $revision->requestedBy?->name,
            'decided_by' => $revision->decided_by !== null ? (int) $revision->decided_by : null,
            'decided_by_name' => $revision->decidedBy?->name,
            'requested_at_formatted' => $revision->created_at?->format('d M Y H:i') ?? '—',
            'decided_at_formatted' => $revision->decided_at?->format('d M Y H:i'),
            'lines' => $revision->lines
                ->map(fn (RecruitmentRequirementHeadcountRevisionLine $line): array => [
                    'id' => (int) $line->id,
                    'recruitment_requirement_line_id' => $line->recruitment_requirement_line_id !== null
                        ? (int) $line->recruitment_requirement_line_id
                        : null,
                    'position_id' => $line->position_id !== null ? (int) $line->position_id : null,
                    'position_title' => (string) $line->position_title,
                    'old_headcount' => (int) $line->old_headcount,
                    'requested_headcount' => (int) $line->requested_headcount,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, RecruitmentRequirementHeadcountRevision>  $revisions
     * @return list<array<string, mixed>>
     */
    public static function history(Collection $revisions): array
    {
        return $revisions
            ->sortByDesc(fn (RecruitmentRequirementHeadcountRevision $revision): int => (int) $revision->id)
            ->values()
            ->map(fn (RecruitmentRequirementHeadcountRevision $revision): array => self::toArray($revision))
            ->all();
    }
}
