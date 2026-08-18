<?php

namespace App\Support\Search;

use App\Models\CrewAssignment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\Vessel;
use Illuminate\Support\Collection;

final class GlobalSearchResultPresenter
{
    /**
     * @param  Collection<int, Employee>  $employees
     * @return list<array{id: string, title: string, subtitle: string, href: string}>
     */
    public function employees(Collection $employees): array
    {
        return $employees->map(function (Employee $employee): array {
            return [
                'id' => 'employee:'.$employee->id,
                'title' => (string) $employee->name,
                'subtitle' => $this->join([
                    $employee->employee_no,
                    $employee->department?->name,
                    $employee->position?->title,
                ]),
                'href' => route('organization.employees.show', $employee),
            ];
        })->all();
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     * @return list<array{id: string, title: string, subtitle: string, href: string}>
     */
    public function documents(Collection $documents): array
    {
        return $documents->map(function (EmployeeDocument $document): array {
            $type = $document->documentType?->title ?: $document->title;

            return [
                'id' => 'document:'.$document->id,
                'title' => (string) ($type ?: 'Document'),
                'subtitle' => $this->join([
                    $document->employee?->employee_no,
                    $document->employee?->name,
                    $this->expiryLabel($document),
                ]),
                'href' => route('organization.documents.employee.files.show', [
                    'employee' => $document->employee_id,
                    'document' => $document->id,
                ]),
            ];
        })->all();
    }

    /**
     * @param  Collection<int, CrewAssignment>  $assignments
     * @return list<array{id: string, title: string, subtitle: string, href: string}>
     */
    public function crew(Collection $assignments): array
    {
        return $assignments->map(function (CrewAssignment $assignment): array {
            $phase = $assignment->currentPhase?->phase_code?->value;

            return [
                'id' => 'crew:'.$assignment->id,
                'title' => (string) $assignment->assignment_no,
                'subtitle' => $this->join([
                    $assignment->employee?->name,
                    $assignment->vessel?->name,
                    $phase !== null ? strtoupper($phase) : null,
                ]),
                'href' => route('organization.crew-assignments.show', $assignment),
            ];
        })->all();
    }

    /**
     * @param  Collection<int, Vessel>  $vessels
     * @return list<array{id: string, title: string, subtitle: string, href: string}>
     */
    public function vessels(Collection $vessels): array
    {
        return $vessels->map(function (Vessel $vessel): array {
            return [
                'id' => 'vessel:'.$vessel->id,
                'title' => (string) $vessel->name,
                'subtitle' => $this->join([
                    filled($vessel->imo_no) ? 'IMO '.$vessel->imo_no : null,
                    filled($vessel->official_no) ? 'Official '.$vessel->official_no : null,
                ]),
                'href' => route('organization.vessels.show', $vessel),
            ];
        })->all();
    }

    /**
     * @param  Collection<int, Department>  $departments
     * @return list<array{id: string, title: string, subtitle: string, href: string}>
     */
    public function departments(Collection $departments): array
    {
        return $departments->map(function (Department $department): array {
            return [
                'id' => 'department:'.$department->id,
                'title' => (string) $department->name,
                'subtitle' => (string) ($department->code ?: ''),
                'href' => route('organization.departments.show', $department),
            ];
        })->all();
    }

    /**
     * @param  Collection<int, Position>  $positions
     * @return list<array{id: string, title: string, subtitle: string, href: string}>
     */
    public function positions(Collection $positions): array
    {
        return $positions->map(function (Position $position): array {
            return [
                'id' => 'position:'.$position->id,
                'title' => (string) $position->title,
                'subtitle' => $this->join([
                    $position->department?->name,
                    $position->grade,
                ]),
                'href' => route('organization.positions.show', $position),
            ];
        })->all();
    }

    /**
     * @param  Collection<int, PayrollPeriod>  $periods
     * @return list<array{id: string, title: string, subtitle: string, href: string}>
     */
    public function payroll(Collection $periods): array
    {
        return $periods->map(function (PayrollPeriod $period): array {
            $range = $this->join([
                $period->start_date?->format('d M Y'),
                $period->end_date?->format('d M Y'),
            ], ' – ');

            return [
                'id' => 'payroll:'.$period->id,
                'title' => (string) $period->name,
                'subtitle' => $this->join([
                    $range !== '' ? $range : null,
                    $period->status?->label(),
                ]),
                'href' => route('payroll.show', $period),
            ];
        })->all();
    }

    /**
     * @param  list<string|null>  $parts
     */
    private function join(array $parts, string $separator = ' · '): string
    {
        return collect($parts)
            ->map(fn (?string $part): string => trim((string) $part))
            ->filter(fn (string $part): bool => $part !== '')
            ->implode($separator);
    }

    private function expiryLabel(EmployeeDocument $document): ?string
    {
        $expiry = $document->expiry_date;

        if ($expiry === null) {
            return null;
        }

        return 'Expires '.$expiry->format('d M Y');
    }
}
