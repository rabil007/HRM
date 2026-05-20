<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\ImportEmployeeWorkExperienceRequest;
use App\Models\Employee;
use App\Models\EmployeeWorkExperience;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EmployeeWorkExperienceController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'job_title' => ['required', 'string', 'max:255'],
            'date_from' => ['required', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'responsibility' => ['nullable', 'string', 'max:65535'],
        ]);

        $maxSort = EmployeeWorkExperience::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->max('sort_order');

        EmployeeWorkExperience::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
            'company_name' => $validated['company_name'],
            'job_title' => $validated['job_title'],
            'date_from' => $validated['date_from'],
            'date_to' => $validated['date_to'] ?? null,
            'responsibility' => isset($validated['responsibility']) && $validated['responsibility'] !== ''
                ? $validated['responsibility']
                : null,
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

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'job_title' => ['required', 'string', 'max:255'],
            'date_from' => ['required', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'responsibility' => ['nullable', 'string', 'max:65535'],
        ]);

        $workExperience->update([
            'company_name' => $validated['company_name'],
            'job_title' => $validated['job_title'],
            'date_from' => $validated['date_from'],
            'date_to' => $validated['date_to'] ?? null,
            'responsibility' => isset($validated['responsibility']) && $validated['responsibility'] !== ''
                ? $validated['responsibility']
                : null,
        ]);

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

        $csv = "company_name,job_title,date_from,date_to,responsibility\n";
        $csv .= "Example Corp,Lifting Engineer,2020-01-01,2023-06-01,Offshore operations\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="work-experience-import-template.csv"',
        ]);
    }

    public function import(ImportEmployeeWorkExperienceRequest $request, Employee $employee): RedirectResponse
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

        $map = $this->resolveWorkExperienceCsvHeaderMap($header);

        if (! isset($map['company_name'], $map['job_title'], $map['date_from'])) {
            fclose($handle);

            return back()->withErrors([
                'file' => 'The CSV must include company_name, job_title, and date_from columns.',
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

            $companyName = trim((string) ($row[$map['company_name']] ?? ''));
            $jobTitle = trim((string) ($row[$map['job_title']] ?? ''));
            $dateFromRaw = trim((string) ($row[$map['date_from']] ?? ''));

            if ($companyName === '' && $jobTitle === '' && $dateFromRaw === '') {
                $skipped['empty_rows']++;

                continue;
            }

            if ($companyName === '' || $jobTitle === '' || $dateFromRaw === '') {
                $skipped['missing_required_fields']++;

                continue;
            }

            $parsedFrom = $this->parseWorkExperienceCsvDate($dateFromRaw);
            if ($parsedFrom === null) {
                $skipped['invalid_date_from']++;

                continue;
            }

            $dateTo = null;
            if (isset($map['date_to'])) {
                $dateToRaw = trim((string) ($row[$map['date_to']] ?? ''));
                if ($dateToRaw !== '') {
                    $dateTo = $this->parseWorkExperienceCsvDate($dateToRaw);
                    if ($dateTo === null || $dateTo->lt($parsedFrom)) {
                        $dateTo = null;
                    }
                }
            }

            $responsibility = null;
            if (isset($map['responsibility'])) {
                $r = trim((string) ($row[$map['responsibility']] ?? ''));
                if ($r !== '') {
                    $responsibility = $r;
                }
            }

            EmployeeWorkExperience::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                'sort_order' => $nextSort,
                'company_name' => $companyName,
                'job_title' => $jobTitle,
                'date_from' => $parsedFrom->toDateString(),
                'date_to' => $dateTo?->toDateString(),
                'responsibility' => $responsibility,
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
            $details[] = "missing company_name, job_title, or date_from ({$skipped['missing_required_fields']} row(s))";
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
}
