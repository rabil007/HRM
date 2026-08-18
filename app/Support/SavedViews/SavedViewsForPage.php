<?php

namespace App\Support\SavedViews;

use App\Enums\SavedViewPage;
use App\Models\SavedView;
use App\Models\User;

final class SavedViewsForPage
{
    /**
     * @return list<array{id: int, name: string, filters: array<string, string>, is_default: bool}>
     */
    public static function props(?User $user, int $companyId, SavedViewPage $page): array
    {
        if ($user === null || $companyId < 1 || ! $page->userCanAccess($user)) {
            return [];
        }

        return SavedView::query()
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->where('page_key', $page)
            ->orderBy('name')
            ->get(['id', 'name', 'filters', 'is_default'])
            ->map(fn (SavedView $view): array => [
                'id' => $view->id,
                'name' => $view->name,
                'filters' => SavedViewCatalog::forApply($page, $view->filters ?? []),
                'is_default' => $view->is_default,
            ])
            ->all();
    }
}
