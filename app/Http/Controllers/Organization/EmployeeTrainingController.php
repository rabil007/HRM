<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\ImportEmployeeTrainingRequest;
use App\Models\Country;
use App\Models\Course;
use App\Models\Employee;
use App\Models\EmployeeTraining;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EmployeeTrainingController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = EmployeeProfileTemplateRequestRules::validate(
            $request,
            $employee,
            'employee_trainings',
            $this->trainingRules(),
        );

        $maxSort = EmployeeTraining::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->max('sort_order');

        $training = EmployeeTraining::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
            ...$this->trainingAttributes($validated, null),
        ]);

        if ($request->hasFile('certificate')) {
            $training->update([
                'certificate_path' => $this->storeCertificate($request->file('certificate'), $companyId),
            ]);
        }

        return back()->with('success', 'Training record added.');
    }

    public function update(Request $request, Employee $employee, EmployeeTraining $training): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $training->employee_id === $employee->id
            && $training->company_id === $companyId,
            403,
        );

        $validated = EmployeeProfileTemplateRequestRules::validate(
            $request,
            $employee,
            'employee_trainings',
            $this->trainingRules(),
        );

        $attributes = $this->trainingAttributes($validated, $training);

        if ($request->hasFile('certificate')) {
            $this->deleteCertificate($training->certificate_path);
            $attributes['certificate_path'] = $this->storeCertificate($request->file('certificate'), $companyId);
        }

        $training->update($attributes);

        return back()->with('success', 'Training record updated.');
    }

    public function destroy(Request $request, Employee $employee, EmployeeTraining $training): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $training->employee_id === $employee->id
            && $training->company_id === $companyId,
            403,
        );

        $this->deleteCertificate($training->certificate_path);
        $training->delete();

        return back()->with('success', 'Training record removed.');
    }

    public function importTemplate(Request $request, Employee $employee): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $csv = "course,issue_date,expiry_date,institute_center,country\n";
        $csv .= "STCW Basic Safety,2024-11-26,2029-11-26,BINA SENA MTC,United Arab Emirates\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="training-import-template.csv"',
        ]);
    }

    public function import(ImportEmployeeTrainingRequest $request, Employee $employee): RedirectResponse
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

        $map = $this->resolveTrainingCsvHeaderMap($header);

        if (! isset($map['course'], $map['issue_date'], $map['institute_center'])) {
            fclose($handle);

            return back()->withErrors([
                'file' => 'The CSV must include course, issue_date, and institute_center columns.',
            ]);
        }

        $courseByLower = Course::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Course $course) => [mb_strtolower(trim((string) $course->name)) => $course->id])
            ->all();

        $countryByLower = Country::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Country $country) => [mb_strtolower(trim((string) $country->name)) => $country->id])
            ->all();

        $maxSort = EmployeeTraining::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->max('sort_order');
        $nextSort = $maxSort === null ? 0 : ((int) $maxSort + 1);

        $imported = 0;
        $skipped = [
            'empty_rows' => 0,
            'missing_required_fields' => 0,
            'unknown_course' => 0,
            'invalid_issue_date' => 0,
        ];

        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $courseLabel = trim((string) ($row[$map['course']] ?? ''));
            $issueDateRaw = trim((string) ($row[$map['issue_date']] ?? ''));
            $instituteCenter = trim((string) ($row[$map['institute_center']] ?? ''));

            if ($courseLabel === '' && $issueDateRaw === '' && $instituteCenter === '') {
                $skipped['empty_rows']++;

                continue;
            }

            if ($courseLabel === '' || $issueDateRaw === '' || $instituteCenter === '') {
                $skipped['missing_required_fields']++;

                continue;
            }

            $courseId = $courseByLower[mb_strtolower($courseLabel)] ?? null;
            if ($courseId === null) {
                $skipped['unknown_course']++;

                continue;
            }

            $issueDate = $this->parseTrainingCsvDate($issueDateRaw);
            if ($issueDate === null) {
                $skipped['invalid_issue_date']++;

                continue;
            }

            $expiryDate = null;
            if (isset($map['expiry_date'])) {
                $expiryRaw = trim((string) ($row[$map['expiry_date']] ?? ''));
                if ($expiryRaw !== '') {
                    $parsedExpiry = $this->parseTrainingCsvDate($expiryRaw);
                    if ($parsedExpiry !== null && $parsedExpiry->gte($issueDate)) {
                        $expiryDate = $parsedExpiry->toDateString();
                    }
                }
            }

            $countryId = null;
            if (isset($map['country'])) {
                $countryLabel = trim((string) ($row[$map['country']] ?? ''));
                if ($countryLabel !== '') {
                    $countryId = $countryByLower[mb_strtolower($countryLabel)] ?? null;
                }
            }

            EmployeeTraining::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                'sort_order' => $nextSort,
                'course_id' => $courseId,
                'issue_date' => $issueDate->toDateString(),
                'expiry_date' => $expiryDate,
                'institute_center' => $instituteCenter,
                'country_id' => $countryId,
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
                'file' => $this->formatTrainingImportFailureMessage($skipped),
            ]);
        }

        return back()->with('success', "Imported {$imported} training row(s).");
    }

    /**
     * @return array<string, mixed>
     */
    private function trainingRules(): array
    {
        return [
            'course_id' => [
                'required',
                'integer',
                Rule::exists('courses', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'issue_date' => ['required', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'institute_center' => ['required', 'string', 'max:255'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function trainingAttributes(array $validated, ?EmployeeTraining $existing = null): array
    {
        return [
            'course_id' => (int) EmployeeProfileTemplateRequestRules::persistedValue(
                $validated,
                'course_id',
                $existing?->course_id ?? 0,
            ),
            'issue_date' => EmployeeProfileTemplateRequestRules::persistedValue(
                $validated,
                'issue_date',
                $existing?->issue_date,
            ),
            'expiry_date' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'expiry_date')
                ? (isset($validated['expiry_date']) && $validated['expiry_date'] !== ''
                    ? $validated['expiry_date']
                    : null)
                : $existing?->expiry_date,
            'institute_center' => EmployeeProfileTemplateRequestRules::persistedValue(
                $validated,
                'institute_center',
                $existing?->institute_center,
            ),
            'country_id' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'country_id')
                ? (isset($validated['country_id']) && $validated['country_id'] !== ''
                    ? (int) $validated['country_id']
                    : null)
                : $existing?->country_id,
        ];
    }

    private function storeCertificate(UploadedFile $file, int $companyId): string
    {
        return $file->storePublicly(
            "employees/{$companyId}/training-certificates",
            ['disk' => 'public'],
        );
    }

    private function deleteCertificate(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    /**
     * @param  array<string, int>  $skipped
     */
    private function formatTrainingImportFailureMessage(array $skipped): string
    {
        $details = [];

        if ($skipped['missing_required_fields'] > 0) {
            $details[] = "missing course, issue_date, or institute_center ({$skipped['missing_required_fields']} row(s))";
        }

        if ($skipped['unknown_course'] > 0) {
            $details[] = "unknown or inactive course name ({$skipped['unknown_course']} row(s))";
        }

        if ($skipped['invalid_issue_date'] > 0) {
            $details[] = "invalid issue_date format ({$skipped['invalid_issue_date']} row(s)) — use YYYY-MM-DD";
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
    private function resolveTrainingCsvHeaderMap(array $header): array
    {
        $map = [];

        foreach ($header as $index => $cell) {
            $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $cell)));

            if (in_array($normalized, ['course', 'name', 'title', 'course name', 'course title', 'training', 'training course'], true)) {
                $map['course'] = (int) $index;
            } elseif (in_array($normalized, ['issue date', 'issue_date', 'issued', 'issued on', 'issued_on', 'date issued'], true)) {
                $map['issue_date'] = (int) $index;
            } elseif (in_array($normalized, ['expiry date', 'expiry_date', 'expires', 'expiration', 'expiry', 'valid until', 'valid_until'], true)) {
                $map['expiry_date'] = (int) $index;
            } elseif (in_array($normalized, ['institute', 'institute_center', 'institute center', 'center', 'training center', 'training_center', 'institute/center'], true)) {
                $map['institute_center'] = (int) $index;
            } elseif (in_array($normalized, ['country', 'country name', 'country_name', 'nation'], true)) {
                $map['country'] = (int) $index;
            }
        }

        return $map;
    }

    private function parseTrainingCsvDate(string $value): ?CarbonImmutable
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
}
