<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\ImportEmployeeWorkExperienceRequest;
use App\Models\Employee;
use App\Models\EmployeeWorkExperience;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateCsvImport;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class EmployeeWorkExperienceController extends Controller
{
    /** @var array<string, string> */
    private const CSV_FIELD_MAP = [
        'company_name' => 'company_name',
        'job_title' => 'job_title',
        'date_from' => 'date_from',
        'date_to' => 'date_to',
        'responsibility' => 'responsibility',
    ];

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = EmployeeProfileTemplateRequestRules::validate(
            $request,
            $employee,
            'employee_work_experiences',
            $this->workExperienceRules(),
        );

        $attributes = $this->workExperienceAttributes($validated, null);

        EmployeeProfileTemplateRequestRules::assertRecordHasMeaningfulContent(
            $attributes,
            ['company_name', 'job_title', 'date_from', 'date_to', 'responsibility'],
            'Enter at least one work experience field before saving.',
        );

        $maxSort = EmployeeWorkExperience::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->max('sort_order');

        EmployeeWorkExperience::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
            ...$attributes,
        ]);

        return back()->with('success', 'Work experience added.');
    }

    public function update(Request $request, Employee $employee, EmployeeWorkExperience $workExperience): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $workExperience->employee_id === $employee->id
            && $workExperience->company_id === $companyId,
            403,
        );

        $validated = EmployeeProfileTemplateRequestRules::validate(
            $request,
            $employee,
            'employee_work_experiences',
            $this->workExperienceRules(),
        );

        $attributes = $this->workExperienceAttributes($validated, $workExperience);

        EmployeeProfileTemplateRequestRules::assertRecordHasMeaningfulContent(
            $attributes,
            ['company_name', 'job_title', 'date_from', 'date_to', 'responsibility'],
            'Enter at least one work experience field before saving.',
        );

        $workExperience->update($attributes);

        return back()->with('success', 'Work experience updated.');
    }

    public function destroy(Request $request, Employee $employee, EmployeeWorkExperience $workExperience): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $workExperience->employee_id === $employee->id
            && $workExperience->company_id === $companyId,
            403,
        );

        $workExperience->delete();

        return back()->with('success', 'Work experience removed.');
    }

    public function importTemplate(Request $request, Employee $employee): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        EmployeeProfileTemplateRequestRules::assertTabForTable($employee, 'employee_work_experiences');

        $csv = EmployeeProfileTemplateCsvImport::buildTemplateCsv(
            $employee,
            'employee_work_experiences',
            self::CSV_FIELD_MAP,
            [
                'company_name' => 'Example Corp',
                'job_title' => 'Lifting Engineer',
                'date_from' => '2020-01-01',
                'date_to' => '2023-06-01',
                'responsibility' => 'Offshore operations',
            ],
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="work-experience-import-template.csv"',
        ]);
    }

    public function import(ImportEmployeeWorkExperienceRequest $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        try {
            EmployeeProfileTemplateCsvImport::assertImportAvailable(
                $employee,
                'employee_work_experiences',
                self::CSV_FIELD_MAP,
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        $requiredCsvColumns = EmployeeProfileTemplateCsvImport::requiredColumns(
            $employee,
            'employee_work_experiences',
            self::CSV_FIELD_MAP,
        );

        $uploaded = $request->file('file');
        $path = $uploaded->getRealPath() ?: $uploaded->path();
        $handle = fopen((string) $path, 'r');

        if ($handle === false) {
            return back()->withErrors(['file' => 'Could not read the uploaded file.']);
        }

        $header = fgetcsv($handle);
        if (! is_array($header) || count($header) === 0) {
            fclose($handle);

            return back()->withErrors(['file' => 'The CSV file is empty.']);
        }

        $map = $this->resolveWorkExperienceCsvHeaderMap($header);

        $missingRequiredColumns = array_values(array_filter(
            $requiredCsvColumns,
            fn (string $column): bool => ! array_key_exists($column, $map),
        ));

        if ($missingRequiredColumns !== []) {
            fclose($handle);

            return back()->withErrors([
                'file' => 'The CSV must include '.implode(', ', $missingRequiredColumns).' columns.',
            ]);
        }

        $maxSort = EmployeeWorkExperience::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->max('sort_order');
        $nextSort = $maxSort === null ? 0 : ((int) $maxSort + 1);

        $imported = 0;
        $skipped = [
            'empty_rows' => 0,
            'missing_required_fields' => 0,
            'invalid_date_from' => 0,
        ];

        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $rowValues = EmployeeProfileTemplateCsvImport::extractRowValues(
                $employee,
                'employee_work_experiences',
                self::CSV_FIELD_MAP,
                $row,
                $map,
            );

            if (EmployeeProfileTemplateCsvImport::rowIsEmpty($rowValues)) {
                $skipped['empty_rows']++;

                continue;
            }

            foreach ($requiredCsvColumns as $requiredColumn) {
                $fieldKey = array_search($requiredColumn, self::CSV_FIELD_MAP, true);
                $value = $fieldKey !== false ? ($rowValues[$fieldKey] ?? null) : null;

                if ($value === null || $value === '') {
                    $skipped['missing_required_fields']++;

                    continue 2;
                }
            }

            $parsedFrom = null;
            if (($rowValues['date_from'] ?? '') !== '') {
                $parsedFrom = $this->parseWorkExperienceCsvDate((string) $rowValues['date_from']);

                if ($parsedFrom === null) {
                    $skipped['invalid_date_from']++;

                    continue;
                }
            }

            $dateTo = null;
            if (($rowValues['date_to'] ?? '') !== '' && $parsedFrom !== null) {
                $dateTo = $this->parseWorkExperienceCsvDate((string) $rowValues['date_to']);

                if ($dateTo === null || $dateTo->lt($parsedFrom)) {
                    $dateTo = null;
                }
            }

            $attributes = [
                'company_name' => $rowValues['company_name'] ?? null,
                'job_title' => $rowValues['job_title'] ?? null,
                'date_from' => $parsedFrom?->toDateString(),
                'date_to' => $dateTo?->toDateString(),
                'responsibility' => $rowValues['responsibility'] ?? null,
            ];

            if (! EmployeeProfileTemplateCsvImport::hasMeaningfulContent($attributes, array_keys(self::CSV_FIELD_MAP))) {
                $skipped['empty_rows']++;

                continue;
            }

            EmployeeWorkExperience::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                'sort_order' => $nextSort,
                ...$attributes,
            ]);

            $nextSort++;
            $imported++;

            if ($imported > 500) {
                break;
            }
        }

        fclose($handle);

        if ($imported === 0) {
            return back()->withErrors([
                'file' => $this->formatWorkExperienceImportFailureMessage($skipped),
            ]);
        }

        return back()->with('success', "Imported {$imported} work experience row(s).");
    }

    /**
     * @param  array<string, int>  $skipped
     */
    private function formatWorkExperienceImportFailureMessage(array $skipped): string
    {
        $details = [];

        if ($skipped['missing_required_fields'] > 0) {
            $details[] = "missing required work experience fields ({$skipped['missing_required_fields']} row(s))";
        }

        if ($skipped['invalid_date_from'] > 0) {
            $details[] = "invalid date_from format ({$skipped['invalid_date_from']} row(s)) — use YYYY-MM-DD";
        }

        if ($details === []) {
            return 'No rows were imported. Check required columns and date formats.';
        }

        return 'No rows were imported. '.implode('; ', $details).'.';
    }

    /**
     * @param  array<int, string|null>  $header
     * @return array<string, int>
     */
    private function resolveWorkExperienceCsvHeaderMap(array $header): array
    {
        $map = [];

        foreach ($header as $index => $cell) {
            $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $cell)));

            if (in_array($normalized, ['company name', 'company_name', 'employer', 'organization', 'organisation', 'organization name'], true)) {
                $map['company_name'] = (int) $index;
            } elseif (in_array($normalized, ['job title', 'job_title', 'title', 'role', 'position'], true)) {
                $map['job_title'] = (int) $index;
            } elseif (in_array($normalized, ['date from', 'date_from', 'start date', 'start_date', 'from', 'started'], true)) {
                $map['date_from'] = (int) $index;
            } elseif (in_array($normalized, ['date to', 'date_to', 'end date', 'end_date', 'to', 'finished', 'ended'], true)) {
                $map['date_to'] = (int) $index;
            } elseif (in_array($normalized, ['responsibility', 'responsibilities', 'duties', 'description', 'notes', 'details'], true)) {
                $map['responsibility'] = (int) $index;
            }
        }

        return $map;
    }

    private function parseWorkExperienceCsvDate(string $value): ?CarbonImmutable
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($trimmed)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function workExperienceRules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'job_title' => ['required', 'string', 'max:255'],
            'date_from' => ['required', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'responsibility' => ['nullable', 'string', 'max:65535'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function workExperienceAttributes(array $validated, ?EmployeeWorkExperience $existing = null): array
    {
        return [
            'company_name' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'company_name',
                $existing?->company_name,
            ),
            'job_title' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'job_title',
                $existing?->job_title,
            ),
            'date_from' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'date_from',
                $existing?->date_from,
            ),
            'date_to' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'date_to',
                $existing?->date_to,
            ),
            'responsibility' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'responsibility',
                $existing?->responsibility,
            ),
        ];
    }
}
