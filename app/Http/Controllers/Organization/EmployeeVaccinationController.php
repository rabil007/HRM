<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\ImportEmployeeVaccinationRequest;
use App\Models\Country;
use App\Models\Employee;
use App\Models\EmployeeVaccination;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateCsvImport;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeVaccinationController extends Controller
{
    /** @var array<string, string> */
    private const CSV_FIELD_MAP = [
        'vaccination_name' => 'vaccination_name',
        'country_id' => 'country',
        'first_dose_date' => 'first_dose',
        'second_dose_date' => 'second_dose',
        'booster_dose_date' => 'booster_dose',
    ];

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = EmployeeProfileTemplateRequestRules::validate(
            $request,
            $employee,
            'employee_vaccinations',
            $this->vaccinationRules(),
        );

        $attributes = $this->vaccinationAttributes($validated, null);

        EmployeeProfileTemplateRequestRules::assertRecordHasMeaningfulContent(
            $attributes,
            ['vaccination_name', 'country_id', 'first_dose_date', 'second_dose_date', 'booster_dose_date'],
            'Enter at least one vaccination field before saving.',
        );

        $maxSort = EmployeeVaccination::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->max('sort_order');

        EmployeeVaccination::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
            ...$attributes,
        ]);

        return back()->with('success', 'Vaccination record added.');
    }

    public function update(Request $request, Employee $employee, EmployeeVaccination $vaccination): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $vaccination->employee_id === $employee->id
            && $vaccination->company_id === $companyId,
            403,
        );

        $validated = EmployeeProfileTemplateRequestRules::validate(
            $request,
            $employee,
            'employee_vaccinations',
            $this->vaccinationRules(),
        );

        $attributes = $this->vaccinationAttributes($validated, $vaccination);

        EmployeeProfileTemplateRequestRules::assertRecordHasMeaningfulContent(
            $attributes,
            ['vaccination_name', 'country_id', 'first_dose_date', 'second_dose_date', 'booster_dose_date'],
            'Enter at least one vaccination field before saving.',
        );

        $vaccination->update($attributes);

        return back()->with('success', 'Vaccination record updated.');
    }

    public function destroy(Request $request, Employee $employee, EmployeeVaccination $vaccination): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $vaccination->employee_id === $employee->id
            && $vaccination->company_id === $companyId,
            403,
        );

        $vaccination->delete();

        return back()->with('success', 'Vaccination record removed.');
    }

    public function importTemplate(Request $request, Employee $employee): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        EmployeeProfileTemplateRequestRules::assertTabForTable($employee, 'employee_vaccinations');

        $csv = EmployeeProfileTemplateCsvImport::buildTemplateCsv(
            $employee,
            'employee_vaccinations',
            self::CSV_FIELD_MAP,
            [
                'vaccination_name' => 'COVID-19',
                'country' => 'United Arab Emirates',
                'first_dose' => '2021-03-01',
                'second_dose' => '2021-06-01',
                'booster_dose' => '2022-01-10',
            ],
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="vaccination-import-template.csv"',
        ]);
    }

    public function import(ImportEmployeeVaccinationRequest $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        try {
            EmployeeProfileTemplateCsvImport::assertImportAvailable(
                $employee,
                'employee_vaccinations',
                self::CSV_FIELD_MAP,
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        $requiredCsvColumns = EmployeeProfileTemplateCsvImport::requiredColumns(
            $employee,
            'employee_vaccinations',
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

        $map = $this->resolveVaccinationCsvHeaderMap($header);

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

        $countryByLower = Country::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Country $c) => [mb_strtolower(trim((string) $c->name)) => $c->id])
            ->all();

        $maxSort = EmployeeVaccination::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->max('sort_order');
        $nextSort = $maxSort === null ? 0 : ((int) $maxSort + 1);

        $imported = 0;
        $skipped = [
            'empty_rows' => 0,
            'missing_vaccination_name' => 0,
        ];

        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $rowValues = EmployeeProfileTemplateCsvImport::extractRowValues(
                $employee,
                'employee_vaccinations',
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
                    $skipped['missing_vaccination_name']++;

                    continue 2;
                }
            }

            $countryId = null;
            if (($rowValues['country_id'] ?? '') !== '') {
                $countryId = $countryByLower[mb_strtolower((string) $rowValues['country_id'])] ?? null;
            }

            $first = ($rowValues['first_dose_date'] ?? '') !== ''
                ? $this->parseVaccinationCsvDate((string) $rowValues['first_dose_date'])
                : null;
            $second = ($rowValues['second_dose_date'] ?? '') !== ''
                ? $this->parseVaccinationCsvDate((string) $rowValues['second_dose_date'])
                : null;
            $booster = ($rowValues['booster_dose_date'] ?? '') !== ''
                ? $this->parseVaccinationCsvDate((string) $rowValues['booster_dose_date'])
                : null;

            $attributes = [
                'vaccination_name' => $rowValues['vaccination_name'] ?? null,
                'country_id' => $countryId,
                'first_dose_date' => $first?->toDateString(),
                'second_dose_date' => $second?->toDateString(),
                'booster_dose_date' => $booster?->toDateString(),
            ];

            if (! EmployeeProfileTemplateCsvImport::hasMeaningfulContent($attributes, array_keys(self::CSV_FIELD_MAP))) {
                $skipped['empty_rows']++;

                continue;
            }

            EmployeeVaccination::query()->create([
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
                'file' => $skipped['missing_vaccination_name'] > 0
                    ? "No rows were imported. {$skipped['missing_vaccination_name']} row(s) are missing a vaccination name."
                    : 'No rows were imported. Ensure each row has a vaccination name.',
            ]);
        }

        return back()->with('success', "Imported {$imported} vaccination row(s).");
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function vaccinationCsvRowHasData(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string|null>  $header
     * @return array<string, int>
     */
    private function resolveVaccinationCsvHeaderMap(array $header): array
    {
        $map = [];

        foreach ($header as $index => $cell) {
            $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $cell)));

            if (in_array($normalized, [
                'vaccination',
                'vaccination_name',
                'vaccine',
                'type',
                'name',
                'immunization',
            ], true)) {
                $map['vaccination_name'] = (int) $index;
            } elseif (in_array($normalized, ['country', 'country_name', 'nation', 'administered in'], true)) {
                $map['country'] = (int) $index;
            } elseif (in_array($normalized, [
                'first_dose',
                'first dose',
                '1st dose',
                'dose 1',
                'date 1',
            ], true)) {
                $map['first_dose'] = (int) $index;
            } elseif (in_array($normalized, [
                'second_dose',
                'second dose',
                '2nd dose',
                'dose 2',
                'date 2',
            ], true)) {
                $map['second_dose'] = (int) $index;
            } elseif (in_array($normalized, [
                'booster_dose',
                'booster dose',
                'booster',
                'third dose',
                'dose 3',
            ], true)) {
                $map['booster_dose'] = (int) $index;
            }
        }

        return $map;
    }

    private function parseVaccinationCsvDate(string $value): ?CarbonImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function vaccinationRules(): array
    {
        return [
            'vaccination_name' => ['required', 'string', 'max:255'],
            'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')->where('is_active', true)],
            'first_dose_date' => ['nullable', 'date'],
            'second_dose_date' => ['nullable', 'date'],
            'booster_dose_date' => ['nullable', 'date'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function vaccinationAttributes(array $validated, ?EmployeeVaccination $existing = null): array
    {
        return [
            'vaccination_name' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'vaccination_name',
                $existing?->vaccination_name,
            ),
            'country_id' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'country_id',
                $existing?->country_id,
                asInteger: true,
            ),
            'first_dose_date' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'first_dose_date',
                $existing?->first_dose_date,
            ),
            'second_dose_date' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'second_dose_date',
                $existing?->second_dose_date,
            ),
            'booster_dose_date' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'booster_dose_date',
                $existing?->booster_dose_date,
            ),
        ];
    }
}
