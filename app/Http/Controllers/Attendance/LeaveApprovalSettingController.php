<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\UpdateLeaveApprovalSettingRequest;
use App\Models\CompanyLeaveApprovalSetting;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\PermissionRegistrar;

class LeaveApprovalSettingController extends Controller
{
    public function edit(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $settings = CompanyLeaveApprovalSetting::forCompany($companyId);
        $settings->load([
            'defaultHrApprover:id,name,employee_no,status,user_id',
            'fallbackApprover:id,name,employee_no,status,user_id',
        ]);

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->with('user:id,name,email,status')
            ->orderBy('name')
            ->get(['id', 'employee_no', 'name', 'status', 'user_id']);

        return Inertia::render('attendance/leave-approval-settings', [
            'settings' => [
                'default_hr_approver_employee_id' => $settings->default_hr_approver_employee_id,
                'fallback_approver_employee_id' => $settings->fallback_approver_employee_id,
                'default_hr_approver' => $this->serializeEmployeeOption($settings->defaultHrApprover),
                'fallback_approver' => $this->serializeEmployeeOption($settings->fallbackApprover),
            ],
            'employees' => $employees->map(fn (Employee $employee) => $this->serializeEmployeeOption($employee))->values()->all(),
            'warnings' => [
                'default_hr_approver' => $this->approverWarnings($settings->defaultHrApprover, $companyId),
                'fallback_approver' => $this->approverWarnings($settings->fallbackApprover, $companyId),
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

        $warningMessages = array_values(array_filter([
            ...$this->approverWarnings($settings->defaultHrApprover, $companyId, 'Default HR approver'),
            ...$this->approverWarnings($settings->fallbackApprover, $companyId, 'Fallback approver'),
        ]));

        $redirect = redirect()
            ->route('attendance.leave-approval-settings.edit')
            ->with('success', 'Leave approval settings saved successfully.');

        if ($warningMessages !== []) {
            $redirect->with('warning', implode(' ', $warningMessages));
        }

        return $redirect;
    }

    /**
     * @return array{id: int, name: string|null, employee_no: string|null, status: string|null}|null
     */
    private function serializeEmployeeOption(?Employee $employee): ?array
    {
        if ($employee === null) {
            return null;
        }

        return [
            'id' => (int) $employee->id,
            'name' => $employee->name,
            'employee_no' => $employee->employee_no,
            'status' => $employee->status,
        ];
    }

    /**
     * @return list<string>
     */
    private function approverWarnings(?Employee $employee, int $companyId, ?string $label = null): array
    {
        if ($employee === null) {
            return [];
        }

        $prefix = $label !== null ? "{$label}: " : '';
        $warnings = [];

        if ($employee->status !== 'active') {
            $warnings[] = "{$prefix}{$employee->name} is not an active employee.";
        }

        $user = $employee->relationLoaded('user')
            ? $employee->user
            : $employee->user()->first(['id', 'name', 'email', 'status']);

        if ($user === null) {
            $warnings[] = "{$prefix}{$employee->name} is not linked to a user account.";

            return $warnings;
        }

        if ($user->status !== 'active') {
            $warnings[] = "{$prefix}{$employee->name} is linked to an inactive user.";
        }

        if (! $this->userHasApprovePermission($user, $companyId)) {
            $warnings[] = "{$prefix}{$employee->name}'s linked user does not have leave-request approve permission. Grant it manually — it is not auto-assigned.";
        }

        return $warnings;
    }

    private function userHasApprovePermission(User $user, int $companyId): bool
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($companyId);
            $user->unsetRelation('roles')->unsetRelation('permissions');

            return $user->can('attendance.leave-requests.approve');
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
