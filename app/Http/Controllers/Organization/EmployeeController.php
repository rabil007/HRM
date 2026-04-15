<?php

namespace App\Http\Controllers\Organization;

use App\Exports\EmployeesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\StoreEmployeeRequest;
use App\Http\Requests\Organization\Employee\UpdateEmployeeRequest;
use App\Http\Requests\Organization\Employee\UpdateEmployeeStatusRequest;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
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

        $employees = Employee::query()
            ->with([
                'branch:id,name',
                'department:id,name',
                'position:id,title',
                'manager:id,first_name,last_name,employee_no',
                'user:id,name,email',
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
                'phone' => $employee->phone,
                'status' => $employee->status,
                'hire_date' => $employee->hire_date,
                'contract_type' => $employee->contract_type,
                'created_at' => $employee->created_at,
            ]);

        return Inertia::render('organization/employees', [
            'employees' => $employees,
            'branches' => $branches,
            'departments' => $departments,
            'positions' => $positions,
            'managers' => $managers,
            'users' => $users,
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

        $employee->load([
            'branch:id,name',
            'department:id,name',
            'position:id,title',
            'manager:id,first_name,last_name,employee_no',
            'user:id,name,email',
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
                'gender' => $employee->gender,
                'nationality' => $employee->nationality,
                'marital_status' => $employee->marital_status,
                'personal_email' => $employee->personal_email,
                'work_email' => $employee->work_email,
                'phone' => $employee->phone,
                'emergency_contact' => $employee->emergency_contact,
                'emergency_phone' => $employee->emergency_phone,
                'address' => $employee->address,
                'hire_date' => $employee->hire_date,
                'probation_end_date' => $employee->probation_end_date,
                'contract_type' => $employee->contract_type,
                'contract_end_date' => $employee->contract_end_date,
                'basic_salary' => $employee->basic_salary,
                'housing_allowance' => $employee->housing_allowance,
                'transport_allowance' => $employee->transport_allowance,
                'other_allowances' => $employee->other_allowances,
                'bank_name' => $employee->bank_name,
                'bank_account_name' => $employee->bank_account_name,
                'iban' => $employee->iban,
                'visa_number' => $employee->visa_number,
                'visa_expiry' => $employee->visa_expiry,
                'visa_type' => $employee->visa_type,
                'emirates_id' => $employee->emirates_id,
                'emirates_id_expiry' => $employee->emirates_id_expiry,
                'passport_number' => $employee->passport_number,
                'passport_expiry' => $employee->passport_expiry,
                'work_permit_number' => $employee->work_permit_number,
                'work_permit_expiry' => $employee->work_permit_expiry,
                'labor_card_number' => $employee->labor_card_number,
                'labor_card_expiry' => $employee->labor_card_expiry,
                'mohre_uid' => $employee->mohre_uid,
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
            'recent_activity' => $recentActivity,
        ]);
    }

    public function store(StoreEmployeeRequest $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $data = $request->validated();
        $data['company_id'] = $companyId;

        foreach ([
            'user_id',
            'branch_id',
            'department_id',
            'position_id',
            'manager_id',
            'date_of_birth',
            'gender',
            'nationality',
            'marital_status',
            'personal_email',
            'work_email',
            'phone',
            'emergency_contact',
            'emergency_phone',
            'address',
            'probation_end_date',
            'contract_end_date',
            'bank_name',
            'bank_account_name',
            'iban',
            'visa_number',
            'visa_expiry',
            'visa_type',
            'emirates_id',
            'emirates_id_expiry',
            'passport_number',
            'passport_expiry',
            'work_permit_number',
            'work_permit_expiry',
            'labor_card_number',
            'labor_card_expiry',
            'mohre_uid',
            'termination_date',
            'termination_reason',
        ] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['status'] = $data['status'] ?? 'active';

        Employee::create($data);

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

        foreach ([
            'user_id',
            'branch_id',
            'department_id',
            'position_id',
            'manager_id',
            'date_of_birth',
            'gender',
            'nationality',
            'marital_status',
            'personal_email',
            'work_email',
            'phone',
            'emergency_contact',
            'emergency_phone',
            'address',
            'probation_end_date',
            'contract_end_date',
            'bank_name',
            'bank_account_name',
            'iban',
            'visa_number',
            'visa_expiry',
            'visa_type',
            'emirates_id',
            'emirates_id_expiry',
            'passport_number',
            'passport_expiry',
            'work_permit_number',
            'work_permit_expiry',
            'labor_card_number',
            'labor_card_expiry',
            'mohre_uid',
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
