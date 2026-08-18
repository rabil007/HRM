<?php

namespace App\Support\SavedViews;

use App\Enums\SavedViewPage;
use App\Models\SavedView;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StoreSavedView
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function handle(
        User $user,
        int $companyId,
        SavedViewPage $page,
        string $name,
        array $filters,
        bool $isDefault,
    ): SavedView {
        abort_unless($page->userCanAccess($user), 403);

        $normalized = SavedViewCatalog::normalizeForSave($page, $filters, $companyId);
        $name = trim($name);

        $existingCount = SavedView::query()
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->where('page_key', $page)
            ->count();

        if ($existingCount >= SavedView::MAX_PER_USER_COMPANY_PAGE) {
            throw ValidationException::withMessages([
                'name' => 'You can save at most '.SavedView::MAX_PER_USER_COMPANY_PAGE.' views on this page.',
            ]);
        }

        return DB::transaction(function () use ($user, $companyId, $page, $name, $normalized, $isDefault): SavedView {
            if ($isDefault) {
                SavedView::query()
                    ->where('user_id', $user->id)
                    ->where('company_id', $companyId)
                    ->where('page_key', $page)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            return SavedView::query()->create([
                'user_id' => $user->id,
                'company_id' => $companyId,
                'page_key' => $page,
                'name' => $name,
                'filters' => $normalized,
                'is_default' => $isDefault,
            ]);
        });
    }
}
