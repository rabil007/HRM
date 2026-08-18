<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavedViews\DestroySavedViewRequest;
use App\Http\Requests\SavedViews\StoreSavedViewRequest;
use App\Http\Requests\SavedViews\UpdateSavedViewRequest;
use App\Models\SavedView;
use App\Support\SavedViews\DeleteSavedView;
use App\Support\SavedViews\StoreSavedView;
use App\Support\SavedViews\UpdateSavedView;
use Illuminate\Http\RedirectResponse;

class SavedViewController extends Controller
{
    public function store(StoreSavedViewRequest $request, StoreSavedView $store): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless($companyId > 0, 403);

        $store->handle(
            $user,
            $companyId,
            $request->page(),
            (string) $request->validated('name'),
            $request->filters(),
            $request->boolean('is_default'),
        );

        return back()->with('success', 'Saved view created.');
    }

    public function update(UpdateSavedViewRequest $request, SavedView $savedView, UpdateSavedView $update): RedirectResponse
    {
        $name = $request->exists('name') ? (string) $request->validated('name') : null;
        $isDefault = $request->exists('is_default') ? $request->boolean('is_default') : null;

        $update->handle($savedView, $name, $isDefault);

        return back()->with('success', 'Saved view updated.');
    }

    public function destroy(DestroySavedViewRequest $request, SavedView $savedView, DeleteSavedView $delete): RedirectResponse
    {
        $delete->handle($savedView);

        return back()->with('success', 'Saved view deleted.');
    }
}
