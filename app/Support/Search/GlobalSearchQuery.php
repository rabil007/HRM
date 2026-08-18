<?php

namespace App\Support\Search;

use App\Models\CrewAssignment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class GlobalSearchQuery
{
    public const MIN_QUERY_LENGTH = 2;

    public const MAX_QUERY_LENGTH = 80;

    public const PER_CATEGORY_LIMIT = 5;

    public function __construct(
        private readonly GlobalSearchResultPresenter $presenter = new GlobalSearchResultPresenter,
    ) {}

    /**
     * @return array{groups: list<array{key: string, label: string, results: list<array{id: string, title: string, subtitle: string, href: string}>}>}
     */
    public function search(?User $user, ?int $companyId, string $query): array
    {
        $query = trim($query);

        if ($user === null || $companyId === null || $companyId < 1) {
            return ['groups' => []];
        }

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return ['groups' => []];
        }

        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query);
        $groups = [];

        if ($user->can('employees.view')) {
            $this->pushGroup($groups, 'employees', 'Employees', $this->presenter->employees(
                $this->employees($companyId, $query, $escaped),
            ));
        }

        if ($user->can('documents.view')) {
            $this->pushGroup($groups, 'documents', 'Documents', $this->presenter->documents(
                $this->documents($companyId, $query, $escaped),
            ));
        }

        if ($user->can('crew_operations.assignments.view')) {
            $this->pushGroup($groups, 'crew', 'Crew', $this->presenter->crew(
                $this->crew($companyId, $query, $escaped),
            ));
        }

        if ($user->can('crew_operations.vessels.view')) {
            $this->pushGroup($groups, 'vessels', 'Vessels', $this->presenter->vessels(
                $this->vessels($companyId, $query, $escaped),
            ));
        }

        if ($user->can('departments.view')) {
            $this->pushGroup($groups, 'departments', 'Departments', $this->presenter->departments(
                $this->departments($companyId, $query, $escaped),
            ));
        }

        if ($user->can('positions.view')) {
            $this->pushGroup($groups, 'positions', 'Positions', $this->presenter->positions(
                $this->positions($companyId, $query, $escaped),
            ));
        }

        if ($user->can('payroll.periods.view') || $user->can('payroll.crew_timesheets.view')) {
            $this->pushGroup($groups, 'payroll', 'Payroll', $this->presenter->payroll(
                $this->payroll($companyId, $query, $escaped),
            ));
        }

        return ['groups' => $groups];
    }

    /**
     * @param  list<array{key: string, label: string, results: list<array{id: string, title: string, subtitle: string, href: string}>}>  $groups
     * @param  list<array{id: string, title: string, subtitle: string, href: string}>  $results
     */
    private function pushGroup(array &$groups, string $key, string $label, array $results): void
    {
        if ($results === []) {
            return;
        }

        $groups[] = [
            'key' => $key,
            'label' => $label,
            'results' => $results,
        ];
    }

    /**
     * @return Collection<int, Employee>
     */
    private function employees(int $companyId, string $query, string $escaped)
    {
        return $this->ranked(
            Employee::query()
                ->where('company_id', $companyId)
                ->with(['department:id,name', 'position:id,title'])
                ->where(function (Builder $inner) use ($escaped): void {
                    $this->addContains($inner, 'employee_no', $escaped);
                    $this->addContains($inner, 'name', $escaped, true);
                }),
            ['employee_no', 'name'],
            $query,
            $escaped,
        )->get(['id', 'name', 'employee_no', 'department_id', 'position_id']);
    }

    /**
     * @return Collection<int, EmployeeDocument>
     */
    private function documents(int $companyId, string $query, string $escaped)
    {
        return $this->ranked(
            EmployeeDocument::query()
                ->forCompany($companyId)
                ->with([
                    'employee:id,name,employee_no,company_id',
                    'documentType:id,title',
                ])
                ->whereHas('employee', function (Builder $employee) use ($companyId): void {
                    $employee->where('company_id', $companyId);
                })
                ->where(function (Builder $inner) use ($companyId, $escaped): void {
                    $this->addContains($inner, 'document_number', $escaped);
                    $this->addContains($inner, 'title', $escaped, true);
                    $inner->orWhereHas('documentType', function (Builder $type) use ($escaped): void {
                        $this->addContains($type, 'title', $escaped);
                    })->orWhereHas('employee', function (Builder $employee) use ($companyId, $escaped): void {
                        $employee->where('company_id', $companyId)
                            ->where(function (Builder $match) use ($escaped): void {
                                $this->addContains($match, 'name', $escaped);
                                $this->addContains($match, 'employee_no', $escaped, true);
                            });
                    });
                }),
            ['document_number', 'title'],
            $query,
            $escaped,
        )->get(['id', 'employee_id', 'document_type_id', 'title', 'document_number', 'expiry_date']);
    }

    /**
     * @return Collection<int, CrewAssignment>
     */
    private function crew(int $companyId, string $query, string $escaped)
    {
        return $this->ranked(
            CrewAssignment::query()
                ->where('company_id', $companyId)
                ->with([
                    'employee:id,name,employee_no,company_id',
                    'vessel:id,name,company_id',
                    'currentPhase:id,phase_code',
                ])
                ->where(function (Builder $inner) use ($companyId, $escaped): void {
                    $this->addContains($inner, 'assignment_no', $escaped);
                    $inner->orWhereHas('employee', function (Builder $employee) use ($companyId, $escaped): void {
                        $employee->where('company_id', $companyId)
                            ->where(function (Builder $match) use ($escaped): void {
                                $this->addContains($match, 'name', $escaped);
                                $this->addContains($match, 'employee_no', $escaped, true);
                            });
                    })->orWhereHas('vessel', function (Builder $vessel) use ($companyId, $escaped): void {
                        $vessel->where('company_id', $companyId);
                        $this->addContains($vessel, 'name', $escaped);
                    });
                }),
            ['assignment_no'],
            $query,
            $escaped,
        )->get(['id', 'assignment_no', 'employee_id', 'vessel_id', 'current_phase_id']);
    }

    /**
     * @return Collection<int, Vessel>
     */
    private function vessels(int $companyId, string $query, string $escaped)
    {
        return $this->ranked(
            Vessel::query()
                ->forCompany($companyId)
                ->where(function (Builder $inner) use ($escaped): void {
                    $this->addContains($inner, 'name', $escaped);
                    $this->addContains($inner, 'imo_no', $escaped, true);
                    $this->addContains($inner, 'official_no', $escaped, true);
                }),
            ['name', 'imo_no', 'official_no'],
            $query,
            $escaped,
        )->get(['id', 'name', 'imo_no', 'official_no']);
    }

    /**
     * @return Collection<int, Department>
     */
    private function departments(int $companyId, string $query, string $escaped)
    {
        return $this->ranked(
            Department::query()
                ->where('company_id', $companyId)
                ->where(function (Builder $inner) use ($escaped): void {
                    $this->addContains($inner, 'name', $escaped);
                    $this->addContains($inner, 'code', $escaped, true);
                }),
            ['code', 'name'],
            $query,
            $escaped,
        )->get(['id', 'name', 'code']);
    }

    /**
     * @return Collection<int, Position>
     */
    private function positions(int $companyId, string $query, string $escaped)
    {
        return $this->ranked(
            Position::query()
                ->where('company_id', $companyId)
                ->with(['department:id,name'])
                ->where(function (Builder $inner) use ($escaped): void {
                    $this->addContains($inner, 'title', $escaped);
                    $this->addContains($inner, 'grade', $escaped, true);
                }),
            ['title', 'grade'],
            $query,
            $escaped,
        )->get(['id', 'title', 'grade', 'department_id']);
    }

    /**
     * @return Collection<int, PayrollPeriod>
     */
    private function payroll(int $companyId, string $query, string $escaped)
    {
        return $this->ranked(
            PayrollPeriod::query()
                ->where('company_id', $companyId)
                ->where(function (Builder $inner) use ($escaped): void {
                    $this->addContains($inner, 'name', $escaped);
                }),
            ['name'],
            $query,
            $escaped,
        )->get(['id', 'name', 'start_date', 'end_date', 'status']);
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<string>  $columns
     * @return Builder<Model>
     */
    private function ranked(Builder $query, array $columns, string $rawQuery, string $escaped): Builder
    {
        $cases = [];
        $bindings = [];

        foreach ($columns as $column) {
            $qualified = $query->qualifyColumn($column);
            $cases[] = "WHEN {$qualified} = ? THEN 0 WHEN {$qualified} LIKE ? ESCAPE '!' THEN 1";
            $bindings[] = $rawQuery;
            $bindings[] = $this->prefix($escaped);
        }

        $query->orderByRaw('CASE '.implode(' ', $cases).' ELSE 2 END', $bindings);

        foreach ($columns as $column) {
            $query->orderBy($column);
        }

        return $query->limit(self::PER_CATEGORY_LIMIT);
    }

    private function addContains(Builder $query, string $column, string $escaped, bool $or = false): void
    {
        $sql = $query->qualifyColumn($column)." LIKE ? ESCAPE '!'";
        $bindings = [$this->contains($escaped)];

        if ($or) {
            $query->orWhereRaw($sql, $bindings);

            return;
        }

        $query->whereRaw($sql, $bindings);
    }

    private function contains(string $escaped): string
    {
        return '%'.$escaped.'%';
    }

    private function prefix(string $escaped): string
    {
        return $escaped.'%';
    }
}
