<?php

namespace App\Http\Controllers\Organization;

use App\Exports\EmployeesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\StoreEmployeeRequest;
use App\Http\Requests\Organization\Employee\UpdateEmployeeRequest;
use App\Http\Requests\Organization\Employee\UpdateEmployeeStatusRequest;
use App\Imports\EmployeesImport;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Country;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEducationQualification;
use App\Models\EmployeeLanguage;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeVaccination;
use App\Models\EmployeeWorkExperience;
use App\Models\Gender;
use App\Models\OnboardingTemplate;
use App\Models\Position;
use App\Models\Rank;
use App\Models\Religion;
use App\Models\User;
use App\Models\VesselType;
use App\Support\EmployeeDocuments\StoresEmployeeDocument;
use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use App\Support\Employees\ResolveEmployeeNavigation;
use App\Support\OnboardingTemplateImportFields;
use App\Support\OnboardingTemplateTabVisibility;
use App\Support\Pagination\ResolvesPerPage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

class EmployeeController extends Controller
{
    use ResolvesPerPage;

    public function index()
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage(request());
        $directoryFilters = EmployeeDirectoryFilters::fromRequest(request());
        $search = $directoryFilters->search;
        $branchId = $directoryFilters->branchId;
        $departmentId = $directoryFilters->departmentId;
        $positionId = $directoryFilters->positionId;
        $status = $directoryFilters->status;

        $branches = Branch::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        $departments = Department::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        $positions = Position::query()
            ->where('company_id', $companyId)
            ->orderBy('title')
            ->get(['id', 'company_id', 'department_id', 'title']);

