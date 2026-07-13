<?php

namespace App\Support\EmployeeTrainings;

use App\Imports\TrainingsImport;
use App\Models\Country;
use App\Models\Course;
use App\Models\Employee;
use App\Models\EmployeeTraining;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TrainingImportOrchestrator
{
    public function __construct(
        private readonly TrainingsImport $import,
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
                    unset($row['employee'], $row['training_attributes']);

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
                $attributes = $row['training_attributes'];

                if (! array_key_exists($employee->id, $nextSortByEmployee)) {
                    $maxSort = EmployeeTraining::query()
                        ->where('company_id', $companyId)
                        ->where('employee_id', $employee->id)
                        ->max('sort_order');

                    $nextSortByEmployee[$employee->id] = $maxSort === null ? 0 : ((int) $maxSort + 1);
                }

                EmployeeTraining::query()->create([
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
        $courseByLower = Course::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Course $course) => [mb_strtolower(trim((string) $course->name)) => $course->id]);
        $countryByLower = Country::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Country $country) => [mb_strtolower(trim((string) $country->name)) => $country->id]);

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

            $hasTrainingData = $this->hasTrainingData($parsedRow);
            $action = 'skip';
            $trainingAttributes = [];

            if ($hasTrainingData) {
                $courseName = trim((string) ($parsedRow['course'] ?? ''));
                $courseId = $courseName !== ''
                    ? ($courseByLower[mb_strtolower($courseName)] ?? null)
                    : null;

                if ($courseId === null) {
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'field' => 'course',
                        'message' => $courseName === ''
                            ? 'Course is required.'
                            : "Course '{$courseName}' was not found or is inactive.",
                    ];
                }

                $issueDate = $parsedRow['issue_date'] ?? null;

                if ($issueDate === null || $issueDate === '') {
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'field' => 'issue_date',
                        'message' => 'Issue date is required.',
                    ];
                }

                $instituteCenter = trim((string) ($parsedRow['institute_center'] ?? ''));

                if ($instituteCenter === '') {
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'field' => 'institute_center',
                        'message' => 'Institute center is required.',
                    ];
                }

                $expiryDate = $parsedRow['expiry_date'] ?? null;

                if (
                    $issueDate
                    && $expiryDate
                    && $expiryDate < $issueDate
                ) {
                    $rowErrors[] = [
                        'row' => $rowNumber,
                        'field' => 'expiry_date',
                        'message' => 'Expiry date must be on or after the issue date.',
                    ];
                }

                $countryName = trim((string) ($parsedRow['country'] ?? ''));
                $countryId = null;

                if ($countryName !== '') {
                    $countryId = $countryByLower[mb_strtolower($countryName)] ?? null;

                    if ($countryId === null) {
                        $rowErrors[] = [
                            'row' => $rowNumber,
                            'field' => 'country',
                            'message' => "Country '{$countryName}' was not found or is inactive.",
                        ];
                    }
                }

                if ($rowErrors === []) {
                    $action = 'create';
                    $trainingAttributes = [
                        'course_id' => $courseId,
                        'issue_date' => $issueDate,
                        'expiry_date' => $expiryDate ?: null,
                        'institute_center' => $instituteCenter,
                        'country_id' => $countryId,
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
                'course' => $parsedRow['course'],
                'issue_date' => $parsedRow['issue_date'],
                'expiry_date' => $parsedRow['expiry_date'],
                'institute_center' => $parsedRow['institute_center'],
                'country' => $parsedRow['country'],
                'action' => $action,
                'errors' => $rowErrors,
                'employee' => $employee,
                'training_attributes' => $trainingAttributes,
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
    private function hasTrainingData(array $row): bool
    {
        return filled($row['course'] ?? null)
            || filled($row['issue_date'] ?? null)
            || filled($row['expiry_date'] ?? null)
            || filled($row['institute_center'] ?? null)
            || filled($row['country'] ?? null);
    }
}
