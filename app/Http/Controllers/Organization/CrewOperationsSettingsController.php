<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CrewOperations\UpdateCrewOperationsSettingsRequest;
use App\Support\CrewOperations\CrewOperationsSettings;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrewOperationsSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $notifications = CrewOperationsSettings::notificationSettings($companyId);
        $companyTimezone = CompanyTimezone::forCompanyId($companyId);

        return Inertia::render('organization/crew-operations/settings', [
            'department_tree' => CrewOperationsSettings::activeDepartmentTree($companyId),
            'notification_users' => CrewOperationsSettings::notificationRecipientOptions($companyId),
            'company_timezone' => $companyTimezone,
            'crew_settings' => [
                'pool_department_ids' => CrewOperationsSettings::poolDepartmentIds($companyId),
                'max_home_days' => CrewOperationsSettings::maxHomeDays($companyId),
                'sync_sea_service' => CrewOperationsSettings::syncSeaServiceEnabled($companyId),
                ...$notifications,
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
            $request->boolean('sync_sea_service'),
            [
                'notifications_enabled' => $request->boolean('notifications_enabled'),
                'notification_recipient_user_ids' => $request->validated('notification_recipient_user_ids') ?? [],
                'alert_signoff_overdue' => $request->boolean('alert_signoff_overdue'),
                'alert_signoff_no_relief' => $request->boolean('alert_signoff_no_relief'),
                'alert_relief_not_ready' => $request->boolean('alert_relief_not_ready'),
                'alert_current_manning_gap' => $request->boolean('alert_current_manning_gap'),
                'alert_projected_manning_gap' => $request->boolean('alert_projected_manning_gap'),
                'notification_email_delivery_mode' => (string) $request->validated('notification_email_delivery_mode'),
                'notification_email_digest_at' => (string) $request->validated('notification_email_digest_at'),
                'notification_email_critical_immediate' => $request->boolean('notification_email_critical_immediate'),
                'actor_id' => $request->user()?->id,
            ],
        );

        return back()->with('success', 'Crew operations settings saved.');
    }
}