        $managers = Employee::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'employee_no']);

        $users = User::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'email']);

        $countries = Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'dial_code']);

        $religions = Religion::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $genders = Gender::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $banks = Bank::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $paginator = (new EmployeeDirectoryQuery($companyId, $directoryFilters))
            ->apply(
                Employee::query()->with([
                    'branch:id,name',
                    'department:id,name',
                    'position:id,title',
                    'manager:id,name,employee_no',
                    'user:id,name,email',
                    'religionRef:id,name',
                    'genderRef:id,name',
                    'nationalityRef:id,name,code',
                    'primaryBankAccount.bank:id,name',
                    'currentContract',
                ]),
            )
            ->paginate($perPage)
            ->withQueryString();

        $employees = $paginator->through(fn (Employee $employee) => [
            'id' => $employee->id,
            'user_id' => $employee->user_id,
            'branch_id' => $employee->branch_id,
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'manager_id' => $employee->manager_id,
            'employee_no' => $employee->employee_no,
            'image' => $employee->image,
            'name' => $employee->name,
            'branch' => $employee->branch_id ? [
                'id' => $employee->branch_id,
                'name' => $employee->branch?->name,
            ] : null,
            'department' => $employee->department_id ? [
                'id' => $employee->department_id,
                'name' => $employee->department?->name,
            ] : null,
            'position' => $employee->position_id ? [
                'id' => $employee->position_id,
                'title' => $employee->position?->title,
            ] : null,
            'work_email' => $employee->work_email,
            'personal_email' => $employee->personal_email,
            'phone' => $employee->phone,
            'phone_home_country' => $employee->phone_home_country,
            'nearest_airport' => $employee->nearest_airport,
            'cv_source' => $employee->cv_source,
            'emergency_contact' => $employee->emergency_contact,
            'emergency_phone' => $employee->emergency_phone,
            'emergency_contact_home_country' => $employee->emergency_contact_home_country,
            'emergency_phone_home_country' => $employee->emergency_phone_home_country,
            'date_of_birth' => $employee->date_of_birth,
            'place_of_birth' => $employee->place_of_birth,
            'gender_id' => $employee->gender_id,
            'gender_ref' => $employee->gender_id ? [
                'id' => $employee->gender_id,
                'name' => $employee->genderRef?->name,
            ] : null,
            'religion_id' => $employee->religion_id,
            'religion_ref' => $employee->religion_id ? [
                'id' => $employee->religion_id,
                'name' => $employee->religionRef?->name,
            ] : null,
            'nationality_id' => $employee->nationality_id,
            'nationality_ref' => $employee->nationality_id ? [
                'id' => $employee->nationality_id,
                'name' => $employee->nationalityRef?->name,
                'code' => $employee->nationalityRef?->code,
            ] : null,
            'marital_status' => $employee->marital_status,
            'spouse_name' => $employee->spouse_name,
            'spouse_birthdate' => $employee->spouse_birthdate,
            'dependent_children_count' => $employee->dependent_children_count,
            'passport_number' => $employee->passport_number,
            'emirates_id' => $employee->emirates_id,
            'bank_id' => $employee->primaryBankAccount?->bank_id,
            'bank' => $employee->primaryBankAccount?->bank_id ? [
                'id' => $employee->primaryBankAccount->bank_id,
                'name' => $employee->primaryBankAccount->bank?->name,
            ] : null,
            'status' => $employee->status,
            'iban' => $employee->primaryBankAccount?->iban,
            'start_date' => $employee->currentContract?->start_date,
            'contract_type' => $employee->currentContract?->contract_type,
            'end_date' => $employee->currentContract?->end_date,
            'labor_contract_id' => $employee->currentContract?->labor_contract_id,
            'created_at' => $employee->created_at,
        ]);

        return Inertia::render('organization/employees', [
            'employees' => $employees->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'filters' => [
                'branch_id' => $branchId,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'status' => $status,
            ],
            'branches' => $branches,
            'departments' => $departments,
            'positions' => $positions,
            'managers' => $managers,
            'users' => $users,
            'countries' => $countries,
            'religions' => $religions,
            'genders' => $genders,
            'banks' => $banks,
            'department_tree' => BuildDepartmentEmployeeTree::for($companyId, $directoryFilters),
            'department_tree_selected_id' => $departmentId !== '' ? (int) $departmentId : null,
        ]);
    }

    public function create()
    {
        $companyId = (int) request()->attributes->get('current_company_id');

        $rankId = (int) request()->query('rank_id', 0);

        $allTemplates = OnboardingTemplate::query()
            ->where('company_id', $companyId)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'is_default', 'tasks']);

        if ($allTemplates->isEmpty()) {
            $message = 'No onboarding template found for this company. Please create one before adding employees.';

            if (request()->user()?->can('onboarding.templates.create')) {
                return redirect()
                    ->route('onboarding.templates.create')
                    ->with('error', $message);
            }

            return redirect()
                ->route('organization.employees')
                ->with('error', $message);
        }

        $requestedId = (int) request()->query('template_id', 0);

        $templateId = $requestedId && $allTemplates->contains('id', $requestedId)
            ? $requestedId
            : ($allTemplates->firstWhere('is_default', true)?->id ?? $allTemplates->first()?->id);

        $template = OnboardingTemplate::query()->find($templateId);

        $branches = Branch::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $departments = Department::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $positions = Position::query()
            ->where('company_id', $companyId)
            ->orderBy('title')
            ->get(['id', 'department_id', 'title']);

        $managers = Employee::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name', 'employee_no']);

        $countries = Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'dial_code']);

        $religions = Religion::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $genders = Gender::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $banks = Bank::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $documentTypes = DocumentType::query()
            ->where('is_active', true)
            ->orderBy('title')
            ->get(['id', 'title']);

        $ranks = Rank::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('organization/employee-create', [
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'tasks' => $template->tasks,
            ],
            'selectedRankId' => $rankId > 0 ? $rankId : null,
            'allTemplates' => $allTemplates->map(fn (OnboardingTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'is_default' => $t->is_default,
            ]),
            'options' => [
                'branches' => $branches,
                'departments' => $departments,
                'positions' => $positions,
                'managers' => $managers,
                'countries' => $countries,
                'religions' => $religions,
                'genders' => $genders,
                'banks' => $banks,
                'ranks' => $ranks,
                'document_types' => $documentTypes,
            ],
        ]);
    }

    public function show(Employee $employee)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $employee->company_id === $companyId, 404);

        $branches = Branch::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        $departments = Department::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        $positions = Position::query()
            ->where('company_id', $companyId)
            ->orderBy('title')
            ->get(['id', 'company_id', 'department_id', 'title']);

        $managers = Employee::query()
            ->where('company_id', $companyId)
            ->where('id', '!=', $employee->id)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'employee_no']);

        $countries = Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'dial_code']);

        $religions = Religion::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $genders = Gender::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $banks = Bank::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $employee->load([
            'branch:id,name',
            'department:id,name',
            'position:id,title',
            'rank:id,name',
            'manager:id,name,employee_no',
            'user:id,name,email',
            'religionRef:id,name',
            'genderRef:id,name',
            'nationalityRef:id,name,code',
            'bankAccounts.bank:id,name',
            'primaryBankAccount.bank:id,name',
            'currentContract',
            'onboardingTemplate:id,name,tasks',
        ]);

        $contracts = EmployeeContract::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeContract $row) => [
                'id' => $row->id,
                'contract_type' => $row->contract_type,
                'start_date' => $row->start_date?->toDateString(),
                'end_date' => $row->end_date?->toDateString(),
                'labor_contract_id' => $row->labor_contract_id,
                'status' => $row->status,
                'basic_salary' => $row->basic_salary,
                'housing_allowance' => $row->housing_allowance,
                'transport_allowance' => $row->transport_allowance,
                'other_allowances' => $row->other_allowances,
                'created_at' => $row->created_at?->toDateTimeString(),
            ])
            ->all();

        $documents = EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with(['documentType:id,title', 'uploader:id,name'])
            ->withCount('versions')
            ->latest('id')
            ->get()
            ->map(fn (EmployeeDocument $doc) => [
                'id' => $doc->id,
                'title' => $doc->title,
                'type' => $doc->type,
                'document_type_id' => $doc->document_type_id,
                'document_type' => $doc->document_type,
                'document_type_label' => $doc->document_type_label,
                'file_path' => $doc->file_path,
                'file_url' => $doc->file_url,
                'original_filename' => $doc->original_filename,
                'mime_type' => $doc->mime_type,
                'size_bytes' => $doc->size_bytes,
                'current_version' => $doc->current_version,
                'can_preview' => $doc->can_preview,
                'issue_date' => $doc->issue_date?->toDateString(),
                'expiry_date' => $doc->expiry_date?->toDateString(),
                'document_number' => $doc->document_number,
                'notes' => $doc->notes,
                'status' => $doc->status,
                'uploaded_by' => $doc->uploader?->name,
                'created_at' => $doc->created_at?->toDateTimeString(),
                'versions_count' => (int) $doc->versions_count,
                'versions' => [],
            ])
            ->all();

        $educationQualifications = EmployeeEducationQualification::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with(['country:id,name,code'])
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeEducationQualification $row) => [
                'id' => $row->id,
                'certificate' => $row->certificate,
                'issue_date' => $row->issue_date?->toDateString(),
                'university' => $row->university,
                'country_id' => $row->country_id,
                'country_name' => $row->country?->name,
            ])
            ->all();

        $workExperiences = EmployeeWorkExperience::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->orderBy('sort_order')
            ->orderByDesc('date_from')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeWorkExperience $row) => [
                'id' => $row->id,
                'company_name' => $row->company_name,
                'job_title' => $row->job_title,
                'date_from' => $row->date_from?->toDateString(),
                'date_to' => $row->date_to?->toDateString(),
                'responsibility' => $row->responsibility,
                'created_at' => $row->created_at?->toDateTimeString(),
            ])
            ->all();

        $vaccinations = EmployeeVaccination::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with(['country:id,name,code'])
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeVaccination $row) => [
                'id' => $row->id,
                'vaccination_name' => $row->vaccination_name,
                'country_id' => $row->country_id,
                'country_name' => $row->country?->name,
                'first_dose_date' => $row->first_dose_date?->toDateString(),
                'second_dose_date' => $row->second_dose_date?->toDateString(),
                'booster_dose_date' => $row->booster_dose_date?->toDateString(),
                'created_at' => $row->created_at?->toDateTimeString(),
            ])
            ->all();

        $languages = EmployeeLanguage::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeLanguage $row) => [
                'id' => $row->id,
                'language_name' => $row->language_name,
                'is_spoken' => $row->is_spoken,
                'is_written' => $row->is_written,
                'is_understood' => $row->is_understood,
                'is_mother_tongue' => $row->is_mother_tongue,
                'created_at' => $row->created_at?->toDateTimeString(),
            ])
            ->all();

        $bankAccounts = EmployeeBankAccount::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with(['bank:id,name'])
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->map(fn (EmployeeBankAccount $row) => [
                'id' => $row->id,
                'bank_id' => $row->bank_id,
                'bank_name' => $row->bank?->name,
                'iban' => $row->iban,
                'account_name' => $row->account_name,
                'is_primary' => $row->is_primary,
                'created_at' => $row->created_at?->toDateTimeString(),
            ])
            ->all();

        $seaServiceModels = EmployeeSeaService::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with([
                'vesselType:id,name',
                'rank:id,name',
                'client:id,name',
            ])
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get();

        $referencedVesselTypeIds = $seaServiceModels->pluck('vessel_type_id')->unique()->filter()->values()->all();

        $vesselTypes = VesselType::query()
            ->where(function ($query) use ($referencedVesselTypeIds): void {
                $query->where('is_active', true);

                if ($referencedVesselTypeIds !== []) {
                    $query->orWhereIn('id', $referencedVesselTypeIds);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (VesselType $vesselType) => [
                'id' => $vesselType->id,
                'name' => $vesselType->name,
            ])
            ->all();

        $referencedClientIds = $seaServiceModels->pluck('client_id')->unique()->filter()->values()->all();

        $clients = Client::query()
            ->where(function ($query) use ($referencedClientIds): void {
                $query->where('is_active', true);

                if ($referencedClientIds !== []) {
                    $query->orWhereIn('id', $referencedClientIds);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => $client->name,
            ])
            ->all();

        $seaServiceRankIds = $seaServiceModels->pluck('rank_id')->unique()->filter()->values()->all();

        $sea_services = $seaServiceModels
            ->map(fn (EmployeeSeaService $row) => [
                'id' => $row->id,
                'vessel_type_id' => $row->vessel_type_id,
                'vessel_type_name' => $row->vesselType?->name,
                'vessel_name' => $row->vessel_name,
                'rank_id' => $row->rank_id,
                'rank_name' => $row->rank?->name,
                'total_months' => $row->total_months,
                'total_days' => $row->total_days,
                'grt' => $row->grt !== null ? (string) $row->grt : null,
                'bhp' => $row->bhp,
                'client_id' => $row->client_id,
                'client_name' => $row->client?->name,
                'is_offshore' => $row->is_offshore,
                'created_at' => $row->created_at?->toDateTimeString(),
            ])
            ->all();

        $documentTypes = DocumentType::query()
            ->where('is_active', true)
            ->orderBy('title')
            ->get(['id', 'title']);

        $ranks = Rank::query()
            ->where(function ($query) use ($employee, $seaServiceRankIds): void {
                $query->where('is_active', true);

                $ensureIds = collect([$employee->rank_id, ...$seaServiceRankIds])
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($ensureIds !== []) {
                    $query->orWhereIn('id', $ensureIds);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        $employeeTabsPayload = [
            'personal' => true,
            'contract' => true,
            'bank' => true,
            'documents' => true,
            'sea_service' => true,
            'vaccination' => true,
        ];

        $enabledProfileFields = null;

        if ($employee->onboarding_template_id) {
            $tasks = is_array($employee->onboardingTemplate?->tasks) ? $employee->onboardingTemplate->tasks : null;

            $employeeTabsPayload = OnboardingTemplateTabVisibility::fromTasks($tasks);

            if (is_array($tasks) && isset($tasks['stages']) && is_array($tasks['stages'])) {
                $enabledProfileFields = [];

                foreach ($tasks['stages'] as $stage) {
                    if (is_array($stage['employee_fields'] ?? null)) {
                        foreach ($stage['employee_fields'] as $f) {
                            $key = is_array($f) ? ($f['key'] ?? '') : (string) $f;
                            if ($key !== '') {
                                $enabledProfileFields[] = $key;
                            }
                        }
                    }
                }

                $enabledProfileFields = array_values(array_unique($enabledProfileFields));
            }
        }

        $employeeTabsPayload['profile_fields'] = $enabledProfileFields;

        $directoryFilters = EmployeeDirectoryFilters::fromRequest(request());
        $employeeNavigation = (new ResolveEmployeeNavigation)->resolve(
            $employee,
            $companyId,
            $directoryFilters,
        );

        return Inertia::render('organization/employee', [
            'employee_navigation' => $employeeNavigation,
            'employee' => [
                'id' => $employee->id,
                'user' => $employee->user_id ? [
                    'id' => $employee->user_id,
                    'name' => $employee->user?->name,
                    'email' => $employee->user?->email,
                ] : null,
                'branch' => $employee->branch_id ? [
                    'id' => $employee->branch_id,
                    'name' => $employee->branch?->name,
                ] : null,
                'department' => $employee->department_id ? [
                    'id' => $employee->department_id,
                    'name' => $employee->department?->name,
                ] : null,
                'position' => $employee->position_id ? [
                    'id' => $employee->position_id,
                    'title' => $employee->position?->title,
                ] : null,
                'rank_id' => $employee->rank_id,
                'rank' => $employee->rank_id ? [
                    'id' => $employee->rank_id,
                    'name' => $employee->rank?->name,
                ] : null,
                'manager' => $employee->manager_id ? [
                    'id' => $employee->manager_id,
                    'employee_no' => $employee->manager?->employee_no,
                    'name' => $employee->manager?->name,
                ] : null,
                'employee_no' => $employee->employee_no,
                'name' => $employee->name,
                'image' => $employee->image,
                'date_of_birth' => $employee->date_of_birth,
                'place_of_birth' => $employee->place_of_birth,
                'gender' => $employee->gender,
                'gender_id' => $employee->gender_id,
                'gender_ref' => $employee->gender_id ? [
                    'id' => $employee->gender_id,
                    'name' => $employee->genderRef?->name,
                ] : null,
                'religion' => $employee->religion,
                'religion_id' => $employee->religion_id,
                'religion_ref' => $employee->religion_id ? [
                    'id' => $employee->religion_id,
                    'name' => $employee->religionRef?->name,
                ] : null,
                'nationality_id' => $employee->nationality_id,
                'nationality_ref' => $employee->nationality_id ? [
                    'id' => $employee->nationality_id,
                    'name' => $employee->nationalityRef?->name,
                    'code' => $employee->nationalityRef?->code,
                ] : null,
                'marital_status' => $employee->marital_status,
                'spouse_name' => $employee->spouse_name,
                'spouse_birthdate' => $employee->spouse_birthdate,
                'dependent_children_count' => $employee->dependent_children_count,
                'personal_email' => $employee->personal_email,
                'work_email' => $employee->work_email,
                'phone' => $employee->phone,
                'nearest_airport' => $employee->nearest_airport,
                'phone_home_country' => $employee->phone_home_country,
                'cv_source' => $employee->cv_source,
                'emergency_contact' => $employee->emergency_contact,
                'emergency_phone' => $employee->emergency_phone,
                'emergency_contact_home_country' => $employee->emergency_contact_home_country,
                'emergency_phone_home_country' => $employee->emergency_phone_home_country,
                'address' => $employee->address,
                'start_date' => $employee->currentContract?->start_date,
                'contract_type' => $employee->currentContract?->contract_type,
                'end_date' => $employee->currentContract?->end_date,
                'labor_contract_id' => $employee->currentContract?->labor_contract_id,
                'basic_salary' => $employee->currentContract?->basic_salary,
                'housing_allowance' => $employee->currentContract?->housing_allowance,
                'transport_allowance' => $employee->currentContract?->transport_allowance,
                'other_allowances' => $employee->currentContract?->other_allowances,
                'bank_id' => $employee->primaryBankAccount?->bank_id,
                'bank' => $employee->primaryBankAccount?->bank_id ? [
                    'id' => $employee->primaryBankAccount->bank_id,
                    'name' => $employee->primaryBankAccount->bank?->name,
                ] : null,
                'iban' => $employee->primaryBankAccount?->iban,
                'account_name' => $employee->primaryBankAccount?->account_name,
                'emirates_id' => $employee->emirates_id,
                'passport_number' => $employee->passport_number,
                'labor_card_number' => $employee->labor_card_number,
                'status' => $employee->status,
                'termination_date' => $employee->termination_date,
                'termination_reason' => $employee->termination_reason,
                'onboarding_template' => $employee->onboarding_template_id ? [
                    'id' => $employee->onboarding_template_id,
                    'name' => $employee->onboardingTemplate?->name,
                ] : null,
                'created_at' => $employee->created_at,
                'updated_at' => $employee->updated_at,
            ],
            'contracts' => $contracts,
            'documents' => $documents,
            'education_qualifications' => $educationQualifications,
            'work_experiences' => $workExperiences,
            'vaccinations' => $vaccinations,
            'languages' => $languages,
            'bank_accounts' => $bankAccounts,
            'sea_services' => $sea_services,
            'document_types' => $documentTypes,
            'can' => [
                'documents_upload' => request()->user()?->can('employees.documents.upload'),
                'documents_delete' => request()->user()?->can('employees.documents.delete'),
                'education_manage' => request()->user()?->can('employees.education.manage'),
                'contracts_manage' => request()->user()?->can('employees.contracts.manage'),
                'work_experience_manage' => request()->user()?->can('employees.work_experience.manage'),
                'vaccination_manage' => request()->user()?->can('employees.vaccination.manage'),
                'languages_manage' => request()->user()?->can('employees.languages.manage'),
                'bank_accounts_manage' => request()->user()?->can('employees.bank_accounts.manage'),
                'sea_service_manage' => request()->user()?->can('employees.sea_service.manage'),
            ],
            'branches' => $branches,
            'departments' => $departments,
            'positions' => $positions,
            'managers' => $managers,
            'countries' => $countries,
            'religions' => $religions,
            'genders' => $genders,
            'banks' => $banks,
            'ranks' => $ranks,
            'vessel_types' => $vesselTypes,
            'clients' => $clients,
            'employee_tabs' => $employeeTabsPayload,
        ]);
    }

    public function store(StoreEmployeeRequest $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $data = $request->validated();
        $data['company_id'] = $companyId;

        $documents = $data['documents'] ?? [];
        unset($data['documents']);

        $primaryBankId = $data['bank_id'] ?? null;
        $primaryIban = $data['iban'] ?? null;
        $primaryAccountName = $data['account_name'] ?? null;
        unset($data['bank_id'], $data['iban'], $data['account_name']);

        if ($primaryAccountName === '') {
            $primaryAccountName = null;
        }

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->storePublicly(
                "employees/{$companyId}/images",
                ['disk' => 'public']
            );
        }

        $contract = [
            'contract_type' => $data['contract_type'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'labor_contract_id' => $data['labor_contract_id'] ?? null,
            'basic_salary' => $data['basic_salary'] ?? null,
            'housing_allowance' => $data['housing_allowance'] ?? null,
            'transport_allowance' => $data['transport_allowance'] ?? null,
            'other_allowances' => $data['other_allowances'] ?? null,
            'status' => 'active',
        ];

        unset(
            $data['contract_type'],
            $data['start_date'],
            $data['end_date'],
            $data['labor_contract_id'],
            $data['basic_salary'],
            $data['housing_allowance'],
            $data['transport_allowance'],
            $data['other_allowances'],
        );

        if (($data['religion_id'] ?? null) === '') {
            $data['religion_id'] = null;
        }

        if (($data['gender_id'] ?? null) === '') {
            $data['gender_id'] = null;
        }

        if (($data['bank_id'] ?? null) === '') {
            $data['bank_id'] = null;
        }

        foreach ([
            'user_id',
            'branch_id',
            'department_id',
            'position_id',
            'rank_id',
            'manager_id',
            'date_of_birth',
            'nationality_id',
            'marital_status',
            'personal_email',
            'work_email',
            'phone',
            'emergency_contact',
            'emergency_phone',
            'address',
            'iban',
            'bank_id',
            'emirates_id',
            'passport_number',
            'labor_card_number',
            'termination_date',
            'termination_reason',
        ] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['status'] = $data['status'] ?? 'active';

        $employee = Employee::create($data);

        EmployeeContract::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            ...$contract,
        ]);

        if ($primaryBankId || $primaryIban || $primaryAccountName) {
            EmployeeBankAccount::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employee->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            EmployeeBankAccount::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                'bank_id' => $primaryBankId ?: null,
                'iban' => $primaryIban ?: null,
                'account_name' => $primaryAccountName ?: null,
                'is_primary' => true,
            ]);
        }

        if (is_array($documents) && count($documents) > 0) {
            $documentStore = app(StoresEmployeeDocument::class);
            $docTypesById = DocumentType::query()
                ->where('is_active', true)
                ->get(['id', 'title'])
                ->keyBy(fn (DocumentType $dt) => (string) $dt->id);

            foreach ($documents as $doc) {
                if (! is_array($doc)) {
                    continue;
                }

                $documentTypeKey = (string) ($doc['type'] ?? '');
                $files = $doc['files'] ?? [];

                if ($documentTypeKey === '' || ! is_array($files) || count($files) === 0) {
                    continue;
                }

                $documentType = $docTypesById->get($documentTypeKey);

                if (! $documentType && ctype_digit($documentTypeKey)) {
                    $found = DocumentType::query()->find((int) $documentTypeKey);
                    if ($found instanceof DocumentType) {
                        $documentType = $found;
                        $docTypesById->put($documentTypeKey, $documentType);
                    }
                }

                if (! $documentType) {
                    $derivedTitle = Str::headline(str_replace(['_', '-'], ' ', $documentTypeKey));
                    $documentType = DocumentType::query()->firstOrCreate(
                        ['title' => $derivedTitle],
                        ['is_active' => true],
                    );
                    $docTypesById->put((string) $documentType->id, $documentType);
                }

                foreach ($files as $file) {
                    if (! $file) {
                        continue;
                    }

                    $expiryDate = $doc['expiry_date'] ?? null;

                    $documentStore->create($employee, $documentType, $file, [
                        'title' => $documentType->title,
                        'issue_date' => $doc['issue_date'] ?? null,
                        'expiry_date' => $expiryDate,
                        'document_number' => $doc['document_number'] ?? null,
                        'notes' => null,
                    ], $companyId, $request->user()?->id);
                }
            }
        }

        return redirect()
            ->route('organization.employees')
            ->with('success', 'Employee created successfully.');
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $employee->company_id === $companyId, 404);

        $data = $request->validated();
        $data['company_id'] = $companyId;

        if ($request->hasFile('image')) {
            if ($employee->image) {
                Storage::disk('public')->delete($employee->image);
            }

            $data['image'] = $request->file('image')->storePublicly(
                "employees/{$companyId}/images",
                ['disk' => 'public']
            );
        }

        if (($data['religion_id'] ?? null) === '') {
            $data['religion_id'] = null;
        }

        if (($data['gender_id'] ?? null) === '') {
            $data['gender_id'] = null;
        }

        foreach ([
            'user_id',
            'branch_id',
            'department_id',
            'position_id',
            'rank_id',
            'manager_id',
            'date_of_birth',
            'nationality_id',
            'marital_status',
            'personal_email',
            'work_email',
            'phone',
            'emergency_contact',
            'emergency_phone',
            'address',
            'emirates_id',
            'passport_number',
            'labor_card_number',
            'termination_date',
            'termination_reason',
        ] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['status'] = $data['status'] ?? 'active';

        $employee->update($data);

        return redirect()
            ->route('organization.employees.show', $employee)
            ->with('success', 'Employee updated successfully.');
    }

    public function destroy(Employee $employee)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $employee->company_id === $companyId, 404);

        $employee->delete();

        return redirect()
            ->route('organization.employees')
            ->with('success', 'Employee deleted successfully.');
    }

    public function updateStatus(UpdateEmployeeStatusRequest $request, Employee $employee)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $employee->company_id === $companyId, 404);

        $employee->update([
            'status' => $request->validated('status'),
        ]);

        return redirect()
            ->route('organization.employees')
            ->with('success', 'Employee status updated successfully.');
    }

    public function export(Request $request)
    {
        $format = strtolower((string) $request->query('format', 'csv'));

        $search = trim((string) $request->query('search', ''));
        $companyId = (int) $request->attributes->get('current_company_id');
        $branchId = trim((string) $request->query('branch_id', ''));
        $departmentId = trim((string) $request->query('department_id', ''));
        $positionId = trim((string) $request->query('position_id', ''));
        $status = trim((string) $request->query('status', ''));

        $query = Employee::query()
            ->with([
                'branch:id,name',
                'department:id,name',
                'position:id,title',
                'manager:id,name,employee_no',
                'user:id,name,email',
                'currentContract',
            ])
            ->where('company_id', $companyId)
            ->latest('id');

        if ($branchId !== '') {
            $query->where('branch_id', $branchId);
        }

        if ($departmentId !== '') {
            $query->where('department_id', $departmentId);
        }

        if ($positionId !== '') {
            $query->where('position_id', $positionId);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('employee_no', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('work_email', 'like', "%{$search}%")
                    ->orWhere('personal_email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $export = new EmployeesExport($query);

        $timestamp = now()->format('Y-m-d_His');
        $baseName = "employees_{$timestamp}";

        if ($format === 'xlsx' || $format === 'excel') {
            return Excel::download($export, "{$baseName}.xlsx", ExcelWriter::XLSX);
        }

        if ($format === 'pdf') {
            $employees = $query->get();
            $pdf = Pdf::loadView('exports.employees', [
                'employees' => $employees,
                'generatedAt' => now(),
            ]);

            return $pdf->download("{$baseName}.pdf");
        }

        return Excel::download($export, "{$baseName}.csv", ExcelWriter::CSV, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function importTemplate(Request $request)
    {
        $headers = $this->importColumnsForRequest($request);

        $sampleRow = array_fill(0, count($headers), '');
        $sampleMap = [
            'employee_no' => 'EMP-001',
            'name' => 'John Doe',
            'work_email' => 'john.doe@example.com',
            'phone' => '+971500000000',
            'date_of_birth' => '1990-01-15',
            'marital_status' => 'single',
            'contract_type' => 'unlimited',
            'start_date' => now()->format('Y-m-d'),
            'status' => 'active',
        ];

        foreach ($headers as $i => $header) {
            if (isset($sampleMap[$header])) {
                $sampleRow[$i] = $sampleMap[$header];
            }
        }

        $callback = function () use ($headers, $sampleRow) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            fputcsv($out, $sampleRow);
            fclose($out);
        };

        return response()->streamDownload($callback, 'employees-import-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function importPage()
    {
        $companyId = (int) request()->attributes->get('current_company_id');

        $templates = OnboardingTemplate::query()
            ->where('company_id', $companyId)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'is_default'])
            ->map(fn (OnboardingTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'is_default' => $t->is_default,
            ]);

        $defaultTemplateId = $templates->firstWhere('is_default', true)['id']
            ?? $templates->first()['id']
            ?? null;

        $importPageRequest = request();
        if ($defaultTemplateId) {
            $importPageRequest = request()->duplicate(
                query: array_merge(request()->query(), ['template_id' => $defaultTemplateId]),
            );
        }

        return Inertia::render('organization/employee-import', [
            'template_url' => route('organization.employees.import.template'),
            'preview_url' => route('organization.employees.import.preview'),
            'import_url' => route('organization.employees.import'),
            'field_options' => $this->importFieldOptions($importPageRequest),
            'max_rows' => EmployeesImport::MAX_ROWS,
            'templates' => $templates,
            'default_template_id' => $defaultTemplateId,
        ]);
    }

    public function importPreview(Request $request)
    {
        $validated = $this->validateImportRequest($request);

        $companyId = (int) $request->attributes->get('current_company_id');
        $importer = new EmployeesImport($companyId, (int) $request->user()->id);

        $file = $request->file('file');
        $headers = $importer->readHeaders($file);
        $mapping = $importer->sanitizeMapping($headers, $validated['mapping'] ?? null, $this->allowedImportFields($request));
        $rows = $this->readImportRows($importer, $file);
        $result = $importer->validateRows($rows, $mapping);

        return response()->json([
            'headers' => $headers,
            'mapping' => $mapping,
            'rows' => $result['rows'],
            'errors' => $result['errors'],
            'summary' => $result['summary'],
            'field_options' => $this->importFieldOptions($request),
            'max_rows' => EmployeesImport::MAX_ROWS,
            'token' => null,
        ]);
    }

    public function import(Request $request)
    {
        $validated = $this->validateImportRequest($request);

        $companyId = (int) $request->attributes->get('current_company_id');
        $importer = new EmployeesImport($companyId, (int) $request->user()->id);

        $file = $request->file('file');
        $headers = $importer->readHeaders($file);
        $mapping = $importer->sanitizeMapping($headers, $validated['mapping'] ?? null, $this->allowedImportFields($request));
        $rows = $this->readImportRows($importer, $file);
        $validation = $importer->validateRows($rows, $mapping);

        $invalidRowNumbers = collect($validation['errors'])->pluck('row')->unique()->all();
        $importable = collect($validation['rows'])
            ->reject(fn ($_, $i) => in_array($i + 2, $invalidRowNumbers, true))
            ->values()
            ->all();

        $result = $importer->execute($importable, (int) $validated['onboarding_template_id']);

        $message = sprintf(
            'Imported %d employee%s. %d row%s skipped.',
            $result['created'],
            $result['created'] === 1 ? '' : 's',
            count($invalidRowNumbers) + count($result['failed']),
            count($invalidRowNumbers) + count($result['failed']) === 1 ? '' : 's',
        );

        if ($request->wantsJson()) {
            return response()->json([
                'created' => $result['created'],
                'skipped' => $invalidRowNumbers,
                'failed' => $result['failed'],
                'errors' => $validation['errors'],
                'message' => $message,
            ]);
        }

        return redirect()
            ->route('organization.employees')
            ->with('success', $message);
    }

    private function validateImportRequest(Request $request): array
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        return $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:csv,txt,xlsx,xls',
                'mimetypes:'.implode(',', EmployeesImport::IMPORT_MIME_TYPES),
                'max:10240',
            ],
            'onboarding_template_id' => [
                'required',
                'integer',
                Rule::exists('onboarding_templates', 'id')->where('company_id', $companyId),
            ],
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function readImportRows(EmployeesImport $importer, $file): array
    {
        $rows = $importer->readRows($file, EmployeesImport::MAX_ROWS + 1);

        if (count($rows) > EmployeesImport::MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => 'The import file may not contain more than '.EmployeesImport::MAX_ROWS.' employee rows.',
            ]);
        }

        return $rows;
    }

    private function allowedImportFields(Request $request): array
    {
        return $this->permittedImportFields($request);
    }

    /**
     * @return list<string>
     */
    private function permittedImportFields(Request $request): array
    {
        $columns = $this->importColumnsForRequest($request);

        return collect($columns)
            ->filter(function (string $field) use ($request) {
                $permission = EmployeesImport::SENSITIVE_FIELD_PERMISSIONS[$field] ?? null;

                return $permission === null || $request->user()?->can($permission);
            })
            ->values()
            ->all();
    }

    private function importFieldOptions(Request $request): array
    {
        $permitted = array_fill_keys($this->permittedImportFields($request), true);

        return collect($this->importColumnsForRequest($request))
            ->map(function (string $field) use ($permitted) {
                $permission = EmployeesImport::SENSITIVE_FIELD_PERMISSIONS[$field] ?? null;

                return [
                    'field' => $field,
                    'label' => Str::headline($field),
                    'required' => in_array($field, EmployeesImport::REQUIRED_FIELDS, true),
                    'sensitive' => $permission !== null,
                    'permission' => $permission,
                    'allowed' => isset($permitted[$field]),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function importColumnsForRequest(Request $request): array
    {
        $template = $this->resolveOnboardingTemplateForImport($request);

        if ($template === null) {
            return EmployeesImport::TEMPLATE_HEADERS;
        }

        return OnboardingTemplateImportFields::columnsForTasks(
            is_array($template->tasks) ? $template->tasks : null,
        );
    }

    private function resolveOnboardingTemplateForImport(Request $request): ?OnboardingTemplate
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $templateId = (int) $request->input('onboarding_template_id', $request->query('template_id', 0));

        if ($templateId <= 0) {
            return null;
        }

        return OnboardingTemplate::query()
            ->where('company_id', $companyId)
            ->find($templateId);
    }
}
