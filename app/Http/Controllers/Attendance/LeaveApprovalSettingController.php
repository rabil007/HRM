<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\UpdateLeaveApprovalSettingRequest;
use App\Models\CompanyLeaveApprovalSetting;
use App\Support\Attendance\PresentLeaveApproverOption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeaveApprovalSettingController extends Controller
{
    public function __construct(
        private PresentLeaveApproverOption $presentApproverOption,
    ) {}

    public function edit(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $settings = CompanyLeaveApprovalSetting::forCompany($companyId);
        $settings->load([
            'defaultHrApprover.user:id,name,email,status',
            'fallbackApprover.user:id,name,email,status',
        ]);

        $defaultHr = $settings->defaultHrApprover !== null
            ? $this->presentApproverOption->present($settings->defaultHrApprover, $companyId)
            : null;
        $fallback = $settings->fallbackApprover !== null
            ? $this->presentApproverOption->present($settings->fallbackApprover, $companyId)
            : null;

        $includeEmployeeIds = array_values(array_filter([
            $settings->default_hr_approver_employee_id !== null
                ? (int) $settings->default_hr_approver_employee_id
                : null,
            $settings->fallback_approver_employee_id !== null
                ? (int) $settings->fallback_approver_employee_id
                : null,
        ]));

        return Inertia::render('attendance/leave-approval-settings', [
            'settings' => [
                'default_hr_approver_employee_id' => $settings->default_hr_approver_employee_id,
                'fallback_approver_employee_id' => $settings->fallback_approver_employee_id,
                'default_hr_approver' => $defaultHr,
                'fallback_approver' => $fallback,
            ],
            'employees' => $this->presentApproverOption->forCompany(
                $companyId,
                activeOnly: true,
                includeEmployeeIds: $includeEmployeeIds,
            ),
            'warnings' => [
                'default_hr_approver' => $defaultHr['warnings'] ?? [],
                'fallback_approver' => $fallback['warnings'] ?? [],
            ],
            'can' => [
                'update' => $request->user()?->can('attendance.leave-approval-settings.update') ?? false,
            ],
        ]);
    }

    public function update(UpdateLeaveApprovalSettingRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $data = $request->validated();

        $settings = CompanyLeaveApprovalSetting::forCompany($companyId);
        $settings->update([
            'default_hr_approver_employee_id' => $data['default_hr_approver_employee_id'] ?? null,
            'fallback_approver_employee_id' => $data['fallback_approver_employee_id'] ?? null,
            'updated_by' => $request->user()?->id,
        ]);

        $settings->load([
            'defaultHrApprover.user:id,name,email,status',
            'fallbackApprover.user:id,name,email,status',
        ]);

        $labelled = [];

        if ($settings->defaultHrApprover !== null) {
            foreach ($this->presentApproverOption->present($settings->defaultHrApprover, $companyId)['warnings'] as $warning) {
                $labelled[] = "Default HR approver: {$warning}";
            }
        }

        if ($settings->fallbackApprover !== null) {
            foreach ($this->presentApproverOption->present($settings->fallbackApprover, $companyId)['warnings'] as $warning) {
                $labelled[] = "Fallback approver: {$warning}";
            }
        }

        $redirect = redirect()
            ->route('attendance.leave-approval-settings.edit')
            ->with('success', 'Leave approval settings saved successfully.');

        if ($labelled !== []) {
            $redirect->with('warning', implode(' ', $labelled));
        }

        return $redirect;
    }
}
