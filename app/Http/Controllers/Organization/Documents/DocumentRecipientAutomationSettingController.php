<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Documents\UpdateDocumentRecipientAutomationSettingRequest;
use App\Models\DocumentRecipientAutomationSetting;
use App\Models\DocumentRecipientRequest;
use App\Models\User;
use App\Support\Documents\RecipientRequests\Automation\DocumentRecipientAutomationPolicy;
use Illuminate\Http\RedirectResponse;

class DocumentRecipientAutomationSettingController extends Controller
{
    public function update(UpdateDocumentRecipientAutomationSettingRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();

        $policy = app(DocumentRecipientAutomationPolicy::class);
        $enabled = $request->boolean('reminders_enabled');
        $rawDays = $request->validated('reminder_days_before_expiry') ?? [];

        $days = $enabled
            ? $policy->validateAndNormalizeDays($rawDays)
            : $policy->normalizeDays($rawDays);

        if ($days === []) {
            $days = DocumentRecipientAutomationSetting::defaultAttributes()['reminder_days_before_expiry'];
        }

        if ($enabled && $days === []) {
            $days = DocumentRecipientAutomationSetting::defaultAttributes()['reminder_days_before_expiry'];
        }

        $settings = DocumentRecipientAutomationSetting::query()->updateOrCreate(
            ['company_id' => $companyId],
            [
                'reminders_enabled' => $enabled,
                'reminder_days_before_expiry' => $days,
                'updated_by' => $user->id,
            ],
        );

        if ($settings->created_by === null) {
            $settings->update(['created_by' => $user->id]);
        }

        activity()
            ->causedBy($user)
            ->performedOn($settings)
            ->tap(fn ($activity) => $activity->company_id = $companyId)
            ->withProperties([
                'action' => 'recipient_automation_settings_updated',
                'reminders_enabled' => $enabled,
                'days_before_expiry' => $days,
            ])
            ->log('Recipient reminder automation settings updated');

        return redirect()
            ->route('organization.documents.requests', ['tab' => 'recipient'])
            ->with('success', 'Reminder settings saved.');
    }

    /**
     * @return array{
     *     reminders_enabled: bool,
     *     reminder_days_before_expiry: list<int>,
     *     request_expiry_days: int,
     *     can_view: bool,
     *     can_update: bool
     * }
     */
    public static function propsFor(?User $user, int $companyId): array
    {
        $canView = $user?->can('documents.recipient-automation.view') ?? false;
        $canUpdate = $user?->can('documents.recipient-automation.update') ?? false;

        if (! $canView) {
            return [
                'reminders_enabled' => false,
                'reminder_days_before_expiry' => DocumentRecipientAutomationSetting::defaultAttributes()['reminder_days_before_expiry'],
                'request_expiry_days' => DocumentRecipientRequest::EXPIRY_DAYS,
                'can_view' => false,
                'can_update' => false,
            ];
        }

        $resolved = app(DocumentRecipientAutomationPolicy::class)->resolveForCompany($companyId);

        return [
            'reminders_enabled' => $resolved['reminders_enabled'],
            'reminder_days_before_expiry' => $resolved['reminder_days_before_expiry'],
            'request_expiry_days' => DocumentRecipientRequest::EXPIRY_DAYS,
            'can_view' => true,
            'can_update' => $canUpdate,
        ];
    }
}
