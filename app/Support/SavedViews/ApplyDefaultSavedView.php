<?php

namespace App\Support\SavedViews;

use App\Enums\SavedViewPage;
use App\Models\SavedView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ApplyDefaultSavedView
{
    public static function maybeRedirect(Request $request, SavedViewPage $page): ?RedirectResponse
    {
        if ($request->headers->has('X-Inertia-Partial-Data')) {
            return null;
        }

        if (SavedViewCatalog::queryHasExplicitFilters($request, $page)) {
            return null;
        }

        $user = $request->user();
        $companyId = (int) $request->attributes->get('current_company_id');

        if ($user === null || $companyId < 1 || ! $page->userCanAccess($user)) {
            return null;
        }

        $view = SavedView::query()
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->where('page_key', $page)
            ->where('is_default', true)
            ->first();

        if ($view === null) {
            return null;
        }

        $filters = SavedViewCatalog::forApply($page, $view->filters ?? []);

        if ($filters === []) {
            return null;
        }

        return redirect()->route($page->routeName(), $filters);
    }
}
