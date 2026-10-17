# HR self-leave submission diagnostics

## Confirmed repository findings

HR employees can submit leave when every mandatory step resolves to a distinct,
authorized employee and linked user. Submission resolves the chain before creating
the request or reserving balance. No approval step is automatically approved.

A reproduced routing defect treated a manager assigned by an earlier **notify-only**
step as unavailable to substitute for a later required HR self-approval step. This
blocked an otherwise valid chain before deduplication could prefer the required
assignment. Alternatives now exclude identities assigned to **required** steps;
notify-only duplicates are removed by the existing required-first deduplication.
Retained entries are ordered by their original policy sequence, so promoting a
listener to a required step cannot move that approval ahead of earlier required
steps. Natural manager reservations remain in place.

The old alternative-required message also covered duplicate required approvers.
Resolution now carries a `LeaveApprovalResolutionReason` enum, and validation
responses distinguish self conflicts, duplicate required identities, invalid
designated approvers, missing approvers, and invalid policies. Candidate details
are returned separately only to users with the active company's
`attendance.leave-approval-settings.view` permission. No permissions are granted.

## Reema: production verification required

No production database, deployment configuration, or authenticated application
session was available in this workspace. Reema's employee/user identity, effective
policy, saved HR/fallback selections, and candidate rejection reasons are therefore
**unconfirmed**. The notify-only defect is a confirmed repository reproduction,
not a claim about Reema's saved policy. Rima/Sara in the settings example are not
evidence of saved configuration.

An authorized administrator should perform these read-only checks in the affected
company. Use the company ID from the trusted company selector and verify Reema's
employee ID; do not identify a login solely by a display name.

1. Inspect Reema's employee `id`, `company_id`, `user_id`, `status`, and
   `department_id`; inspect the linked user's `id` and `status`. Confirm her
   department has `include_in_attendance_leave = true`.
2. Call `ResolveLeaveApprovalPolicy::forEmployee()` for that company. Record the
   policy ID, `source` (`direct`, `inherited`, or `company_default`), source
   department, approval mode, and ordered steps (`sequence`, `approver_type`,
   `approver_employee_id`, `is_required`). An explicit HR employee on a policy
   step overrides the company default.
3. Read `CompanyLeaveApprovalSetting::findForCompany()` and record the actual
   `default_hr_approver_employee_id` and `fallback_approver_employee_id`. This
   method does not create a settings row. Do not use `forCompany()` for diagnostics.
4. Call `ResolveDepartmentManagementChain::forEmployee()` and inspect each source
   department ID, parent ID, manager employee ID, and linked user ID. Managers are
   traversed from the employee's department upward; cycles and repeated managers
   are guarded. Check missing/foreign manager IDs on the department records too,
   since foreign managers are excluded from the returned chain.
5. For each policy-designated, default HR, fallback, and hierarchy candidate,
   fetch the employee constrained by `company_id`. Run
   `LeaveApproverEligibility::evaluate()` to inspect employee status, linked-user
   status, accessible membership, and company-scoped view/approve permissions.
   Run `LeaveRequestVisibility::assignedApproverCanActOnRequester()` to check
   participation and requester visibility. `view_all` does not bypass department
   visibility restrictions; ordinary assigned approvers follow the existing
   assignment visibility rules.
6. Run the resolver **without submitting a request**, as shown below. Inspect
   every rejected identity, used required identity, and natural manager reservation.
   Count distinct linked users as well as employees. An eligible fallback cannot
   fill two mandatory steps. An unrelated invalid designated HR employee is a
   configuration error and is not silently replaced.

For an authorized backend operator, the following can be run in `php artisan tinker`
after substituting the two verified numeric IDs. It performs reads only; it does
not submit leave, reserve balance, sync policies, or persist settings.

```php
$companyId = 123; // Replace with the verified affected company ID.
$employeeId = 456; // Replace with Reema's verified employee ID.
$employee = App\Models\Employee::query()
    ->where('company_id', $companyId)->findOrFail($employeeId);
$effective = app(App\Support\Attendance\ResolveLeaveApprovalPolicy::class)
    ->forEmployee($employee, $companyId);
$settings = App\Models\CompanyLeaveApprovalSetting::findForCompany($companyId);
$hierarchy = App\Support\Departments\ResolveDepartmentManagementChain::forEmployee($employee);
dump($employee->only(['id', 'company_id', 'user_id', 'status', 'department_id']));
dump($effective?->source, $effective?->sourceDepartment?->id,
    $effective?->policy->only(['id', 'approval_mode']),
    $effective?->policy->steps->map->only([
        'sequence', 'approver_type', 'approver_employee_id', 'is_required',
    ])->all());
dump($settings->only(['default_hr_approver_employee_id', 'fallback_approver_employee_id']));
foreach ($hierarchy as $entry) {
    dump($entry['source_department']->only(['id', 'parent_id', 'manager_id']),
        $entry['manager']->only(['id', 'user_id', 'status']));
}
try {
    $chain = app(App\Support\Attendance\ResolveLeaveApprovalChain::class)
        ->handle($employee, $companyId);
    foreach ($chain->steps as $step) {
        dump($step->sequence, $step->approverType->value,
            $step->approverEmployee->id, $step->approverUser?->id, $step->isRequired);
    }
} catch (App\Support\Attendance\LeaveApprovalResolutionException $exception) {
    dump($exception->reason->value, $exception->diagnostics);
}
```

Candidate diagnostic codes identify `requester_identity`,
`already_assigned_required_step`, `reserved_for_required_manager_step`,
`inactive_employee`, `missing_linked_user`, `inactive_linked_user`,
`inactive_company_membership`, `missing_view_permission`,
`missing_approve_permission`, and `outside_requester_visibility_scope`.
Missing selections and IDs not found in the company are distinguished without
looking up or exposing foreign-company employees.

If no valid chain exists, the administrator must explicitly configure enough
distinct eligible approvers through Leave Approval Settings, the effective Leave
Approval Policy, or the department hierarchy. Correct inactive accounts,
membership, permissions, or visibility only after confirming the intended
authority. The application must continue to block submission until all mandatory
steps can be assigned. These checks do not authorize production changes.

## Employee message

**Leave request not submitted**

We couldn't find another authorized person to approve your leave. You cannot
approve your own request. Please contact your HRM administrator to assign a
different approver in Leave Approval Settings.
