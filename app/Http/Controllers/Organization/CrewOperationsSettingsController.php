<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CrewOperations\UpdateCrewOperationsSettingsRequest;
use App\Support\CrewOperations\CrewOperationsSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrewOperationsSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        return Inertia::render('organization/crew-operations/settings', [
            'department_tree' => CrewOperationsSettings::activeDepartmentTree($companyId),
            'crew_settings' => [
                'pool_department_ids' => CrewOperationsSettings::poolDepartmentIds($companyId),
                'max_home_days' => CrewOperationsSettings::maxHomeDays($companyId),
            ],
        ]);
    }

    public function update(UpdateCrewOperationsSettingsRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        CrewOperationsSettings::saveSettings(
            $companyId,
            $request->validated('pool_department_ids') ?? [],
            (int) $request->validated('max_home_days'),
        );

        return back()->with('success', 'Crew operations settings saved.');
    }
}
