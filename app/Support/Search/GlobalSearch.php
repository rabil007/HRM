<?php

namespace App\Support\Search;

use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\PayrollPeriod;
use App\Models\User;

final class GlobalSearch
{
    /**
     * @return list<array{type: string, id: int, title: string, subtitle: string, href: string}>
     */
    public function search(User $user, int $companyId, string $term, int $limitPerType = 5): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        return collect()
            ->when($user->can('employees.view'), fn ($results) => $results->concat(
                $this->employees($companyId, $term, $limitPerType),
            ))
            ->when($user->can('documents.view'), fn ($results) => $results->concat(
                $this->documents($companyId, $term, $limitPerType),
            ))
            ->when($user->can('crew_operations.assignments.view'), fn ($results) => $results->concat(
                $this->crewAssignments($companyId, $term, $limitPerType),
            ))
            ->when(
                $user->can('payroll.periods.view') || $user->can('payroll.crew_timesheets.view'),
                fn ($results) => $results->concat($this->payrollPeriods($companyId, $term, $limitPerType)),
            )
            ->values()
            ->all();
    }

    /**
     * @return list<array{type: string, id: int, title: string, subtitle: string, href: string}>
     */
    private function employees(int $companyId, string $term, int $limit): array
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->where(function ($query) use ($term): void {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('employee_no', 'like', "%{$term}%")
                    ->orWhere('work_email', 'like', "%{$term}%")
                    ->orWhere('passport_number', 'like', "%{$term}%");
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'employee_no', 'name', 'status'])
            ->map(fn (Employee $employee): array => [
                'type' => 'employee',
                'id' => $employee->id,
                'title' => $employee->name,
                'subtitle' => implode(' · ', array_filter([
                    $employee->employee_no,
                    ucfirst((string) $employee->status),
                ])),
                'href' => "/organization/employees/{$employee->id}",
            ])
            ->all();
    }

    /**
     * @return list<array{type: string, id: int, title: string, subtitle: string, href: string}>
     */
    private function documents(int $companyId, string $term, int $limit): array
    {
        return EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->where(function ($query) use ($term): void {
                $query->where('title', 'like', "%{$term}%")
                    ->orWhere('document_number', 'like', "%{$term}%")
                    ->orWhere('original_filename', 'like', "%{$term}%")
                    ->orWhereHas('employee', fn ($employeeQuery) => $employeeQuery
                        ->where('name', 'like', "%{$term}%")
                        ->orWhere('employee_no', 'like', "%{$term}%"));
            })
            ->with('employee:id,name,employee_no')
            ->latest('updated_at')
            ->limit($limit)
            ->get(['id', 'employee_id', 'title', 'document_number'])
            ->map(fn (EmployeeDocument $document): array => [
                'type' => 'document',
                'id' => $document->id,
                'title' => $document->title ?: ($document->document_number ?: 'Employee document'),
                'subtitle' => implode(' · ', array_filter([
                    $document->employee?->name,
                    $document->document_number,
                ])),
                'href' => "/organization/documents/employees/{$document->employee_id}/files/{$document->id}",
            ])
            ->all();
    }

    /**
     * @return list<array{type: string, id: int, title: string, subtitle: string, href: string}>
     */
    private function crewAssignments(int $companyId, string $term, int $limit): array
    {
        return CrewAssignment::query()
            ->where('company_id', $companyId)
            ->where(function ($query) use ($term): void {
                $query->where('assignment_no', 'like', "%{$term}%")
                    ->orWhereHas('employee', fn ($employeeQuery) => $employeeQuery
                        ->where('name', 'like', "%{$term}%")
                        ->orWhere('employee_no', 'like', "%{$term}%"))
                    ->orWhereHas('vessel', fn ($vesselQuery) => $vesselQuery
                        ->where('name', 'like', "%{$term}%"));
            })
            ->with([
                'employee:id,name,employee_no',
                'vessel:id,name',
            ])
            ->latest('updated_at')
            ->limit($limit)
            ->get(['id', 'assignment_no', 'employee_id', 'vessel_id', 'status'])
            ->map(fn (CrewAssignment $assignment): array => [
                'type' => 'crew_assignment',
                'id' => $assignment->id,
                'title' => $assignment->assignment_no,
                'subtitle' => implode(' · ', array_filter([
                    $assignment->employee?->name,
                    $assignment->vessel?->name,
                ])),
                'href' => "/organization/crew/{$assignment->id}",
            ])
            ->all();
    }

    /**
     * @return list<array{type: string, id: int, title: string, subtitle: string, href: string}>
     */
    private function payrollPeriods(int $companyId, string $term, int $limit): array
    {
        return PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->where('name', 'like', "%{$term}%")
            ->latest('start_date')
            ->limit($limit)
            ->get(['id', 'name', 'status', 'start_date', 'end_date'])
            ->map(fn (PayrollPeriod $period): array => [
                'type' => 'payroll_period',
                'id' => $period->id,
                'title' => $period->name,
                'subtitle' => ucfirst($period->status->value ?? (string) $period->status),
                'href' => "/payroll/{$period->id}",
            ])
            ->all();
    }
}
