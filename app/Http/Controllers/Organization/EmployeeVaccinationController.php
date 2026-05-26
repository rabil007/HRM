<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\ImportEmployeeVaccinationRequest;
use App\Models\Country;
use App\Models\Employee;
use App\Models\EmployeeVaccination;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class EmployeeVaccinationController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = EmployeeProfileTemplateRequestRules::validate($request, $employee, 'employee_vaccinations', [
            'vaccination_name' => ['required', 'string', 'max:255'],
            'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')->where('is_active', true)],
            'first_dose_date' => ['nullable', 'date'],
            'second_dose_date' => ['nullable', 'date'],
            'booster_dose_date' => ['nullable', 'date'],
        ]);

        $maxSort = EmployeeVaccination::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->max('sort_order');

        EmployeeVaccination::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
            'vaccination_name' => $validated['vaccination_name'],
            'country_id' => $validated['country_id'] ?? null,
            'first_dose_date' => $validated['first_dose_date'] ?? null,
            'second_dose_date' => $validated['second_dose_date'] ?? null,
            'booster_dose_date' => $validated['booster_dose_date'] ?? null,
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

        $validated = EmployeeProfileTemplateRequestRules::validate($request, $employee, 'employee_vaccinations', [
            'vaccination_name' => ['required', 'string', 'max:255'],
            'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')->where('is_active', true)],
            'first_dose_date' => ['nullable', 'date'],
            'second_dose_date' => ['nullable', 'date'],
            'booster_dose_date' => ['nullable', 'date'],
        ]);

        $vaccination->update([
            'vaccination_name' => EmployeeProfileTemplateRequestRules::persistedValue(
                $validated,
                'vaccination_name',
                $vaccination->vaccination_name,
            ),
            'country_id' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'country_id')
                ? ($validated['country_id'] ?? null)
                : $vaccination->country_id,
            'first_dose_date' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'first_dose_date')
                ? ($validated['first_dose_date'] ?? null)
                : $vaccination->first_dose_date,
            'second_dose_date' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'second_dose_date')
                ? ($validated['second_dose_date'] ?? null)
                : $vaccination->second_dose_date,
            'booster_dose_date' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'booster_dose_date')
                ? ($validated['booster_dose_date'] ?? null)
                : $vaccination->booster_dose_date,
        ]);

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

        $csv = "vaccination_name,country,first_dose,second_dose,booster_dose\n";
        $csv .= "COVID-19,United Arab Emirates,2021-03-01,2021-06-01,2022-01-10\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="vaccination-import-template.csv"',
        ]);
    }

    public function import(ImportEmployeeVaccinationRequest $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

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

        if (! isset($map['vaccination_name'])) {
            fclose($handle);

            return back()->withErrors([
                'file' => 'The CSV must include a vaccination column (e.g. vaccination_name or Vaccination).',
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

            $name = trim((string) ($row[$map['vaccination_name']] ?? ''));
            if ($name === '') {
                if ($this->vaccinationCsvRowHasData($row)) {
                    $skipped['missing_vaccination_name']++;
                } else {
                    $skipped['empty_rows']++;
                }

                continue;
            }

            $countryId = null;
            if (isset($map['country'])) {
                $countryLabel = trim((string) ($row[$map['country']] ?? ''));
                if ($countryLabel !== '') {
                    $countryId = $countryByLower[mb_strtolower($countryLabel)] ?? null;
                }
            }

            $first = isset($map['first_dose']) ? $this->parseVaccinationCsvDate(trim((string) ($row[$map['first_dose']] ?? ''))) : null;
            $second = isset($map['second_dose']) ? $this->parseVaccinationCsvDate(trim((string) ($row[$map['second_dose']] ?? ''))) : null;
            $booster = isset($map['booster_dose']) ? $this->parseVaccinationCsvDate(trim((string) ($row[$map['booster_dose']] ?? ''))) : null;

            EmployeeVaccination::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                'sort_order' => $nextSort,
                'vaccination_name' => $name,
                'country_id' => $countryId,
                'first_dose_date' => $first?->toDateString(),
                'second_dose_date' => $second?->toDateString(),
                'booster_dose_date' => $booster?->toDateString(),
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
}
