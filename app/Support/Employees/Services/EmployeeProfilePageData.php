<?php

namespace App\Support\Employees\Services;

use App\Models\Client;
use App\Models\Course;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEducationQualification;
use App\Models\EmployeeLanguage;
use App\Models\EmployeeProfileTemplate;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeTraining;
use App\Models\EmployeeVaccination;
use App\Models\EmployeeWorkExperience;
use App\Models\User;
use App\Models\VesselType;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateResolver;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeFormOptions;
use App\Support\Employees\ResolveEmployeeNavigation;
use App\Support\Employees\Resources\EmployeeContractResource;
use App\Support\Employees\Resources\EmployeeDetailResource;
use App\Support\Employees\Resources\EmployeeDocumentResource;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Spatie\Permission\Models\Role as SpatieRole;

final class EmployeeProfilePageData
{
    private const DEFER_GROUP = 'employee_profile_records';

    /**
     * @return array<string, mixed>
     */
    public static function for(Employee $employee, int $companyId, Request $request): array
    {
        $employee->load([
            'branch:id,name',
            'department:id,name',
            'position:id,title',
            'rank:id,name',
            'manager:id,name,employee_no',
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

        $profileLookups = EmployeeFormOptions::forProfile($companyId, $employee, []);

        $authUser = $request->user();

        $employeeTabsPayload = self::employeeTabsPayload($employee, $authUser);

        $directoryFilters = EmployeeDirectoryFilters::fromRequest($request);
        $employeeNavigation = (new ResolveEmployeeNavigation)->resolve(
            $employee,
            $companyId,
            $directoryFilters,
        );

        $formOptions = EmployeeFormOptions::for($companyId, $employee);

        $roles = SpatieRole::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $canUpdateEmployee = $authUser?->can('employees.update') ?? false;
        $needsProfileTemplate = $employee->employee_profile_template_id === null;

        return [
            'mode' => 'edit',
            'employee_navigation' => $employeeNavigation,
            'employee' => EmployeeDetailResource::toArray($employee),
            'resolved_template' => EmployeeProfileTemplateResolver::resolve($employee->employeeProfileTemplate),
            'profile_templates' => $needsProfileTemplate
                ? self::activeProfileTemplates($companyId)
                : [],
            'roles' => $roles,
            'can' => [
                'create_user' => $canUpdateEmployee && ($authUser?->can('users.create') ?? false),
                'assign_profile_template' => $canUpdateEmployee && $needsProfileTemplate,
                'documents_view' => $authUser?->can('documents.view'),
                'documents_download' => $authUser?->can('documents.download'),
                'documents_upload' => $authUser?->can('documents.upload'),
                'documents_delete' => $authUser?->can('documents.delete'),
                'education_manage' => $authUser?->can('employees.education.manage'),
                'contracts_manage' => $authUser?->can('employees.contracts.manage'),
                'work_experience_manage' => $authUser?->can('employees.work_experience.manage'),
                'vaccination_manage' => $authUser?->can('employees.vaccination.manage'),
                'languages_manage' => $authUser?->can('employees.languages.manage'),
                'bank_accounts_manage' => $authUser?->can('employees.bank_accounts.manage'),
                'sea_service_manage' => $authUser?->can('employees.sea_service.manage'),
                'training_manage' => $authUser?->can('employees.training.manage'),
            ],
            'branches' => $formOptions['branches'],
            'departments' => $formOptions['departments'],
            'positions' => $formOptions['positions'],
            'managers' => $formOptions['managers'],
            'countries' => $formOptions['countries'],
            'religions' => $formOptions['religions'],
            'genders' => $formOptions['genders'],
            'visa_types' => $formOptions['visa_types'],
            'company_visa_types' => $formOptions['company_visa_types'],
            'approval_locations' => $formOptions['approval_locations'],
            'sssa_options' => $formOptions['sssa_options'],
            'banks' => $formOptions['banks'],
            'ranks' => $profileLookups['ranks'],
            'employee_tabs' => $employeeTabsPayload,
            'contracts' => Inertia::defer(
                fn () => self::contracts($companyId, $employee->id),
                self::DEFER_GROUP,
            ),
            'documents' => Inertia::defer(
                fn () => self::documents($companyId, $employee->id),
                self::DEFER_GROUP,
            ),
            'education_qualifications' => Inertia::defer(
                fn () => self::educationQualifications($companyId, $employee->id),
                self::DEFER_GROUP,
            ),
            'work_experiences' => Inertia::defer(
                fn () => self::workExperiences($companyId, $employee->id),
                self::DEFER_GROUP,
            ),
            'vaccinations' => Inertia::defer(
                fn () => self::vaccinations($companyId, $employee->id),
                self::DEFER_GROUP,
            ),
            'languages' => Inertia::defer(
                fn () => self::languages($companyId, $employee->id),
                self::DEFER_GROUP,
            ),
            'bank_accounts' => Inertia::defer(
                fn () => self::bankAccounts($companyId, $employee->id),
                self::DEFER_GROUP,
            ),
            'sea_services' => Inertia::defer(
                fn () => self::seaServiceBundle($companyId, $employee->id)['sea_services'],
                self::DEFER_GROUP,
            ),
            'document_types' => Inertia::defer(
                fn () => self::documentTypes($companyId),
                self::DEFER_GROUP,
            ),
            'vessel_types' => Inertia::defer(
                fn () => self::seaServiceBundle($companyId, $employee->id)['vessel_types'],
                self::DEFER_GROUP,
            ),
            'clients' => Inertia::defer(
                fn () => self::seaServiceBundle($companyId, $employee->id)['clients'],
                self::DEFER_GROUP,
            ),
            'trainings' => Inertia::defer(
                fn () => self::trainings($companyId, $employee->id),
                self::DEFER_GROUP,
            ),
            'courses' => Inertia::defer(
                fn () => self::courseOptions(),
                self::DEFER_GROUP,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forCreate(int $companyId, Request $request, ?Employee $employee = null, ?EmployeeProfileTemplate $selectedTemplate = null): array
    {
        $authUser = $request->user();
        $resolved = $selectedTemplate !== null
            ? EmployeeProfileTemplateResolver::resolve($selectedTemplate)
            : ($employee?->employeeProfileTemplate !== null
                ? EmployeeProfileTemplateResolver::resolve($employee->employeeProfileTemplate)
                : EmployeeProfileTemplateResolver::defaults());

        $employeeTabsPayload = self::applyDocumentsPermission($resolved['employee_tabs'], $authUser);

        $profileTemplates = self::activeProfileTemplates($companyId);

        $formOptions = EmployeeFormOptions::for($companyId, $employee);
        $profileLookups = $employee !== null
            ? EmployeeFormOptions::forProfile($companyId, $employee, [])
            : ['ranks' => EmployeeFormOptions::forCreate($companyId)['ranks']];

        $employeeId = $employee?->id;
        $employeePayload = $employee !== null
            ? EmployeeDetailResource::toArray($employee)
            : self::placeholderEmployee();

        $base = [
            'mode' => 'create',
            'employee_navigation' => null,
            'employee' => $employeePayload,
            'resolved_template' => $resolved,
            'profile_templates' => $profileTemplates,
            'selected_profile_template_id' => $selectedTemplate?->id ?? $employee?->employee_profile_template_id,
            'roles' => [],
            'can' => self::permissionsPayload($authUser),
            'branches' => $formOptions['branches'],
            'departments' => $formOptions['departments'],
            'positions' => $formOptions['positions'],
            'managers' => $formOptions['managers'],
            'countries' => $formOptions['countries'],
            'religions' => $formOptions['religions'],
            'genders' => $formOptions['genders'],
            'visa_types' => $formOptions['visa_types'],
            'company_visa_types' => $formOptions['company_visa_types'],
            'approval_locations' => $formOptions['approval_locations'],
            'sssa_options' => $formOptions['sssa_options'],
            'banks' => $formOptions['banks'],
            'ranks' => $profileLookups['ranks'],
            'employee_tabs' => $employeeTabsPayload,
            'contracts' => $employeeId ? self::contracts($companyId, $employeeId) : [],
            'documents' => $employeeId ? self::documents($companyId, $employeeId) : [],
            'education_qualifications' => $employeeId ? self::educationQualifications($companyId, $employeeId) : [],
            'work_experiences' => $employeeId ? self::workExperiences($companyId, $employeeId) : [],
            'vaccinations' => $employeeId ? self::vaccinations($companyId, $employeeId) : [],
            'languages' => $employeeId ? self::languages($companyId, $employeeId) : [],
            'bank_accounts' => $employeeId ? self::bankAccounts($companyId, $employeeId) : [],
            'sea_services' => $employeeId ? self::seaServiceBundle($companyId, $employeeId)['sea_services'] : [],
            'document_types' => self::documentTypes($companyId),
            'vessel_types' => $employeeId ? self::seaServiceBundle($companyId, $employeeId)['vessel_types'] : VesselType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn (VesselType $v) => ['id' => $v->id, 'name' => $v->name])->all(),
            'clients' => $employeeId ? self::seaServiceBundle($companyId, $employeeId)['clients'] : Client::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name])->all(),
            'trainings' => $employeeId ? self::trainings($companyId, $employeeId) : [],
            'courses' => self::courseOptions(),
        ];

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private static function placeholderEmployee(): array
    {
        return [
            'id' => null,
            'name' => '',
            'employee_no' => '',
            'status' => 'active',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return list<array{id: int, name: string, description: string|null}>
     */
    private static function activeProfileTemplates(int $companyId): array
    {
        return EmployeeProfileTemplate::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'description'])
            ->map(fn (EmployeeProfileTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
            ])
            ->all();
    }

    private static function permissionsPayload(?User $authUser): array
    {
        return [
            'create_user' => false,
            'assign_profile_template' => false,
            'documents_view' => $authUser?->can('documents.view') ?? false,
            'documents_download' => $authUser?->can('documents.download') ?? false,
            'documents_upload' => $authUser?->can('documents.upload') ?? false,
            'documents_delete' => $authUser?->can('documents.delete') ?? false,
            'education_manage' => $authUser?->can('employees.education.manage') ?? false,
            'contracts_manage' => $authUser?->can('employees.contracts.manage') ?? false,
            'work_experience_manage' => $authUser?->can('employees.work_experience.manage') ?? false,
            'vaccination_manage' => $authUser?->can('employees.vaccination.manage') ?? false,
            'languages_manage' => $authUser?->can('employees.languages.manage') ?? false,
            'bank_accounts_manage' => $authUser?->can('employees.bank_accounts.manage') ?? false,
            'sea_service_manage' => $authUser?->can('employees.sea_service.manage') ?? false,
            'training_manage' => $authUser?->can('employees.training.manage') ?? false,
        ];
    }

    /**
     * @param  array<string, mixed>  $employeeTabsPayload
     * @return array<string, mixed>
     */
    private static function applyDocumentsPermission(array $employeeTabsPayload, ?User $authUser): array
    {
        $employeeTabsPayload['documents'] = ($employeeTabsPayload['documents'] ?? false)
            && ($authUser?->can('documents.view') ?? false);

        return $employeeTabsPayload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function employeeTabsPayload(Employee $employee, ?User $authUser): array
    {
        $resolved = EmployeeProfileTemplateResolver::resolve($employee->employeeProfileTemplate);

        return self::applyDocumentsPermission($resolved['employee_tabs'], $authUser);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function contracts(int $companyId, int $employeeId): array
    {
        return EmployeeContract::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeContract $row) => EmployeeContractResource::toArray($row))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function documents(int $companyId, int $employeeId): array
    {
        return EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->with(['documentType:id,title', 'uploader:id,name'])
            ->withCount('versions')
            ->latest('id')
            ->get()
            ->map(fn (EmployeeDocument $doc) => EmployeeDocumentResource::toArray($doc))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function educationQualifications(int $companyId, int $employeeId): array
    {
        return EmployeeEducationQualification::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
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
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function workExperiences(int $companyId, int $employeeId): array
    {
        return EmployeeWorkExperience::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
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
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function vaccinations(int $companyId, int $employeeId): array
    {
        return EmployeeVaccination::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
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
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function trainings(int $companyId, int $employeeId): array
    {
        return EmployeeTraining::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->with(['course:id,name', 'country:id,name'])
            ->orderBy('sort_order')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeTraining $row) => [
                'id' => $row->id,
                'course_id' => $row->course_id,
                'course_name' => $row->course?->name,
                'issue_date' => $row->issue_date?->toDateString(),
                'expiry_date' => $row->expiry_date?->toDateString(),
                'institute_center' => $row->institute_center,
                'country_id' => $row->country_id,
                'country_name' => $row->country?->name,
                'certificate_url' => $row->certificate_path
                    ? Storage::disk('public')->url($row->certificate_path)
                    : null,
                'created_at' => $row->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private static function courseOptions(): array
    {
        return Course::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Course $course) => [
                'id' => $course->id,
                'name' => $course->name,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function languages(int $companyId, int $employeeId): array
    {
        return EmployeeLanguage::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
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
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function bankAccounts(int $companyId, int $employeeId): array
    {
        return EmployeeBankAccount::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
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
    }

    /**
     * @return array{sea_services: list<array<string, mixed>>, vessel_types: list<array<string, mixed>>, clients: list<array<string, mixed>>}
     */
    private static function seaServiceBundle(int $companyId, int $employeeId): array
    {
        return once(function () use ($companyId, $employeeId): array {
            $seaServiceModels = EmployeeSeaService::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeId)
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

            $seaServices = $seaServiceModels
                ->map(fn (EmployeeSeaService $row) => [
                    'id' => $row->id,
                    'vessel_type_id' => $row->vessel_type_id,
                    'vessel_type_name' => $row->vesselType?->name,
                    'vessel_name' => $row->vessel_name,
                    'rank_id' => $row->rank_id,
                    'rank_name' => $row->rank?->name,
                    'start_date' => $row->start_date?->toDateString(),
                    'end_date' => $row->end_date?->toDateString(),
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

            return [
                'sea_services' => $seaServices,
                'vessel_types' => $vesselTypes,
                'clients' => $clients,
            ];
        });
    }

    /**
     * @return Collection<int, DocumentType>
     */
    private static function documentTypes(int $companyId): Collection
    {
        return EmployeeFormOptions::forCreate($companyId)['document_types'];
    }
}
