<?php

namespace App\Support\Recruitment;

use App\Models\RecruitmentRequirementDeadlineExtension;
use App\Support\Settings\PlatformDateFormatter;
use Illuminate\Support\Collection;

final class RequirementDeadlineExtensionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(RecruitmentRequirementDeadlineExtension $extension): array
    {
        return [
            'id' => (int) $extension->id,
            'initiator' => $extension->initiator->value,
            'initiator_label' => $extension->initiator->label(),
            'status' => $extension->status->value,
            'status_label' => $extension->status->label(),
            'old_deadline' => $extension->old_deadline?->format('Y-m-d'),
            'old_deadline_formatted' => PlatformDateFormatter::make()->date($extension->old_deadline) ?? '—',
            'requested_deadline' => $extension->requested_deadline?->format('Y-m-d'),
            'requested_deadline_formatted' => PlatformDateFormatter::make()->date($extension->requested_deadline) ?? '—',
            'reason' => filled($extension->reason) ? (string) $extension->reason : null,
            'decision_note' => filled($extension->decision_note) ? (string) $extension->decision_note : null,
            'requested_by' => $extension->requested_by !== null ? (int) $extension->requested_by : null,
            'requested_by_name' => $extension->requestedBy?->name,
            'decided_by' => $extension->decided_by !== null ? (int) $extension->decided_by : null,
            'decided_by_name' => $extension->decidedBy?->name,
            'requested_at_formatted' => PlatformDateFormatter::make()->dateTime($extension->created_at) ?? '—',
            'decided_at_formatted' => PlatformDateFormatter::make()->dateTime($extension->decided_at, null),
        ];
    }

    /**
     * @param  Collection<int, RecruitmentRequirementDeadlineExtension>  $extensions
     * @return list<array<string, mixed>>
     */
    public static function history(Collection $extensions): array
    {
        return $extensions
            ->sortByDesc(fn (RecruitmentRequirementDeadlineExtension $extension): int => (int) $extension->id)
            ->values()
            ->map(fn (RecruitmentRequirementDeadlineExtension $extension): array => self::toArray($extension))
            ->all();
    }
}
