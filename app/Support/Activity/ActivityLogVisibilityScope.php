<?php

namespace App\Support\Activity;

use App\Models\AttendanceRecord;
use App\Models\ContractSalaryRevision;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\CrewPlanningAssignment;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\CrewTimesheetPreparationSkip;
use App\Models\CrewTimesheetSegment;
use App\Models\DocumentInstance;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEducationQualification;
use App\Models\EmployeeLanguage;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeTraining;
use App\Models\EmployeeVaccination;
use App\Models\EmployeeWorkExperience;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\PayrollRecord;
use App\Models\PayrollWorkAllocation;
use App\Models\SalaryInput;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class ActivityLogVisibilityScope
{
    /**
     * Models whose tables have a direct `employee_id` foreign key.
     *
     * @var array<class-string, string>
     */
    private const DIRECT_EMPLOYEE_MODELS = [
        AttendanceRecord::class => 'attendance_records',
        ContractSalaryRevision::class => 'contract_salary_revisions',
        CrewAssignment::class => 'crew_assignments',
        CrewPlanningAssignment::class => 'crew_planning_assignments',
        CrewTimesheet::class => 'crew_timesheets',
        CrewTimesheetPreparationLine::class => 'crew_timesheet_preparation_lines',
        CrewTimesheetPreparationSkip::class => 'crew_timesheet_preparation_skips',
        DocumentInstance::class => 'document_instances',
        EmployeeBankAccount::class => 'employee_bank_accounts',
        EmployeeContract::class => 'employee_contracts',
        EmployeeDocument::class => 'employee_documents',
        EmployeeEducationQualification::class => 'employee_education_qualifications',
        EmployeeLanguage::class => 'employee_languages',
        EmployeeSeaService::class => 'employee_sea_services',
        EmployeeTraining::class => 'employee_trainings',
        EmployeeVaccination::class => 'employee_vaccinations',
        EmployeeWorkExperience::class => 'employee_work_experiences',
        LeaveBalance::class => 'leave_balances',
        LeaveRequest::class => 'leave_requests',
        PayrollRecord::class => 'payroll_records',
        PayrollWorkAllocation::class => 'payroll_work_allocations',
        SalaryInput::class => 'salary_inputs',
    ];

    /**
     * Models whose tables link via `crew_assignment_id`.
     *
     * @var array<class-string, string>
     */
    private const CREW_ASSIGNMENT_MODELS = [
        CrewAccommodationStay::class => 'crew_accommodation_stays',
        CrewAssignmentPhase::class => 'crew_assignment_phases',
        CrewMovementCorrection::class => 'crew_movement_corrections',
        CrewTimesheetSegment::class => 'crew_timesheet_segments',
    ];

    /**
     * Models whose tables link via `leave_request_id`.
     *
     * @var array<class-string, string>
     */
    private const LEAVE_REQUEST_MODELS = [
        LeaveRequestApproval::class => 'leave_request_approvals',
    ];

    /**
     * Apply the employee visibility scope to an Activity query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, ?User $user, int $companyId): Builder
    {
        if ($user === null || EmployeeVisibilityScope::hasUnrestrictedAccess($user, $companyId)) {
            return $query;
        }

        $allowedDepartmentIds = EmployeeVisibilityScope::allowedDepartmentIds($user, $companyId);
        if ($allowedDepartmentIds === null) {
            return $query;
        }

        $employeeRelatedClasses = array_merge(
            [Employee::class],
            array_keys(self::DIRECT_EMPLOYEE_MODELS),
            array_keys(self::CREW_ASSIGNMENT_MODELS),
            array_keys(self::LEAVE_REQUEST_MODELS),
        );

        return $query->where(function (Builder $builder) use ($companyId, $allowedDepartmentIds, $employeeRelatedClasses): void {
            // Company-level logs: non-employee subject without employee metadata in properties
            $builder->where(function (Builder $companyLevel) use ($employeeRelatedClasses): void {
                $companyLevel->where(function (Builder $nonEmployee) use ($employeeRelatedClasses): void {
                    $nonEmployee->whereNull('subject_type')
                        ->orWhereNotIn('subject_type', $employeeRelatedClasses);
                })->where(function (Builder $withoutEmployeeMetadata): void {
                    self::applyWithoutPropertiesEmployeeId($withoutEmployeeMetadata);
                });
            });

            // If user has no allowed departments, they cannot see any employee-owned logs
            if ($allowedDepartmentIds === []) {
                return;
            }

            // Employee-specific metadata on non-employee subjects (e.g. crew timeline skip on preparation)
            $builder->orWhere(function (Builder $metadataOwned) use ($companyId, $allowedDepartmentIds, $employeeRelatedClasses): void {
                $metadataOwned->where(function (Builder $nonEmployee) use ($employeeRelatedClasses): void {
                    $nonEmployee->whereNull('subject_type')
                        ->orWhereNotIn('subject_type', $employeeRelatedClasses);
                });
                self::applyPropertiesEmployeeIdVisible($metadataOwned, $companyId, $allowedDepartmentIds);
            });

            // Direct Employee logs
            $builder->orWhere(function (Builder $empQuery) use ($companyId, $allowedDepartmentIds): void {
                $empQuery->where('subject_type', Employee::class)
                    ->whereExists(function (QueryBuilder $sub) use ($companyId, $allowedDepartmentIds): void {
                        $sub->selectRaw(1)
                            ->from('employees')
                            ->whereColumn('employees.id', 'activity_log.subject_id')
                            ->where('employees.company_id', $companyId)
                            ->whereIn('employees.department_id', $allowedDepartmentIds);
                    });
            });

            // Direct employee-owned models
            foreach (self::DIRECT_EMPLOYEE_MODELS as $class => $table) {
                $builder->orWhere(function (Builder $modelQuery) use ($class, $table, $companyId, $allowedDepartmentIds): void {
                    $modelQuery->where('subject_type', $class)
                        ->whereExists(function (QueryBuilder $sub) use ($table, $companyId, $allowedDepartmentIds): void {
                            $sub->selectRaw(1)
                                ->from($table)
                                ->join('employees', 'employees.id', '=', "{$table}.employee_id")
                                ->whereColumn("{$table}.id", 'activity_log.subject_id')
                                ->where('employees.company_id', $companyId)
                                ->whereIn('employees.department_id', $allowedDepartmentIds);
                        });
                });
            }

            // Models via crew_assignments
            foreach (self::CREW_ASSIGNMENT_MODELS as $class => $table) {
                $builder->orWhere(function (Builder $crewQuery) use ($class, $table, $companyId, $allowedDepartmentIds): void {
                    $crewQuery->where('subject_type', $class)
                        ->whereExists(function (QueryBuilder $sub) use ($table, $companyId, $allowedDepartmentIds): void {
                            $sub->selectRaw(1)
                                ->from($table)
                                ->join('crew_assignments', 'crew_assignments.id', '=', "{$table}.crew_assignment_id")
                                ->join('employees', 'employees.id', '=', 'crew_assignments.employee_id')
                                ->whereColumn("{$table}.id", 'activity_log.subject_id')
                                ->where('employees.company_id', $companyId)
                                ->whereIn('employees.department_id', $allowedDepartmentIds);
                        });
                });
            }

            // Models via leave_requests
            foreach (self::LEAVE_REQUEST_MODELS as $class => $table) {
                $builder->orWhere(function (Builder $leaveQuery) use ($class, $table, $companyId, $allowedDepartmentIds): void {
                    $leaveQuery->where('subject_type', $class)
                        ->whereExists(function (QueryBuilder $sub) use ($table, $companyId, $allowedDepartmentIds): void {
                            $sub->selectRaw(1)
                                ->from($table)
                                ->join('leave_requests', 'leave_requests.id', '=', "{$table}.leave_request_id")
                                ->join('employees', 'employees.id', '=', 'leave_requests.employee_id')
                                ->whereColumn("{$table}.id", 'activity_log.subject_id')
                                ->where('employees.company_id', $companyId)
                                ->whereIn('employees.department_id', $allowedDepartmentIds);
                        });
                });
            }
        });
    }

    /**
     * @param  Builder<Model>  $query
     */
    private static function applyWithoutPropertiesEmployeeId(Builder $query): void
    {
        $expression = self::propertiesEmployeeIdExpression();

        $query->where(function (Builder $builder) use ($expression): void {
            $builder->whereNull('properties')
                ->orWhereRaw("{$expression} IS NULL")
                ->orWhereRaw("{$expression} <= 0");
        });
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<int>  $allowedDepartmentIds
     */
    private static function applyPropertiesEmployeeIdVisible(
        Builder $query,
        int $companyId,
        array $allowedDepartmentIds,
    ): void {
        $expression = self::propertiesEmployeeIdExpression();

        $query->whereNotNull('properties')
            ->whereRaw("{$expression} > 0")
            ->whereExists(function (QueryBuilder $sub) use ($companyId, $allowedDepartmentIds, $expression): void {
                $sub->selectRaw('1')
                    ->from('employees')
                    ->whereRaw("employees.id = {$expression}")
                    ->where('employees.company_id', $companyId)
                    ->whereIn('employees.department_id', $allowedDepartmentIds);
            });
    }

    private static function propertiesEmployeeIdExpression(): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "CAST(json_extract(activity_log.properties, '$.employee_id') AS INTEGER)";
        }

        return 'CAST(JSON_UNQUOTE(JSON_EXTRACT(activity_log.properties, \'$.employee_id\')) AS UNSIGNED)';
    }
}
