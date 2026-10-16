<?php

namespace App\Exports;

use App\Models\Employee;
use App\Support\Employees\EmployeeExportFieldRegistry;
use App\Support\Employees\EmployeeExportFieldResolver;
use App\Support\Settings\PlatformDateFormatter;
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
        private readonly EmployeeExportFieldResolver $resolver,
        private readonly array $selectedFields = EmployeeExportFieldRegistry::DEFAULT_FIELD_KEYS,
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
        $values = $this->resolver->resolve(
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

        // Date-only cells stay ISO Y-m-d so export → EmployeesImport round-trips
        // without ambiguous day/month corruption (e.g. 05/06/2026).
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $value) === 1) {
            return PlatformDateFormatter::make()->dateTime($value, '') ?? '';
        }

        return $value;
    }
}
