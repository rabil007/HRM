<?php

namespace App\Exports;

use App\Models\Employee;
use App\Support\Employees\EmployeeExportFieldRegistry;
use App\Support\Employees\EmployeeExportFieldResolver;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class EmployeesExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<Employee>  $query
     * @param  list<string>  $selectedFields
     */
    public function __construct(
        private readonly Builder $query,
        private readonly array $selectedFields = EmployeeExportFieldRegistry::DEFAULT_FIELD_KEYS,
        private readonly ?EmployeeExportFieldResolver $resolver = null,
    ) {}

    public function query(): Builder
    {
        return $this->query;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return array_map(
            fn (string $key): string => EmployeeExportFieldRegistry::labelFor($key),
            $this->selectedFields,
        );
    }

    /**
     * @param  Employee  $employee
     * @return list<mixed>
     */
    public function map($employee): array
    {
        $values = ($this->resolver ?? new EmployeeExportFieldResolver)->resolve(
            $employee,
            $this->selectedFields,
        );

        return array_map(
            fn (string $key): mixed => $this->formatValue($values[$key] ?? null),
            $this->selectedFields,
        );
    }

    private function formatValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return $value;
    }
}
