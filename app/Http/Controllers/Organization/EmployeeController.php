<?php

namespace App\Http\Controllers\Organization;

use App\Exports\EmployeesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\StoreEmployeeRequest;
use App\Http\Requests\Organization\Employee\UpdateEmployeeRequest;
use App\Http\Requests\Organization\Employee\UpdateEmployeeStatusRequest;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\Country;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\Gender;
use App\Models\OnboardingRecord;
use App\Models\OnboardingTemplate;
use App\Models\Position;
use App\Models\Religion;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;

class EmployeeController extends Controller
{
    public function index()
    {
        $companyId = (int) request()->attributes->get('current_company_id');

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
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'company_id', 'first_name', 'last_name', 'employee_no']);

        $users = User::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'email']);

        $countries = Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

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

        $employees = Employee::query()
            ->with([
                'branch:id,name',
                'department:id,name',
                'position:id,title',
                'manager:id,first_name,last_name,employee_no',
                'user:id,name,email',
                'religionRef:id,name',
                'genderRef:id,name',
                'nationalityRef:id,name,code',
                'primaryBankAccount.bank:id,name',
                'currentContract',
            ])
            ->where('company_id', $companyId)
            ->latest('id')
            ->paginate(20)
            ->through(fn (Employee $employee) => [
                'id' => $employee->id,
                'user_id' => $employee->user_id,
                'branch_id' => $employee->branch_id,
                'department_id' => $employee->department_id,
                'position_id' => $employee->position_id,
                'manager_id' => $employee->manager_id,
                'employee_no' => $employee->employee_no,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'image' => $employee->image,
                'name' => trim("{$employee->first_name} {$employee->last_name}"),
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
                'probation_end_date' => $employee->currentContract?->probation_end_date,
                'end_date' => $employee->currentContract?->end_date,
                'labor_contract_id' => $employee->currentContract?->labor_contract_id,
                'created_at' => $employee->created_at,
            ]);

        return Inertia::render('organization/employees', [
            'employees' => $employees,
            'branches' => $branches,
            'departments' => $departments,
            'positions' => $positions,
            'managers' => $managers,
            'users' => $users,
            'countries' => $countries,
            'religions' => $religions,
            'genders' => $genders,
            'banks' => $banks,
        ]);
    }

    public function create()
    {
        $companyId = (int) request()->attributes->get('current_company_id');

        $template = OnboardingTemplate::query()
            ->where('company_id', $companyId)
            ->where('is_default', true)
            ->first();

        if (! $template) {
            // Fallback to latest if no default
            $template = OnboardingTemplate::query()
                ->where('company_id', $companyId)
                ->latest()
                ->first();
        }

        abort_unless($template, 403, 'Please create an onboarding template first.');

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
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'employee_no']);

        $countries = Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

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
            ->get(['id', 'title', 'slug']);

        return Inertia::render('organization/employee-create', [
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'tasks' => $template->tasks,
            ],
            'options' => [
                'branches' => $branches,
                'departments' => $departments,
                'positions' => $positions,
                'managers' => $managers,
                'countries' => $countries,
                'religions' => $religions,
                'genders' => $genders,
                'banks' => $banks,
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
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'company_id', 'first_name', 'last_name', 'employee_no']);

        $users = User::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'email']);

        $countries = Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

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
            'manager:id,first_name,last_name,employee_no',
            'user:id,name,email',
            'religionRef:id,name',
            'genderRef:id,name',
            'nationalityRef:id,name,code',
            'primaryBankAccount.bank:id,name',
            'currentContract',
        ]);

        $recentActivity = [];
        $request = request();
        if ($request->user()?->can('audit.view')) {
            $recentActivity = Activity::query()
                ->where('company_id', $companyId)
                ->where('subject_type', Employee::class)
                ->where('subject_id', $employee->id)
                ->with(['causer:id,name,email'])
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (Activity $log) => [
                    'id' => $log->id,
                    'event' => $log->event,
                    'description' => $log->description,
                    'causer' => $log->causer ? [
                        'id' => $log->causer->id,
                        'name' => $log->causer->name,
                        'email' => $log->causer->email,
                    ] : null,
                    'old_values' => $log->attribute_changes?->get('old'),
                    'new_values' => $log->attribute_changes?->get('attributes'),
                    'created_at' => $log->created_at,
                ])
                ->all();
        }

        return Inertia::render('organization/employee', [
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
                'manager' => $employee->manager_id ? [
                    'id' => $employee->manager_id,
                    'employee_no' => $employee->manager?->employee_no,
                    'name' => $employee->manager ? trim("{$employee->manager->first_name} {$employee->manager->last_name}") : null,
                ] : null,
                'employee_no' => $employee->employee_no,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
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
                'probation_end_date' => $employee->currentContract?->probation_end_date,
                'contract_type' => $employee->currentContract?->contract_type,
                'end_date' => $employee->currentContract?->end_date,
                'labor_contract_id' => $employee->currentContract?->labor_contract_id,
                'basic_salary' => $employee->currentContract?->basic_salary,
                'housing_allowance' => $employee->currentContract?->housing_allowance,
                'transport_allowance' => $employee->currentContract?->transport_allowance,
                'other_allowances' => $employee->currentContract?->other_allowances,
                'iban' => $employee->primaryBankAccount?->iban,
                'emirates_id' => $employee->emirates_id,
                'passport_number' => $employee->passport_number,
                'labor_card_number' => $employee->labor_card_number,
                'status' => $employee->status,
                'termination_date' => $employee->termination_date,
                'termination_reason' => $employee->termination_reason,
                'created_at' => $employee->created_at,
                'updated_at' => $employee->updated_at,
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
            'recent_activity' => $recentActivity,
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
        unset($data['bank_id'], $data['iban']);

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
            'probation_end_date' => $data['probation_end_date'] ?? null,
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
            $data['probation_end_date'],
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

        if ($primaryBankId || $primaryIban) {
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
                'account_name' => null,
                'is_primary' => true,
            ]);
        }

        if (is_array($documents) && count($documents) > 0) {
            $docTypeMap = DocumentType::query()
                ->where('is_active', true)
                ->get(['id', 'title', 'slug'])
                ->mapWithKeys(fn (DocumentType $dt) => [
                    (string) $dt->slug => $dt->title,
                    (string) $dt->id => $dt->title,
                ]);

            foreach ($documents as $doc) {
                if (! is_array($doc)) {
                    continue;
                }

                $documentTypeKey = (string) ($doc['type'] ?? '');
                $files = $doc['files'] ?? [];

                if ($documentTypeKey === '' || ! is_array($files) || count($files) === 0) {
                    continue;
                }

                foreach ($files as $file) {
                    if (! $file) {
                        continue;
                    }

                    $path = $file->storePublicly(
                        "employee-documents/{$companyId}/{$employee->id}/{$documentTypeKey}",
                        ['disk' => 'public']
                    );

                    $expiryDate = $doc['expiry_date'] ?? null;
                    $status = 'valid';

                    if ($expiryDate) {
                        $expiry = now()->parse($expiryDate);
                        $status = $expiry->isPast() ? 'expired' : ($expiry->lte(now()->addDays(30)) ? 'expiring_soon' : 'valid');
                    }

                    \DB::table('employee_documents')->insert([
                        'company_id' => $companyId,
                        'employee_id' => $employee->id,
                        'type' => 'other',
                        'document_type' => $documentTypeKey,
                        'title' => $docTypeMap->get($documentTypeKey),
                        'file_path' => $path,
                        'issue_date' => $doc['issue_date'] ?? null,
                        'expiry_date' => $expiryDate,
                        'document_number' => $doc['document_number'] ?? null,
                        'notes' => null,
                        'status' => $status,
                        'uploaded_by' => $request->user()?->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        $defaultTemplateId = OnboardingTemplate::query()
            ->where('company_id', $companyId)
            ->where('is_default', true)
            ->value('id');

        OnboardingRecord::query()->firstOrCreate(
            [
                'company_id' => $companyId,
                'employee_id' => $employee->id,
            ],
            [
                'template_id' => $defaultTemplateId,
                'status' => 'pending',
                'stage' => 'draft',
                'task_progress' => [],
                'start_date' => now()->toDateString(),
            ]
        );

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

        $primaryBankId = $data['bank_id'] ?? null;
        $primaryIban = $data['iban'] ?? null;
        unset($data['bank_id'], $data['iban']);

        if ($request->hasFile('image')) {
            if ($employee->image) {
                Storage::disk('public')->delete($employee->image);
            }

            $data['image'] = $request->file('image')->storePublicly(
                "employees/{$companyId}/images",
                ['disk' => 'public']
            );
        }

        $contract = [
            'contract_type' => $data['contract_type'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'probation_end_date' => $data['probation_end_date'] ?? null,
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
            $data['probation_end_date'],
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

        $employee->update($data);

        $employee->loadMissing('primaryBankAccount');
        $existingPrimary = $employee->primaryBankAccount;
        $hasBankData = (bool) ($primaryBankId || $primaryIban);

        if ($hasBankData) {
            EmployeeBankAccount::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employee->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);

            EmployeeBankAccount::query()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'employee_id' => $employee->id,
                    'id' => $existingPrimary?->id,
                ],
                [
                    'bank_id' => $primaryBankId ?: null,
                    'iban' => $primaryIban ?: null,
                    'account_name' => $existingPrimary?->account_name,
                    'is_primary' => true,
                ]
            );
        } elseif ($existingPrimary) {
            $existingPrimary->delete();
        }

        $existingContract = $employee->currentContract;
        if ($existingContract) {
            $existingContract->update($contract);
        } else {
            EmployeeContract::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                ...$contract,
            ]);
        }

        return redirect()
            ->route('organization.employees')
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
                'manager:id,first_name,last_name,employee_no',
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
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
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
}
