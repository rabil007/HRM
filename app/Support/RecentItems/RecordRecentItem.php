<?php

namespace App\Support\RecentItems;

use App\Enums\RecentItemType;
use App\Models\RecentItem;
use App\Models\User;

final class RecordRecentItem
{
    public function handle(User $user, int $companyId, RecentItemType $type, int $recordId): void
    {
        if ($companyId < 1 || $recordId < 1) {
            return;
        }

        if (! $type->isAccessible($user)) {
            return;
        }

        RecentItem::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'company_id' => $companyId,
                'record_type' => $type,
                'record_id' => $recordId,
            ],
            [
                'last_viewed_at' => now(),
            ],
        );

        $this->trim($user->id, $companyId);
    }

    private function trim(int $userId, int $companyId): void
    {
        $keepIds = RecentItem::query()
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->orderByDesc('last_viewed_at')
            ->orderByDesc('id')
            ->limit(RecentItem::MAX_PER_USER_COMPANY)
            ->pluck('id');

        if ($keepIds->isEmpty()) {
            return;
        }

        RecentItem::query()
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }
}
