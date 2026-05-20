<?php

namespace App\Support\Employees\Services;

use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEducationQualification;
use App\Models\EmployeeLanguage;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeVaccination;
use App\Models\EmployeeWorkExperience;
use App\Models\VesselType;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeFormOptions;
use App\Support\Employees\ResolveEmployeeNavigation;
use App\Support\Employees\Resources\EmployeeContractResource;
use App\Support\Employees\Resources\EmployeeDetailResource;
use App\Support\Employees\Resources\EmployeeDocumentResource;
use App\Support\OnboardingTemplateTabVisibility;
use Illuminate\Http\Request;

final class EmployeeProfilePageData
{
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
            ->map(fn (EmployeeContract $row) => EmployeeContractResource::toArray($row))
            ->all();

        $documents = EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with(['documentType:id,title', 'uploader:id,name'])
            ->withCount('versions')
            ->latest('id')
            ->get()
            ->map(fn (EmployeeDocument $doc) => EmployeeDocumentResource::toArray($doc))
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

        $profileLookups = EmployeeFormOptions::forProfile($companyId, $employee, $seaServiceRankIds);

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
                        foreach ($stage['employee_fields'] as $field) {
                            $key = is_array($field) ? ($field['key'] ?? '') : (string) $field;
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

        $directoryFilters = EmployeeDirectoryFilters::fromRequest($request);
        $employeeNavigation = (new ResolveEmployeeNavigation)->resolve(
            $employee,
            $companyId,
            $directoryFilters,
        );

        $formOptions = EmployeeFormOptions::for($companyId, $employee);

        return [
            'employee_navigation' => $employeeNavigation,
            'employee' => EmployeeDetailResource::toArray($employee),
            'contracts' => $contracts,
            'documents' => $documents,
            'education_qualifications' => $educationQualifications,
            'work_experiences' => $workExperiences,
            'vaccinations' => $vaccinations,
            'languages' => $languages,
            'bank_accounts' => $bankAccounts,
            'sea_services' => $sea_services,
            'document_types' => $profileLookups['document_types'],
            'can' => [
                'documents_upload' => $request->user()?->can('employees.documents.upload'),
                'documents_delete' => $request->user()?->can('employees.documents.delete'),
                'education_manage' => $request->user()?->can('employees.education.manage'),
                'contracts_manage' => $request->user()?->can('employees.contracts.manage'),
                'work_experience_manage' => $request->user()?->can('employees.work_experience.manage'),
                'vaccination_manage' => $request->user()?->can('employees.vaccination.manage'),
                'languages_manage' => $request->user()?->can('employees.languages.manage'),
                'bank_accounts_manage' => $request->user()?->can('employees.bank_accounts.manage'),
                'sea_service_manage' => $request->user()?->can('employees.sea_service.manage'),
            ],
            'branches' => $formOptions['branches'],
            'departments' => $formOptions['departments'],
            'positions' => $formOptions['positions'],
            'managers' => $formOptions['managers'],
            'countries' => $formOptions['countries'],
            'religions' => $formOptions['religions'],
            'genders' => $formOptions['genders'],
            'banks' => $formOptions['banks'],
            'ranks' => $profileLookups['ranks'],
            'vessel_types' => $vesselTypes,
            'clients' => $clients,
            'employee_tabs' => $employeeTabsPayload,
        ];
    }
}
