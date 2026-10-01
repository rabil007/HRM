<?php

namespace App\Support\Recruitment;

use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementNotificationRecipient;
use Illuminate\Validation\ValidationException;

final class SyncRequirementNotificationRecipients
{
    /**
     * @param  list<int|string>|null  $userIds
     * @return list<int>
     */
    public static function sync(RecruitmentRequirement $requirement, ?array $userIds): array
    {
        $companyId = (int) $requirement->company_id;
        $normalized = self::normalizeAndValidate($companyId, $userIds, $requirement->assigned_to);

        $existing = RecruitmentRequirementNotificationRecipient::query()
            ->where('recruitment_requirement_id', $requirement->id)
            ->where('company_id', $companyId)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $toRemove = array_values(array_diff($existing, $normalized));
        $toAdd = array_values(array_diff($normalized, $existing));

        if ($toRemove !== []) {
            RecruitmentRequirementNotificationRecipient::query()
                ->where('recruitment_requirement_id', $requirement->id)
                ->where('company_id', $companyId)
                ->whereIn('user_id', $toRemove)
                ->delete();
        }

        $now = now();
        foreach ($toAdd as $userId) {
            RecruitmentRequirementNotificationRecipient::query()->create([
                'company_id' => $companyId,
                'recruitment_requirement_id' => $requirement->id,
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $normalized;
    }

    /**
     * @param  list<int|string>|null  $userIds
     * @return list<int>
     */
    public static function normalizeAndValidate(int $companyId, ?array $userIds, ?int $assignedTo = null): array
    {
        if ($userIds === null) {
            return [];
        }

        $ids = collect($userIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $validIds = RecruiterOptionsQuery::baseQuery($companyId)
            ->whereIn('users.id', $ids)
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $invalid = array_values(array_diff($ids, $validIds));
        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'notification_recipient_ids' => 'One or more notification recipients are inactive or do not belong to this company.',
            ]);
        }

        if ($assignedTo !== null) {
            $ids = array_values(array_filter($ids, fn (int $id): bool => $id !== $assignedTo));
        }

        return $ids;
    }
}
