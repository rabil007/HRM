<?php

namespace App\Support\SeaServices;

use App\Imports\SeaServicesImport;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Support\Employees\SeaServiceDuration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SeaServiceImportOrchestrator
{
    public function __construct(
        private readonly SeaServicesImport $import,
    ) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     errors: list<array{row: int, field: string, message: string}>,
     *     warnings: list<array{row: int, field: string, message: string}>,
     *     summary: array{total: int, valid: int, invalid: int, importable: int, skipped: int, warnings: int}
     * }
     */
    public function preview(int $companyId, UploadedFile $file): array
    {
        $evaluation = $this->evaluateRows(
            $companyId,
            $this->import->parse($file),
        );

        return [
            'rows' => collect($evaluation['rows'])
                ->map(function (array $row): array {
                    unset($row['employee'], $row['sea_service_attributes']);

                    return $row;
                })
                ->values()
                ->all(),
            'errors' => $evaluation['errors'],
            'warnings' => $evaluation['warnings'],
            'summary' => $evaluation['summary'],
        ];
    }

    /**
     * @return array{
     *     imported: int,
     *     skipped: int,
     *     errors: list<array{row: int, field: string, message: string}>
     * }
     */
    public function execute(int $companyId, UploadedFile $file): array
    {
        $evaluation = $this->evaluateRows(
            $companyId,
            $this->import->parse($file),
        );

        if ($evaluation['summary']['importable'] === 0) {
            throw ValidationException::withMessages([
                'file' => 'No valid rows were found to import.',
            ]);
        }

        $imported = 0;
        $skipped = 0;
        $nextSortByEmployee = [];

        DB::transaction(function () use ($evaluation, $companyId, &$imported, &$skipped, &$nextSortByEmployee): void {
            foreach ($evaluation['rows'] as $row) {
                if (! empty($row['errors']) || $row['action'] === 'skip') {
                    $skipped++;

                    continue;
                }

                /** @var Employee $employee */
                $employee = $row['employee'];
                $attributes = $row['sea_service_attributes'];

                if (! array_key_exists($employee->id, $nextSortByEmployee)) {
                    $maxSort = EmployeeSeaService::query()
                        ->where('company_id', $companyId)
                        ->where('employee_id', $employee->id)
                        ->max('sort_order');

                    $nextSortByEmployee[$employee->id] = $maxSort === null ? 0 : ((int) $maxSort + 1);
                }

                EmployeeSeaService::query()->create([
                    'company_id' => $companyId,
                    'employee_id' => $employee->id,
                    'sort_order' => $nextSortByEmployee[$employee->id],
                    ...$attributes,
                ]);

                $nextSortByEmployee[$employee->id]++;
                $imported++;
            }
        });

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $evaluation['errors'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $parsedRows
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     errors: list<array{row: int, field: string, message: string}>,
     *     warnings: list<array{row: int, field: string, message: string}>,
     *     summary: array{total: int, valid: int, invalid: int, importable: int, skipped: int, warnings: int}
     * }
     */
    private function evaluateRows(int $companyId, array $parsedRows): array
    {
        $employeesByNo = $this->loadEmployeesByNumber($companyId);
        $vesselTypeByLower = VesselType::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (VesselType $row) => [mb_strtolower(trim((string) $row->name)) => $row->id]);
        $vesselsByTypeAndName = Vessel::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'vessel_type_id'])
            ->groupBy('vessel_type_id')
            ->map(fn ($group) => $group->mapWithKeys(
                fn (Vessel $row) => [Vessel::normalizeName($row->name) => $row->id],
            ));
        $rankByLower = Rank::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Rank $row) => [mb_strtolower(trim((string) $row->name)) => $row->id]);
        $clientByLower = Client::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Client $row) => [mb_strtolower(trim((string) $row->name)) => $row->id]);

        $rows = [];
        $errors = [];
        $warnings = [];

        foreach ($parsedRows as $parsedRow) {
            $rowNumber = (int) $parsedRow['row'];
            $employeeNo = (string) $parsedRow['employee_no'];
            $rowErrors = [];

            $employee = $employeesByNo->get($employeeNo);

            if ($employee === null) {
                $rowErrors[] = [
                    'row' => $rowNumber,
                    'field' => 'employee_no',
                    'message' => "Employee number {$employeeNo} was not found.",
                ];
            } elseif ($employee->status !== 'active') {
                $rowErrors[] = [
                    'row' => $rowNumber,
                    'field' => 'employee_no',
                    'message' => 'Employee is not active.',
                ];
            }

            $hasData = $this->hasSeaServiceData($parsedRow);
            $action = 'skip';
            $seaServiceAttributes = [];

            if ($hasData) {
                $vesselTypeName = trim((string) ($parsedRow['vessel_type'] ?? ''));
                $vesselTypeId = $vesselTypeName !== ''
                    ? ($vesselTypeByLower[mb_strtolower($vesselTypeName)] ?? null)
                    : null;

                if ($vesselTypeId === null) {
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'field' => 'vessel_type',
                        'message' => $vesselTypeName === ''
                            ? 'Vessel type is required.'
                            : "Vessel type '{$vesselTypeName}' was not found or is inactive.",
                    ];
                }

                $vesselName = trim((string) ($parsedRow['vessel'] ?? ''));
                $vesselId = null;

                if ($vesselTypeId !== null) {
                    if ($vesselName === '') {
                        $rowErrors[] = [
                            'row' => $rowNumber,
                            'field' => 'vessel',
                            'message' => 'Vessel is required.',
                        ];
                    } else {
                        $vesselId = $vesselsByTypeAndName->get($vesselTypeId)?->get(Vessel::normalizeName($vesselName));

                        if ($vesselId === null) {
                            $rowErrors[] = [
                                'row' => $rowNumber,
                                'field' => 'vessel',
                                'message' => "Vessel '{$vesselName}' was not found for the selected vessel type.",
                            ];
                        }
                    }
                }

                $rankName = trim((string) ($parsedRow['rank'] ?? ''));
                $rankId = $rankName !== ''
                    ? ($rankByLower[mb_strtolower($rankName)] ?? null)
                    : null;

                if ($rankId === null) {
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'field' => 'rank',
                        'message' => $rankName === ''
                            ? 'Rank is required.'
                            : "Rank '{$rankName}' was not found or is inactive.",
                    ];
                }

                $startDate = $parsedRow['start_date'] ?? null;
                $endDate = $parsedRow['end_date'] ?? null;

                if ($startDate === null || $startDate === '') {
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'field' => 'start_date',
                        'message' => 'Start date is required.',
                    ];
                }

                if ($endDate === null || $endDate === '') {
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'field' => 'end_date',
                        'message' => 'End date is required.',
                    ];
                }

                if (
                    $startDate
                    && $endDate
                    && $endDate < $startDate
                ) {
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'field' => 'end_date',
                        'message' => 'End date must be on or after the start date.',
                    ];
                }

                $clientName = trim((string) ($parsedRow['client'] ?? ''));
                $clientId = null;

                if ($clientName !== '') {
                    $clientId = $clientByLower[mb_strtolower($clientName)] ?? null;

                    if ($clientId === null) {
                        $rowErrors[] = [
                            'row' => $rowNumber,
                            'field' => 'client',
                            'message' => "Client '{$clientName}' was not found or is inactive.",
                        ];
                    }
                }

                if ($rowErrors === []) {
                    $duration = SeaServiceDuration::fromDates(
                        (string) $startDate,
                        (string) $endDate,
                    );

                    $action = 'create';
                    $seaServiceAttributes = [
                        'vessel_type_id' => $vesselTypeId,
                        'vessel_id' => $vesselId,
                        'rank_id' => $rankId,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'total_months' => $duration['months'],
                        'total_days' => $duration['days'],
                        'client_id' => $clientId,
                    ];
                }
            }

            foreach ($rowErrors as $error) {
                $errors[] = $error;
            }

            $rows[] = [
                'row' => $rowNumber,
                'employee_no' => $employeeNo,
                'name' => $parsedRow['name'] ?? $employee?->name,
                'vessel_type' => $parsedRow['vessel_type'],
                'vessel' => $parsedRow['vessel'],
                'rank' => $parsedRow['rank'],
                'start_date' => $parsedRow['start_date'],
                'end_date' => $parsedRow['end_date'],
                'client' => $parsedRow['client'],
                'action' => $action,
                'errors' => $rowErrors,
                'employee' => $employee,
                'sea_service_attributes' => $seaServiceAttributes,
            ];
        }

        $invalid = collect($rows)->filter(fn (array $row): bool => $row['errors'] !== [])->count();
        $skipped = collect($rows)->filter(fn (array $row): bool => $row['action'] === 'skip' && $row['errors'] === [])->count();
        $importable = collect($rows)->filter(fn (array $row): bool => $row['action'] === 'create' && $row['errors'] === [])->count();

        return [
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => $warnings,
            'summary' => [
                'total' => count($rows),
                'valid' => count($rows) - $invalid,
                'invalid' => $invalid,
                'importable' => $importable,
                'skipped' => $skipped,
                'warnings' => count($warnings),
            ],
        ];
    }

    /**
     * @return Collection<string, Employee>
     */
    private function loadEmployeesByNumber(int $companyId): Collection
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->whereNotNull('employee_no')
            ->get(['id', 'employee_no', 'name', 'status'])
            ->keyBy(fn (Employee $employee): string => (string) $employee->employee_no);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function hasSeaServiceData(array $row): bool
    {
        return filled($row['vessel_type'] ?? null)
            || filled($row['vessel'] ?? null)
            || filled($row['rank'] ?? null)
            || filled($row['start_date'] ?? null)
            || filled($row['end_date'] ?? null)
            || filled($row['client'] ?? null);
    }
}
