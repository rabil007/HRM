<?php

namespace App\Http\Controllers\Attendance;

use App\Enums\LeaveApprovalApproverType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\StoreLeaveApprovalPolicyRequest;
use App\Http\Requests\Attendance\UpdateLeaveApprovalPolicyRequest;
use App\Http\Requests\Attendance\UpdateLeaveApprovalPolicyStatusRequest;
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveApprovalPolicyStep;
use App\Support\Attendance\AssertLeaveApprovalPolicyDefaultInvariant;
use App\Support\Attendance\PresentLeaveApproverOption;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LeaveApprovalPolicyController extends Controller
{
    use ResolvesPerPage;

    public function __construct(
        private PresentLeaveApproverOption $presentApproverOption,
        private AssertLeaveApprovalPolicyDefaultInvariant $defaultInvariant,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage($request);
        $search = trim((string) $request->query('search', ''));
        $user = $request->user();

        $paginator = LeaveApprovalPolicy::query()
            ->with(['steps' => fn ($query) => $query->orderBy('sequence')])
            ->withCount('departments')
            ->where('company_id', $companyId)
            ->when($search, function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $policies = $paginator->through(fn (LeaveApprovalPolicy $policy) => $this->serializePolicy($policy));

        return Inertia::render('attendance/leave-approval-policies', [
            'policies' => $policies->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'approver_types' => collect(LeaveApprovalApproverType::cases())
                ->map(fn (LeaveApprovalApproverType $type) => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'requires_employee' => $type->requiresEmployeeSelection(),
                    'allows_employee_override' => $type->allowsOptionalEmployeeOverride(),
                ])
                ->values()
                ->all(),
            'employees' => $this->presentApproverOption->forCompany($companyId),
            'can' => [
                'create' => $user?->can('attendance.leave-approval-policies.create') ?? false,
                'update' => $user?->can('attendance.leave-approval-policies.update') ?? false,
                'delete' => $user?->can('attendance.leave-approval-policies.delete') ?? false,
                'manage_settings' => $user?->can('attendance.leave-approval-settings.update') ?? false,
            ],
        ]);
    }

    public function store(StoreLeaveApprovalPolicyRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $data = $request->validated();
        $userId = $request->user()?->id;

        $this->defaultInvariant->assertForCreate($data);

        DB::transaction(function () use ($companyId, $data, $userId): void {
            $policy = LeaveApprovalPolicy::query()->create([
                'company_id' => $companyId,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_default' => false,
                'status' => $data['status'] ?? 'active',
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->syncSteps($policy, $companyId, $data['steps']);

            if (! empty($data['is_default'])) {
                $this->defaultInvariant->assertCanBecomeDefault($policy);
                $policy->markAsCompanyDefault();
            }
        });

        return redirect()
            ->route('attendance.leave-approval-policies.index')
            ->with('success', 'Leave approval policy created successfully.');
    }

    public function update(UpdateLeaveApprovalPolicyRequest $request, LeaveApprovalPolicy $leaveApprovalPolicy): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $leaveApprovalPolicy->company_id === $companyId, 404);

        $data = $request->validated();
        $userId = $request->user()?->id;

        $this->defaultInvariant->assertForUpdate($leaveApprovalPolicy, $data);

        DB::transaction(function () use ($leaveApprovalPolicy, $companyId, $data, $userId): void {
            $leaveApprovalPolicy->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? $leaveApprovalPolicy->status,
                'updated_by' => $userId,
            ]);

            $this->syncSteps($leaveApprovalPolicy, $companyId, $data['steps']);

            if (! empty($data['is_default'])) {
                $this->defaultInvariant->assertCanBecomeDefault($leaveApprovalPolicy);
                $leaveApprovalPolicy->markAsCompanyDefault();
            } elseif (array_key_exists('is_default', $data) && ! $data['is_default'] && $leaveApprovalPolicy->is_default) {
                $leaveApprovalPolicy->forceFill(['is_default' => false])->save();
            }
        });

        return redirect()
            ->route('attendance.leave-approval-policies.index')
            ->with('success', 'Leave approval policy updated successfully.');
    }

    public function updateStatus(UpdateLeaveApprovalPolicyStatusRequest $request, LeaveApprovalPolicy $leaveApprovalPolicy): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $leaveApprovalPolicy->company_id === $companyId, 404);

        $status = $request->validated('status');

        if ($status === 'inactive') {
            $this->defaultInvariant->assertCanDeactivate($leaveApprovalPolicy);
        }

        $leaveApprovalPolicy->update([
            'status' => $status,
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('attendance.leave-approval-policies.index')
            ->with('success', 'Leave approval policy status updated successfully.');
    }

    public function setDefault(Request $request, LeaveApprovalPolicy $leaveApprovalPolicy): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $leaveApprovalPolicy->company_id === $companyId, 404);

        $this->defaultInvariant->assertCanBecomeDefault($leaveApprovalPolicy);

        $leaveApprovalPolicy->markAsCompanyDefault();
        $leaveApprovalPolicy->forceFill(['updated_by' => $request->user()?->id])->save();

        return redirect()
            ->route('attendance.leave-approval-policies.index')
            ->with('success', 'Company default leave approval policy updated.');
    }

    public function moveStep(Request $request, LeaveApprovalPolicy $leaveApprovalPolicy, LeaveApprovalPolicyStep $step): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $leaveApprovalPolicy->company_id === $companyId, 404);
        abort_unless((int) $step->company_id === $companyId, 404);
        abort_unless((int) $step->leave_approval_policy_id === (int) $leaveApprovalPolicy->id, 404);

        $direction = (string) $request->input('direction', '');

        if (! in_array($direction, ['up', 'down'], true)) {
            throw ValidationException::withMessages([
                'direction' => 'Direction must be up or down.',
            ]);
        }

        DB::transaction(function () use ($leaveApprovalPolicy, $step, $direction, $companyId): void {
            $steps = LeaveApprovalPolicyStep::query()
                ->where('company_id', $companyId)
                ->where('leave_approval_policy_id', $leaveApprovalPolicy->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            $index = $steps->search(fn (LeaveApprovalPolicyStep $item) => (int) $item->id === (int) $step->id);

            if ($index === false) {
                abort(404);
            }

            $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

            if ($swapWith < 0 || $swapWith >= $steps->count()) {
                return;
            }

            /** @var LeaveApprovalPolicyStep $current */
            $current = $steps[$index];
            /** @var LeaveApprovalPolicyStep $neighbor */
            $neighbor = $steps[$swapWith];

            $currentSequence = (int) $current->sequence;
            $neighborSequence = (int) $neighbor->sequence;

            $current->forceFill(['sequence' => 0])->save();
            $neighbor->forceFill(['sequence' => $currentSequence])->save();
            $current->forceFill(['sequence' => $neighborSequence])->save();
        });

        return redirect()
            ->route('attendance.leave-approval-policies.index')
            ->with('success', 'Approval step order updated.');
    }

    public function destroy(LeaveApprovalPolicy $leaveApprovalPolicy): RedirectResponse
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $leaveApprovalPolicy->company_id === $companyId, 404);

        if ($leaveApprovalPolicy->is_default) {
            return redirect()
                ->route('attendance.leave-approval-policies.index')
                ->withErrors(['policy' => 'The company default leave approval policy cannot be deleted.']);
        }

        if (! $leaveApprovalPolicy->isSafelyDeletable()) {
            return redirect()
                ->route('attendance.leave-approval-policies.index')
                ->withErrors(['policy' => 'This leave approval policy cannot be deleted because it is assigned to departments or used by leave request approvals.']);
        }

        $leaveApprovalPolicy->delete();

        return redirect()
            ->route('attendance.leave-approval-policies.index')
            ->with('success', 'Leave approval policy deleted successfully.');
    }

    /**
     * @param  list<array{approver_type: string, approver_employee_id?: int|null, is_required?: bool}>  $steps
     */
    private function syncSteps(LeaveApprovalPolicy $policy, int $companyId, array $steps): void
    {
        $policy->steps()->delete();

        foreach (array_values($steps) as $index => $step) {
            LeaveApprovalPolicyStep::query()->create([
                'company_id' => $companyId,
                'leave_approval_policy_id' => $policy->id,
                'sequence' => $index + 1,
                'approver_type' => $step['approver_type'],
                'approver_employee_id' => $step['approver_employee_id'] ?? null,
                'is_required' => array_key_exists('is_required', $step)
                    ? (bool) $step['is_required']
                    : true,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePolicy(LeaveApprovalPolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'name' => $policy->name,
            'description' => $policy->description,
            'is_default' => (bool) $policy->is_default,
            'status' => $policy->status,
            'departments_count' => $policy->departments_count ?? $policy->departments()->count(),
            'steps' => $policy->steps
                ->map(fn (LeaveApprovalPolicyStep $step) => [
                    'id' => $step->id,
                    'sequence' => $step->sequence,
                    'approver_type' => $step->approver_type?->value ?? $step->approver_type,
                    'approver_type_label' => $step->approver_type instanceof LeaveApprovalApproverType
                        ? $step->approver_type->label()
                        : null,
                    'approver_employee_id' => $step->approver_employee_id,
                    'is_required' => (bool) $step->is_required,
                ])
                ->values()
                ->all(),
            'created_at' => $policy->created_at?->toIso8601String(),
            'updated_at' => $policy->updated_at?->toIso8601String(),
        ];
    }
}
