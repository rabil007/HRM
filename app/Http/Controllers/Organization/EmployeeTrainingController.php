<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\BulkDestroyEmployeeTrainingsRequest;
use App\Http\Requests\Organization\Employee\BulkStoreEmployeeTrainingRequest;
use App\Http\Requests\Organization\Employee\ImportEmployeeTrainingRequest;
use App\Models\Country;
use App\Models\Course;
use App\Models\Employee;
use App\Models\EmployeeTraining;
use App\Support\EmployeeDocuments\DocumentUploadOptimizer;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use App\Support\Uploads\FailedUploadLogger;
use App\Support\Uploads\UploadedFileStorage;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeTrainingController extends Controller
{
    public function __construct(private DocumentUploadOptimizer $uploadOptimizer) {}

    /** @var array<string, string> */
    private const TRAINING_REQUEST_FIELD_ALIASES = [
        'certificate' => 'certificate_path',
    ];

    /** @var array<string, string> */
    private const TRAINING_CSV_TEMPLATE_FIELD_MAP = [
        'course_id' => 'course',
        'issue_date' => 'issue_date',
        'expiry_date' => 'expiry_date',
        'institute_center' => 'institute_center',
        'country_id' => 'country',
    ];

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = EmployeeProfileTemplateRequestRules::validate(
            $request,
            $employee,
            'employee_trainings',
            $this->trainingRules(),
            self::TRAINING_REQUEST_FIELD_ALIASES,
        );

        EmployeeProfileTemplateRequestRules::assertRequiredFilePresent(
            $request,
            $employee,
            'employee_trainings',
            'certificate_path',
            'certificate',
        );

        $attributes = $this->trainingAttributes($validated, null);

        $this->assertTrainingHasContent($attributes, $request->hasFile('certificate'));

        $maxSort = EmployeeTraining::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->max('sort_order');

        $training = EmployeeTraining::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
            ...$attributes,
        ]);

        if ($request->hasFile('certificate')) {
            $training->update([
                'certificate_path' => $this->storeCertificate(
                    $request->file('certificate'),
                    $companyId,
                    $employee->id,
                ),
            ]);
        }

        return back()->with('success', 'Training record added.');
    }

    public function bulkStore(BulkStoreEmployeeTrainingRequest $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = $request->validated();

        $maxSort = EmployeeTraining::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->max('sort_order');
        $nextSort = $maxSort === null ? 0 : ((int) $maxSort + 1);

        foreach ($validated['trainings'] as $index => $trainingData) {
            $attributes = $this->trainingAttributes($trainingData, null);
            $hasCertificate = $request->hasFile("trainings.{$index}.certificate");

            $this->assertTrainingHasContent(
                $attributes,
                $hasCertificate,
                "trainings.{$index}._",
            );

            EmployeeProfileTemplateRequestRules::assertRequiredFilePresent(
                $request,
                $employee,
                'employee_trainings',
                'certificate_path',
                "trainings.{$index}.certificate",
            );

            $training = EmployeeTraining::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                'sort_order' => $nextSort,
                ...$attributes,
            ]);

            $nextSort++;

            if ($hasCertificate) {
                $training->update([
                    'certificate_path' => $this->storeCertificate(
                        $request->file("trainings.{$index}.certificate"),
                        $companyId,
                        $employee->id,
                        $index,
                    ),
                ]);
            }
        }

        return back()->with('success', 'Training records added.');
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
            self::TRAINING_REQUEST_FIELD_ALIASES,
        );

        $attributes = $this->trainingAttributes($validated, $training);

        EmployeeProfileTemplateRequestRules::assertRecordHasMeaningfulContent(
            $attributes,
            ['course_id', 'issue_date', 'expiry_date', 'institute_center', 'country_id'],
            'Enter at least one training detail or upload a certificate.',
        );

        $certificatePath = $training->certificate_path;

        if ($request->boolean('remove_certificate')) {
            $this->deleteCertificate($certificatePath);
            $certificatePath = null;
            $attributes['certificate_path'] = null;
        }

        EmployeeProfileTemplateRequestRules::assertRequiredFilePresent(
            $request,
            $employee,
            'employee_trainings',
            'certificate_path',
            'certificate',
            $certificatePath,
        );

        if ($request->hasFile('certificate')) {
            $this->deleteCertificate($certificatePath);
            $attributes['certificate_path'] = $this->storeCertificate(
                $request->file('certificate'),
                $companyId,
                $employee->id,
                trainingId: $training->id,
            );
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

    public function bulkDestroy(
        BulkDestroyEmployeeTrainingsRequest $request,
        Employee $employee,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $trainings = EmployeeTraining::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->whereIn('id', $request->validated('training_ids'))
            ->get();

        if ($trainings->isEmpty()) {
            return back()->with('error', 'No training records could be deleted.');
        }

        foreach ($trainings as $training) {
            $this->deleteCertificate($training->certificate_path);
            $training->delete();
        }

        $deleted = $trainings->count();
        $label = $deleted === 1 ? '1 training record' : "{$deleted} training records";

        return back()->with('success', "Deleted {$label}.");
    }

    public function importTemplate(Request $request, Employee $employee): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        EmployeeProfileTemplateRequestRules::assertTabForTable($employee, 'employee_trainings');

        $csv = $this->buildTrainingImportTemplateCsv($employee);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="training-import-template.csv"',
        ]);
    }

    public function import(ImportEmployeeTrainingRequest $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        EmployeeProfileTemplateRequestRules::assertTabForTable($employee, 'employee_trainings');

        $visibleCsvColumns = $this->trainingCsvColumnsForEmployee($employee);

        if ($visibleCsvColumns === []) {
            return back()->withErrors([
                'file' => 'Training import is not available for this employee profile template.',
            ]);
        }

        $requiredCsvColumns = $this->trainingImportRequiredCsvKeys($employee);

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

            $rowValues = $this->extractTrainingCsvRowValues($employee, $row, $map);

            if ($this->trainingCsvRowIsEmpty($rowValues)) {
                $skipped['empty_rows']++;

                continue;
            }

            foreach ($requiredCsvColumns as $requiredColumn) {
                $fieldKey = array_search($requiredColumn, self::TRAINING_CSV_TEMPLATE_FIELD_MAP, true);
                $value = $fieldKey !== false ? ($rowValues[$fieldKey] ?? null) : null;

                if ($value === null || $value === '') {
                    $skipped['missing_required_fields']++;

                    continue 2;
                }
            }

            $courseId = null;
            if (
                EmployeeProfileTemplateRequestRules::isFieldVisible($employee, 'employee_trainings', 'course_id')
                && ($rowValues['course_id'] ?? '') !== ''
            ) {
                $courseId = $courseByLower[mb_strtolower((string) $rowValues['course_id'])] ?? null;

                if ($courseId === null) {
                    $skipped['unknown_course']++;

                    continue;
                }
            }

            $issueDate = null;
            if (
                EmployeeProfileTemplateRequestRules::isFieldVisible($employee, 'employee_trainings', 'issue_date')
                && ($rowValues['issue_date'] ?? '') !== ''
            ) {
                $parsedIssueDate = $this->parseTrainingCsvDate((string) $rowValues['issue_date']);

                if ($parsedIssueDate === null) {
                    $skipped['invalid_issue_date']++;

                    continue;
                }

                $issueDate = $parsedIssueDate->toDateString();
            }

            $expiryDate = null;
            if (
                EmployeeProfileTemplateRequestRules::isFieldVisible($employee, 'employee_trainings', 'expiry_date')
                && ($rowValues['expiry_date'] ?? '') !== ''
                && $issueDate !== null
            ) {
                $parsedExpiry = $this->parseTrainingCsvDate((string) $rowValues['expiry_date']);

                if ($parsedExpiry !== null && $parsedExpiry->gte(CarbonImmutable::parse($issueDate))) {
                    $expiryDate = $parsedExpiry->toDateString();
                }
            }

            $instituteCenter = EmployeeProfileTemplateRequestRules::isFieldVisible($employee, 'employee_trainings', 'institute_center')
                ? (($rowValues['institute_center'] ?? '') !== '' ? (string) $rowValues['institute_center'] : null)
                : null;

            $countryId = null;
            if (
                EmployeeProfileTemplateRequestRules::isFieldVisible($employee, 'employee_trainings', 'country_id')
                && ($rowValues['country_id'] ?? '') !== ''
            ) {
                $countryId = $countryByLower[mb_strtolower((string) $rowValues['country_id'])] ?? null;
            }

            $attributes = [
                'course_id' => $courseId,
                'issue_date' => $issueDate,
                'expiry_date' => $expiryDate,
                'institute_center' => $instituteCenter,
                'country_id' => $countryId,
            ];

            if (! $this->trainingHasMeaningfulContent($attributes)) {
                $skipped['empty_rows']++;

                continue;
            }

            EmployeeTraining::query()->create([
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
            'remove_certificate' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function trainingAttributes(array $validated, ?EmployeeTraining $existing = null): array
    {
        return [
            'course_id' => $this->nullableTrainingAttribute($validated, 'course_id', $existing, asInteger: true),
            'issue_date' => $this->nullableTrainingAttribute($validated, 'issue_date', $existing),
            'expiry_date' => $this->nullableTrainingAttribute($validated, 'expiry_date', $existing),
            'institute_center' => $this->nullableTrainingAttribute($validated, 'institute_center', $existing),
            'country_id' => $this->nullableTrainingAttribute($validated, 'country_id', $existing, asInteger: true),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function nullableTrainingAttribute(
        array $validated,
        string $key,
        ?EmployeeTraining $existing,
        bool $asInteger = false,
    ): mixed {
        return EmployeeProfileTemplateRequestRules::persistedNullableValue(
            $validated,
            $key,
            $existing?->{$key},
            $asInteger,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertTrainingHasContent(
        array $attributes,
        bool $hasCertificate,
        string $errorKey = '_',
    ): void {
        if ($hasCertificate || $this->trainingHasMeaningfulContent($attributes)) {
            return;
        }

        throw ValidationException::withMessages([
            $errorKey => 'Enter at least one training detail or upload a certificate.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function trainingHasMeaningfulContent(array $attributes): bool
    {
        foreach (['course_id', 'issue_date', 'expiry_date', 'institute_center', 'country_id'] as $key) {
            $value = $attributes[$key] ?? null;

            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function trainingCsvColumnsForEmployee(Employee $employee): array
    {
        $columns = [];

        foreach (self::TRAINING_CSV_TEMPLATE_FIELD_MAP as $fieldKey => $column) {
            if (EmployeeProfileTemplateRequestRules::isFieldVisible($employee, 'employee_trainings', $fieldKey)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function trainingImportRequiredCsvKeys(Employee $employee): array
    {
        $columns = [];

        foreach (self::TRAINING_CSV_TEMPLATE_FIELD_MAP as $fieldKey => $column) {
            if (
                EmployeeProfileTemplateRequestRules::isFieldVisible($employee, 'employee_trainings', $fieldKey)
                && EmployeeProfileTemplateRequestRules::isFieldRequired($employee, 'employee_trainings', $fieldKey)
            ) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    private function buildTrainingImportTemplateCsv(Employee $employee): string
    {
        $columns = $this->trainingCsvColumnsForEmployee($employee);
        $sampleValues = [
            'course' => 'STCW Basic Safety',
            'issue_date' => '2024-11-26',
            'expiry_date' => '2029-11-26',
            'institute_center' => 'BINA SENA MTC',
            'country' => 'United Arab Emirates',
        ];

        $header = implode(',', $columns);
        $row = implode(',', array_map(
            fn (string $column): string => $sampleValues[$column] ?? '',
            $columns,
        ));

        return $header."\n".$row."\n";
    }

    /**
     * @param  array<int, string|null>  $row
     * @param  array<string, int>  $map
     * @return array<string, string|null>
     */
    private function extractTrainingCsvRowValues(Employee $employee, array $row, array $map): array
    {
        $values = [];

        foreach (self::TRAINING_CSV_TEMPLATE_FIELD_MAP as $fieldKey => $column) {
            if (! EmployeeProfileTemplateRequestRules::isFieldVisible($employee, 'employee_trainings', $fieldKey)) {
                continue;
            }

            if (! array_key_exists($column, $map)) {
                $values[$fieldKey] = null;

                continue;
            }

            $raw = trim((string) ($row[$map[$column]] ?? ''));

            $values[$fieldKey] = $raw === '' ? null : $raw;
        }

        return $values;
    }

    /**
     * @param  array<string, string|null>  $rowValues
     */
    private function trainingCsvRowIsEmpty(array $rowValues): bool
    {
        foreach ($rowValues as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    private function storeCertificate(
        UploadedFile $file,
        int $companyId,
        int $employeeId,
        ?int $trainingIndex = null,
        ?int $trainingId = null,
    ): string {
        $prepared = $this->uploadOptimizer->prepare($file);

        $logContext = [
            'upload_module' => 'employee_training_certificate',
            'employee_id' => $employeeId,
        ];

        if ($trainingIndex !== null) {
            $logContext['training_index'] = $trainingIndex;
        }

        if ($trainingId !== null) {
            $logContext['training_id'] = $trainingId;
        }

        $storagePath = "employees/{$companyId}/training-certificates";

        try {
            $storedPath = UploadedFileStorage::storePublicly(
                $prepared->file,
                $storagePath,
                [
                    'disk' => 'public',
                    'log_context' => $logContext,
                ],
            );

            FailedUploadLogger::logStorageSuccess(
                $prepared->file,
                'storePublicly',
                $storagePath,
                $storedPath,
                $logContext,
            );

            return $storedPath;
        } finally {
            $prepared->cleanup();
        }
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
            $details[] = "missing required training fields ({$skipped['missing_required_fields']} row(s))";
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
