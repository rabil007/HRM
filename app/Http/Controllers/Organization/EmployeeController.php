<?php

namespace App\Http\Controllers\Organization;

use App\Enums\RecentItemType;
use App\Enums\Recruitment\CandidateOfferStatus;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\SavedViewPage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\AssignEmployeeProfileTemplateRequest;
use App\Http\Requests\Organization\Employee\PreviewEmployeeHireDateChangeRequest;
use App\Http\Requests\Organization\Employee\StoreEmployeeRequest;
use App\Http\Requests\Organization\Employee\StoreEnsureEmployeeRequest;
use App\Http\Requests\Organization\Employee\UpdateEmployeeRequest;
use App\Http\Requests\Organization\Employee\UpdateEmployeeStatusRequest;
use App\Models\Employee;
use App\Models\EmployeeProfileTemplate;
use App\Models\Position;
use App\Models\RecruitmentCandidate;
use App\Services\Settings\AiSettingsService;
use App\Support\Attendance\EmployeeHireDateChangeGuard;
use App\Support\CrewMovements\CrewAssignmentStatusResolver;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateResolver;
use App\Support\Employees\Actions\ApplyEmployeeUpdateWithDepartmentGuard;
use App\Support\Employees\Actions\CreateEmployee;
use App\Support\Employees\Actions\CreateEmployeeFromName;
use App\Support\Employees\Actions\GuardEmployeeStatusTransition;
use App\Support\Employees\Actions\SyncEmployeeWorkAssignments;
use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\DraftEmployeeNumber;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use App\Support\Employees\EmployeeExportFieldRegistry;
use App\Support\Employees\EmployeeFormOptions;
use App\Support\Employees\EmployeePagePermissions;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Employees\ProvisionalEmployeeAccess;
use App\Support\Employees\Resources\EmployeeListResource;
use App\Support\Employees\Services\EmployeeProfilePageData;
use App\Support\Pagination\ResolvesPerPage;
use App\Support\Payroll\PayrollRecordLinkage;
use App\Support\RecentItems\RecordRecentItem;
use App\Support\Recruitment\Candidates\Actions\ConvertCandidateToEmployee;
use App\Support\Recruitment\Candidates\Actions\LinkCandidateToEmployee;
use App\Support\Recruitment\Candidates\FindCandidateDuplicateEmployees;
use App\Support\SavedViews\ApplyDefaultSavedView;
use App\Support\SavedViews\SavedViewsForPage;
use App\Support\Uploads\UploadedFileStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EmployeeController extends Controller
{
    use ResolvesPerPage;

    public function index(AiSettingsService $aiSettings): InertiaResponse|RedirectResponse
    {
        $redirect = ApplyDefaultSavedView::maybeRedirect(request(), SavedViewPage::Employees);

        if ($redirect !== null) {
            return $redirect;
        }

        $companyId = (int) request()->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage(request());
        $directoryFilters = EmployeeDirectoryFilters::fromRequest(request());

        $paginator = (new EmployeeDirectoryQuery($companyId, $directoryFilters, request()->user()))
            ->apply(
                Employee::query()->with([
                    'branch:id,name',
                    'department:id,name',
                    'position:id,title',
                    'user:id,name,email',
                    'religionRef:id,name',
                    'genderRef:id,name',
                    'nationalityRef:id,name,code',
                    'primaryBankAccount.bank:id,name',
                    'currentContract',
                    'employeeProfileTemplate:id,name,configuration_json',
                ]),
            )
            ->paginate($perPage)
            ->appends($directoryFilters->toQueryArray());

        $pageEmployees = $paginator->getCollection();
        $crewStatusByEmployeeId = [];

        $needsCrewStatus = $pageEmployees->contains(
            fn (Employee $employee): bool => EmployeeProfileTemplateResolver::employeeFieldVisible(
                $employee->employeeProfileTemplate,
                'crew_status',
            ),
        );

        if ($needsCrewStatus) {
            $crewStatusByEmployeeId = (new CrewAssignmentStatusResolver)->forEmployees(
                $pageEmployees,
                $companyId,
            );
        }

        $employees = $paginator->through(
            fn (Employee $employee) => EmployeeListResource::toArray($employee, $crewStatusByEmployeeId),
        );

        $formOptions = fn (): array => once(fn (): array => EmployeeFormOptions::for($companyId, request()->user()));

        return Inertia::render('organization/employees', [
            'employees' => $employees->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $directoryFilters->search,
            'filters' => $directoryFilters->toInertiaFilters(),
            'departments' => fn () => $formOptions()['departments'],
            'positions' => fn () => $formOptions()['positions'],
            'managers' => fn () => EmployeeFormOptions::departmentManagersForFilter($companyId, request()->user()),
            'users' => fn () => $formOptions()['users'],
            'countries' => fn () => $formOptions()['countries'],
            'religions' => fn () => $formOptions()['religions'],
            'genders' => fn () => $formOptions()['genders'],
            'visa_types' => fn () => $formOptions()['visa_types'],
            'company_visa_types' => fn () => $formOptions()['company_visa_types'],
            'approval_locations' => fn () => $formOptions()['approval_locations'],
            'sssa_options' => fn () => $formOptions()['sssa_options'],
            'clients' => fn () => $formOptions()['clients'],
            'projects' => fn () => $formOptions()['projects'],
            'banks' => fn () => $formOptions()['banks'],
            'roles' => fn () => $formOptions()['roles'],
            'export_field_options' => fn () => EmployeeExportFieldRegistry::optionsForUser(request()->user()),
            'department_tree' => fn () => BuildDepartmentEmployeeTree::for(
                $companyId,
                $directoryFilters,
                fn (Builder $q) => EmployeeVisibilityScope::apply($q, request()->user(), $companyId),
            ),
            'department_tree_selected_id' => $directoryFilters->departmentIds === '' && $directoryFilters->departmentId !== '' ? (int) $directoryFilters->departmentId : null,
            'department_tree_selected_ids' => $directoryFilters->departmentIdList(),
            'department_tree_selected_position_id' => $directoryFilters->positionId !== '' ? (int) $directoryFilters->positionId : null,
            'can' => fn () => EmployeePagePermissions::for(request()->user()),
            'saved_views' => fn () => SavedViewsForPage::props(request()->user(), $companyId, SavedViewPage::Employees),
            'smart_search_available' => fn () => $aiSettings->isSmartSearchAvailable(),
        ]);
    }

    public function create()
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        $user = request()->user();

        $requestedTemplateId = (int) request()->query('profile_template_id', 0);
        $selectedTemplate = $requestedTemplateId > 0
            ? EmployeeProfileTemplate::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->find($requestedTemplateId)
            : null;

        $candidateContext = null;
        $candidatePrefills = null;
        $candidateId = (int) request()->query('candidate_id', 0);

        if ($candidateId > 0) {
            abort_unless(
                $user !== null
                    && $user->can('recruitment.candidates.view')
                    && $user->can('employees.create')
                    && $user->can('recruitment.candidates.convert'),
                403,
            );

            /** @var RecruitmentCandidate $candidate */
            $candidate = RecruitmentCandidate::query()
                ->where('company_id', $companyId)
                ->where('id', $candidateId)
                ->with(['line.position', 'requirement.client', 'requirement.project', 'nationality', 'currentOffer'])
                ->firstOrFail();

            if ($candidate->stage !== CandidateStage::Joined || $candidate->employee_id !== null) {
                return redirect()
                    ->route('organization.recruitment.candidates.show', $candidateId)
                    ->with('error', 'Only confirmed Joined candidates without an existing employee link can be converted.');
            }

            $duplicateMatches = FindCandidateDuplicateEmployees::find($candidate, $user, $companyId);
            $acceptedOffer = $candidate->currentOffer;
            if ($acceptedOffer === null || $acceptedOffer->status !== CandidateOfferStatus::Accepted) {
                $acceptedOffer = $candidate->offers()
                    ->where('status', CandidateOfferStatus::Accepted)
                    ->where('is_current', true)
                    ->first();
            }

            $candidateContext = [
                'candidate_id' => (int) $candidate->id,
                'name' => (string) $candidate->name,
                'email' => $candidate->email,
                'phone' => $candidate->phone,
                'nationality_id' => $candidate->nationality_id,
                'nationality_name' => $candidate->nationality?->name,
                'position_id' => $candidate->line?->position_id,
                'position_title' => (string) ($candidate->line?->position?->title ?? $candidate->position_title_snapshot),
                'requirement_number' => (string) ($candidate->requirement?->requirement_number ?? $candidate->requirement_number_snapshot),
                'client_name' => $candidate->requirement?->client?->name,
                'project_title' => $candidate->requirement?->project?->title,
                'actual_joining_date' => $candidate->actual_joining_date?->toDateString(),
                'lock_version' => (int) $candidate->lock_version,
                'proposed_offer' => $acceptedOffer ? [
                    'salary_amount' => $acceptedOffer->salary_amount !== null ? (string) $acceptedOffer->salary_amount : null,
                    'currency' => $acceptedOffer->salary_currency_code,
                ] : null,
                'duplicate_matches' => $duplicateMatches,
                'can_link_existing' => LinkCandidateToEmployee::canLink($user, $candidate),
            ];

            $position = $candidate->line?->position;
            $positionId = $position?->id;
            if ($positionId !== null) {
                $posExists = Position::query()->where('company_id', $companyId)->whereKey($positionId)->exists();
                if (! $posExists) {
                    $positionId = null;
                    $position = null;
                }
            }

            $candidatePrefills = [
                'name' => (string) $candidate->name,
                'personal_email' => $candidate->email,
                'phone' => $candidate->phone,
                'nationality_id' => $candidate->nationality_id,
                'position_id' => $positionId,
                'position' => $position ? ['id' => $position->id, 'title' => $position->title] : null,
                'hire_date' => $candidate->actual_joining_date?->toDateString(),
                'start_date' => $candidate->actual_joining_date?->toDateString(),
                'candidate_id' => (int) $candidate->id,
                'candidate_lock_version' => (int) $candidate->lock_version,
            ];
        }

        $employee = null;
        $employeeId = (int) request()->query('employee_id', 0);
        if ($employeeId > 0) {
            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->where('id', $employeeId)
                ->first();
            // Treat employee_id as untrusted. Resume create only for the
            // authenticated user's own provisional draft — never as a bypass
            // for employees.view or another user's / finalized records.
            abort_unless($employee instanceof Employee, 404);
            abort_unless(
                ProvisionalEmployeeAccess::canResumeCreate(request()->user(), $employee, $companyId),
                403,
            );
            $employee->load([
                'branch:id,name',
                'department:id,name',
                'position:id,title',
                'project:id,title',
                'client:id,name',
                'user:id,name,email,avatar',
                'religionRef:id,name',
                'genderRef:id,name',
                'visaTypeRef:id,name',
                'companyVisaTypeRef:id,name',
                'approvalLocations:id,name',
                'sssaOptions:id,name',
                'nationalityRef:id,name,code',
                'bankAccounts.bank:id,name',
                'primaryBankAccount.bank:id,name',
                'currentContract',
                'employeeProfileTemplate:id,name,configuration_json',
            ]);
        }

        $pageData = EmployeeProfilePageData::forCreate($companyId, request(), $employee, $selectedTemplate);

        if ($candidateContext !== null) {
            $pageData['candidate_context'] = $candidateContext;
            if ($candidatePrefills !== null) {
                $pageData['employee'] = array_merge($pageData['employee'], $candidatePrefills);
            }
        }

        return Inertia::render('organization/employee', $pageData);
    }

    public function ensure(StoreEnsureEmployeeRequest $request, CreateEmployeeFromName $createEmployeeFromName)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $validated = $request->validated();
        $user = $request->user();
        $userId = $user?->id;
        $ensureKey = CreateEmployeeFromName::normalizeEnsureKey(
            isset($validated['idempotency_key']) ? (string) $validated['idempotency_key'] : null,
        );

        if ($userId !== null && $ensureKey !== null) {
            $existing = $this->findOwnedProvisionalByEnsureKey($companyId, (int) $userId, $ensureKey);

            if ($existing instanceof Employee) {
                return response()->json([
                    'employee' => [
                        'id' => $existing->id,
                        'name' => $existing->name,
                        'employee_no' => $existing->employee_no,
                    ],
                ]);
            }
        }

        try {
            $employee = $createEmployeeFromName->handle(
                $validated['name'],
                $companyId,
                isset($validated['employee_profile_template_id'])
                    ? (int) $validated['employee_profile_template_id']
                    : null,
                $userId !== null ? (int) $userId : null,
                $ensureKey,
            );
        } catch (UniqueConstraintViolationException $exception) {
            if ($userId === null || $ensureKey === null) {
                throw $exception;
            }

            $existing = $this->findOwnedProvisionalByEnsureKey($companyId, (int) $userId, $ensureKey);

            if (! $existing instanceof Employee) {
                throw $exception;
            }

            $employee = $existing;
        }

        return response()->json([
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
            ],
        ]);
    }

    public function show(Employee $employee, RecordRecentItem $recordRecentItem)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $employee->company_id === $companyId, 404);
        abort_unless(EmployeeVisibilityScope::canAccess(request()->user(), $employee, $companyId), 404);

        $user = request()->user();
        if ($user !== null) {
            $recordRecentItem->handle($user, $companyId, RecentItemType::Employee, $employee->id);
        }

        return Inertia::render(
            'organization/employee',
            EmployeeProfilePageData::for($employee, $companyId, request()),
        );
    }

    public function store(
        StoreEmployeeRequest $request,
        CreateEmployee $createEmployee,
        ConvertCandidateToEmployee $convertCandidateToEmployee,
    ) {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        $candidateId = $request->input('candidate_id');

        if ($candidateId !== null && $candidateId !== '') {
            /** @var RecruitmentCandidate $candidate */
            $candidate = RecruitmentCandidate::query()
                ->where('company_id', $companyId)
                ->where('id', (int) $candidateId)
                ->firstOrFail();

            $employee = $convertCandidateToEmployee->handle(
                $user,
                $candidate,
                $request->validated(),
                $companyId,
                $request->file('image'),
            );

            return redirect()
                ->route('organization.recruitment.candidates.show', $candidateId)
                ->with('success', "Candidate successfully converted to employee #{$employee->employee_no}.");
        }

        $createEmployee->handle(
            $request->validated(),
            $companyId,
            $user?->id,
            $request->file('image'),
        );

        // Create-only users cannot open the employee directory (employees.view).
        if ($user !== null && $user->can('employees.view')) {
            return redirect()
                ->route('organization.employees')
                ->with('success', 'Employee created successfully.');
        }

        return redirect()
            ->route('organization.employees.create')
            ->with('success', 'Employee created successfully.');
    }

    public function previewHireDateChange(
        PreviewEmployeeHireDateChangeRequest $request,
        Employee $employee,
        EmployeeHireDateChangeGuard $hireDateChangeGuard,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        abort_unless((int) $employee->company_id === $companyId, 404);
        abort_unless(
            ProvisionalEmployeeAccess::canAccessForProfileMutation($user, $employee, $companyId),
            404,
        );

        $proposedHireDate = $hireDateChangeGuard->normalizeHireDate(
            $request->validated('hire_date'),
        );
        $requiresAcknowledgment = $hireDateChangeGuard->requiresAcknowledgment(
            $employee,
            $companyId,
            $proposedHireDate,
        );

        return response()->json([
            'requires_acknowledgment' => $requiresAcknowledgment,
            'previous_hire_date' => $employee->hire_date?->toDateString(),
            'new_hire_date' => $proposedHireDate,
            'annual_balance_years' => $requiresAcknowledgment
                ? $hireDateChangeGuard->annualLeaveBalanceYears($companyId, (int) $employee->id)
                : [],
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        abort_unless((int) $employee->company_id === $companyId, 404);
        abort_unless(
            ProvisionalEmployeeAccess::canAccessForProfileMutation($user, $employee, $companyId),
            404,
        );

        $hireDateChangeGuard = app(EmployeeHireDateChangeGuard::class);
        $previousHireDate = $employee->hire_date?->toDateString();
        $proposedHireDate = $request->has('hire_date')
            ? $hireDateChangeGuard->normalizeHireDate($request->input('hire_date'))
            : $previousHireDate;
        $hireDateAcknowledgmentRequired = $request->has('hire_date')
            && $hireDateChangeGuard->requiresAcknowledgment($employee, $companyId, $proposedHireDate);
        $hireDateAcknowledgmentProvided = $request->boolean(EmployeeHireDateChangeGuard::ACKNOWLEDGMENT_INPUT);

        $wasProvisional = DraftEmployeeNumber::isDraft($employee->employee_no);

        $employee->loadMissing('employeeProfileTemplate');

        $validated = $request->validated();
        $removeImage = $request->boolean('remove_image');
        unset($validated['remove_image'], $validated[EmployeeHireDateChangeGuard::ACKNOWLEDGMENT_INPUT]);

        $data = EmployeeProfileTemplateRequestRules::onlyVisibleAttributes(
            $employee,
            'employees',
            $validated,
        );
        $data['company_id'] = $companyId;

        // Defense in depth: never persist an empty/null employee number even if
        // a template or partial payload somehow bypasses required validation.
        if (array_key_exists('employee_no', $data)) {
            $employeeNo = is_string($data['employee_no']) || is_numeric($data['employee_no'])
                ? trim((string) $data['employee_no'])
                : '';

            if ($employeeNo === '') {
                unset($data['employee_no']);
            } else {
                $data['employee_no'] = $employeeNo;
            }
        }

        // Drop ensure idempotency key once the official number is assigned so
        // the key cannot be reused to mutate another employee's record.
        if (
            $wasProvisional
            && array_key_exists('employee_no', $data)
            && ! DraftEmployeeNumber::isDraft($data['employee_no'])
        ) {
            $data['provisional_ensure_key'] = null;
        }

        if ($request->hasFile('image')) {
            if ($employee->image) {
                Storage::disk('public')->delete($employee->image);
            }

            $data['image'] = UploadedFileStorage::storePublicly(
                $request->file('image'),
                "employees/{$companyId}/images",
                ['disk' => 'public'],
            );
        } elseif ($removeImage) {
            if ($employee->image) {
                Storage::disk('public')->delete($employee->image);
            }

            $data['image'] = null;
        }

        if (($data['religion_id'] ?? null) === '') {
            $data['religion_id'] = null;
        }

        if (($data['gender_id'] ?? null) === '') {
            $data['gender_id'] = null;
        }

        if (($data['visa_type_id'] ?? null) === '') {
            $data['visa_type_id'] = null;
        }

        // Sponsor is managed via the employee's active contract; profile saves
        // must not overwrite the current value mirrored from contract history.
        unset($data['company_visa_type_id']);

        foreach ([
            'user_id',
            'branch_id',
            'department_id',
            'position_id',
            'project_id',
            'client_id',
            'date_of_birth',
            'hire_date',
            'nationality_id',
            'visa_type_id',
            'marital_status',
            'personal_email',
            'work_email',
            'phone',
            'emergency_contact',
            'emergency_phone',
            'address',
            'emirates_id',
            'passport_number',
            'termination_date',
            'termination_reason',
        ] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        unset($data['rank_id']);

        $data['status'] = $data['status'] ?? $employee->status;

        GuardEmployeeStatusTransition::assertCanLeaveActive($employee, (string) $data['status']);

        $approvalLocationIds = $data['approval_location_ids'] ?? null;
        $sssaOptionIds = $data['sssa_option_ids'] ?? null;
        unset($data['approval_location_ids'], $data['sssa_option_ids']);

        if ($hireDateAcknowledgmentRequired) {
            $hireDateChangeGuard->markOmitHireDateFromNextUpdateActivityLog((int) $employee->id);
        }

        $result = app(ApplyEmployeeUpdateWithDepartmentGuard::class)
            ->handle($employee, $companyId, $data);
        $employee = $result['employee'];

        if ($hireDateAcknowledgmentRequired) {
            activity()
                ->performedOn($employee)
                ->causedBy($user)
                ->event('hire_date_changed')
                ->tap(function ($activity) use ($companyId): void {
                    $activity->company_id = $companyId;
                })
                ->withProperties([
                    'previous_hire_date' => $previousHireDate,
                    'new_hire_date' => $employee->hire_date?->toDateString(),
                    'annual_leave_balance_years' => $hireDateChangeGuard->annualLeaveBalanceYears(
                        $companyId,
                        (int) $employee->id,
                    ),
                    'acknowledgment_required' => true,
                    'acknowledgment_provided' => $hireDateAcknowledgmentProvided,
                ])
                ->log('Employee hire date changed; existing annual leave allocations were preserved.');
        }

        SyncEmployeeWorkAssignments::sync($employee, array_filter([
            'approval_location_ids' => $approvalLocationIds,
            'sssa_option_ids' => $sssaOptionIds,
        ], fn ($value) => $value !== null));

        $listQuery = EmployeeDirectoryFilters::listQueryFromRequest($request);

        $canViewProfile = $user !== null
            && $user->can('employees.view')
            && EmployeeVisibilityScope::canAccess($user, $employee, $companyId);

        if ($canViewProfile) {
            return redirect()
                ->route('organization.employees.show', array_merge(
                    ['employee' => $employee],
                    $listQuery,
                ))
                ->with('success', $wasProvisional
                    ? 'Employee created successfully.'
                    : 'Employee updated successfully.');
        }

        // Create-only (or otherwise unable to view) users must not land on a 403
        // profile page after a successful save. Keep confirmation minimal.
        return redirect()
            ->route('organization.employees.create')
            ->with('success', $wasProvisional
                ? 'Employee created successfully.'
                : 'Employee updated successfully.');
    }

    private function findOwnedProvisionalByEnsureKey(
        int $companyId,
        int $userId,
        string $ensureKey,
    ): ?Employee {
        return Employee::query()
            ->where('company_id', $companyId)
            ->where('provisional_created_by', $userId)
            ->where('provisional_ensure_key', $ensureKey)
            ->where('employee_no', 'like', 'DRAFT-%')
            ->first();
    }

    public function destroy(Employee $employee)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $employee->company_id === $companyId, 404);
        abort_unless(EmployeeVisibilityScope::canAccess(request()->user(), $employee, $companyId), 404);

        if (PayrollRecordLinkage::employeeHasRecords((int) $employee->id)) {
            return redirect()
                ->route('organization.employees')
                ->withErrors([
                    'employee' => 'This employee cannot be deleted because they are included in pay runs.',
                ]);
        }

        $employee->delete();

        return redirect()
            ->route('organization.employees')
            ->with('success', 'Employee deleted successfully.');
    }

    public function updateStatus(UpdateEmployeeStatusRequest $request, Employee $employee)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $employee->company_id === $companyId, 404);
        abort_unless(EmployeeVisibilityScope::canAccess(request()->user(), $employee, $companyId), 404);

        $status = $request->validated('status');

        GuardEmployeeStatusTransition::assertCanLeaveActive($employee, $status);

        $employee->update([
            'status' => $status,
        ]);

        return redirect()
            ->back()
            ->with('success', 'Employee status updated successfully.');
    }

    public function assignProfileTemplate(
        AssignEmployeeProfileTemplateRequest $request,
        Employee $employee,
    ) {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $employee->company_id === $companyId, 404);
        abort_unless(EmployeeVisibilityScope::canAccess(request()->user(), $employee, $companyId), 404);

        $hadProfileTemplate = $employee->employee_profile_template_id !== null;

        $employee->update([
            'employee_profile_template_id' => (int) $request->validated('employee_profile_template_id'),
        ]);

        $successMessage = $hadProfileTemplate
            ? 'Profile template changed successfully.'
            : 'Profile template assigned successfully.';

        return redirect()
            ->route('organization.employees.show', array_merge(
                ['employee' => $employee],
                EmployeeDirectoryFilters::listQueryFromRequest($request),
            ))
            ->with('success', $successMessage);
    }
}
