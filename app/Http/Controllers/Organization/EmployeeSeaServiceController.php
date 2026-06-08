<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\BulkDestroyEmployeeSeaServicesRequest;
use App\Http\Requests\Organization\Employee\ImportEmployeeSeaServiceRequest;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\VesselType;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use App\Support\Employees\SeaServiceDuration;
use App\Support\Imports\FlexibleCsvDateParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeSeaServiceController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = EmployeeProfileTemplateRequestRules::validate(
            $request,
            $employee,
            'employee_sea_services',
            $this->seaServiceRules(),
        );

        $attributes = $this->seaServiceAttributes($validated, null);

        EmployeeProfileTemplateRequestRules::assertRecordHasMeaningfulContent(
            $attributes,
            ['vessel_type_id', 'vessel_name', 'rank_id', 'start_date', 'end_date', 'grt', 'bhp', 'client_id'],
            'Enter at least one sea service field before saving.',
        );

        $maxSort = EmployeeSeaService::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->max('sort_order');

        EmployeeSeaService::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
            ...$attributes,
        ]);

        return back()->with('success', 'Sea service record added.');
    }

    public function update(Request $request, Employee $employee, EmployeeSeaService $seaService): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $seaService->employee_id === $employee->id
            && $seaService->company_id === $companyId,
            403,
        );

        $validated = EmployeeProfileTemplateRequestRules::validate(
            $request,
            $employee,
            'employee_sea_services',
            $this->seaServiceRules(),
        );

        $attributes = $this->seaServiceAttributes($validated, $seaService);

        EmployeeProfileTemplateRequestRules::assertRecordHasMeaningfulContent(
            $attributes,
            ['vessel_type_id', 'vessel_name', 'rank_id', 'start_date', 'end_date', 'grt', 'bhp', 'client_id'],
            'Enter at least one sea service field before saving.',
        );

        $seaService->update($attributes);

        return back()->with('success', 'Sea service record updated.');
    }

    public function destroy(Request $request, Employee $employee, EmployeeSeaService $seaService): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            $employee->company_id === $companyId
            && $seaService->employee_id === $employee->id
            && $seaService->company_id === $companyId,
            403,
        );

        $seaService->delete();

        return back()->with('success', 'Sea service record removed.');
    }

    public function bulkDestroy(
        BulkDestroyEmployeeSeaServicesRequest $request,
        Employee $employee,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $deleted = EmployeeSeaService::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->whereIn('id', $request->validated('sea_service_ids'))
            ->delete();

        if ($deleted === 0) {
            return back()->with('error', 'No sea service records could be deleted.');
        }

        $label = $deleted === 1 ? '1 sea service record' : "{$deleted} sea service records";

        return back()->with('success', "Deleted {$label}.");
    }

    public function reorder(Request $request, Employee $employee): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'exists:employee_sea_services,id'],
        ]);

        $ownedIds = EmployeeSeaService::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->orderBy('id')
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $sentIds = collect($validated['order'])->sort()->values()->all();

        abort_if($ownedIds !== $sentIds, 422);

        DB::transaction(function () use ($validated, $companyId, $employee): void {
            foreach ($validated['order'] as $idx => $id) {
                EmployeeSeaService::query()
                    ->where('company_id', $companyId)
                    ->where('employee_id', $employee->id)
                    ->whereKey($id)
                    ->update(['sort_order' => $idx]);
            }
        });

        return back()->with('success', 'Sea service order saved.');
    }

    public function importTemplate(Request $request, Employee $employee): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($employee->company_id === $companyId, 403);

        $csv = "vessel_type,vessel_name,rank,start_date,end_date,grt,bhp,client,is_offshore\n";
        $csv .= "Tanker,MT Example,Chief Officer,2022-01-01,2023-04-15,50000,8000,Acme Corp,yes\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="sea-service-import-template.csv"',
        ]);
    }

    public function import(ImportEmployeeSeaServiceRequest $request, Employee $employee): RedirectResponse
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

        $map = $this->resolveSeaServiceCsvHeaderMap($header);

        if (! isset($map['vessel_type'], $map['vessel_name'], $map['rank'], $map['start_date'], $map['end_date'])) {
            fclose($handle);

            return back()->withErrors([
                'file' => 'The CSV must include vessel_type, vessel_name, rank, start_date, and end_date columns.',
            ]);
        }

        $vesselTypeIdsByName = VesselType::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (VesselType $row) => [mb_strtolower(trim($row->name)) => $row->id]);

        $rankIdsByName = Rank::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Rank $row) => [mb_strtolower(trim($row->name)) => $row->id]);

        $clientIdsByName = Client::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Client $row) => [mb_strtolower(trim($row->name)) => $row->id]);

        $maxSort = EmployeeSeaService::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->max('sort_order');
        $nextSort = $maxSort === null ? 0 : ((int) $maxSort + 1);

        $imported = 0;
        $skipped = [
            'empty_rows' => 0,
            'missing_vessel_type' => 0,
            'missing_required_fields' => 0,
            'unknown_vessel_type' => 0,
            'unknown_rank' => 0,
            'invalid_start_date' => 0,
            'invalid_end_date' => 0,
            'invalid_date_range' => 0,
        ];
        $unknownVesselTypes = [];
        $unknownRanks = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $vesselTypeName = trim((string) ($row[$map['vessel_type']] ?? ''));
            $vesselName = trim((string) ($row[$map['vessel_name']] ?? ''));
            $rankName = trim((string) ($row[$map['rank']] ?? ''));
            $startDateRaw = trim((string) ($row[$map['start_date']] ?? ''));
            $endDateRaw = trim((string) ($row[$map['end_date']] ?? ''));

            if ($vesselTypeName === '' && $vesselName === '' && $rankName === '') {
                $skipped['empty_rows']++;

                continue;
            }

            if ($vesselTypeName === '') {
                $skipped['missing_vessel_type']++;

                continue;
            }

            if ($vesselName === '' || $rankName === '' || $startDateRaw === '' || $endDateRaw === '') {
                $skipped['missing_required_fields']++;

                continue;
            }

            $vesselTypeId = $vesselTypeIdsByName[mb_strtolower($vesselTypeName)] ?? null;
            $rankId = $rankIdsByName[mb_strtolower($rankName)] ?? null;

            if ($vesselTypeId === null) {
                $skipped['unknown_vessel_type']++;
                $unknownVesselTypes[$vesselTypeName] = true;

                continue;
            }

            if ($rankId === null) {
                $skipped['unknown_rank']++;
                $unknownRanks[$rankName] = true;

                continue;
            }

            $parsedStart = FlexibleCsvDateParser::parse($startDateRaw);
            if ($parsedStart === null) {
                $skipped['invalid_start_date']++;

                continue;
            }

            $parsedEnd = FlexibleCsvDateParser::parse($endDateRaw);
            if ($parsedEnd === null) {
                $skipped['invalid_end_date']++;

                continue;
            }

            if ($parsedEnd->lt($parsedStart)) {
                $skipped['invalid_date_range']++;

                continue;
            }

            $duration = SeaServiceDuration::fromDates(
                $parsedStart->toDateString(),
                $parsedEnd->toDateString(),
            );

            $grt = null;
            if (isset($map['grt'])) {
                $grtRaw = trim((string) ($row[$map['grt']] ?? ''));
                if ($grtRaw !== '' && is_numeric($grtRaw)) {
                    $grt = (float) $grtRaw;
                }
            }

            $bhp = null;
            if (isset($map['bhp'])) {
                $bhpRaw = trim((string) ($row[$map['bhp']] ?? ''));
                if ($bhpRaw !== '' && is_numeric($bhpRaw)) {
                    $bhp = (int) $bhpRaw;
                }
            }

            $clientId = null;
            if (isset($map['client'])) {
                $clientName = trim((string) ($row[$map['client']] ?? ''));
                if ($clientName !== '') {
                    $clientId = $clientIdsByName[mb_strtolower($clientName)] ?? null;
                }
            }

            $isOffshore = false;
            if (isset($map['is_offshore'])) {
                $isOffshore = $this->parseSeaServiceCsvBoolean((string) ($row[$map['is_offshore']] ?? ''));
            }

            EmployeeSeaService::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                'sort_order' => $nextSort,
                'vessel_type_id' => $vesselTypeId,
                'vessel_name' => $vesselName,
                'rank_id' => $rankId,
                'start_date' => $parsedStart->toDateString(),
                'end_date' => $parsedEnd->toDateString(),
                'total_months' => $duration['months'],
                'total_days' => $duration['days'],
                'grt' => $grt,
                'bhp' => $bhp,
                'client_id' => $clientId,
                'is_offshore' => $isOffshore,
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
                'file' => $this->formatSeaServiceImportFailureMessage($skipped, $unknownVesselTypes, $unknownRanks),
            ]);
        }

        $totalSkipped = array_sum($skipped);
        $message = "Imported {$imported} sea service row(s).";

        if ($totalSkipped > 0) {
            $message .= ' '.$this->formatSeaServiceImportSkippedSummary($skipped, $unknownVesselTypes, $unknownRanks);
        }

        return back()->with('success', $message);
    }

    /**
     * @param  array<string, int>  $skipped
     * @param  array<string, bool>  $unknownVesselTypes
     * @param  array<string, bool>  $unknownRanks
     */
    private function formatSeaServiceImportFailureMessage(
        array $skipped,
        array $unknownVesselTypes,
        array $unknownRanks,
    ): string {
        $details = [];

        if ($skipped['missing_vessel_type'] > 0) {
            $details[] = "missing vessel_type ({$skipped['missing_vessel_type']} row(s))";
        }

        if ($skipped['missing_required_fields'] > 0) {
            $details[] = "missing vessel_name, rank, start_date, or end_date ({$skipped['missing_required_fields']} row(s))";
        }

        if ($skipped['unknown_vessel_type'] > 0) {
            $names = implode(', ', array_keys($unknownVesselTypes));
            $details[] = "unknown vessel type(s): {$names}";
        }

        if ($skipped['unknown_rank'] > 0) {
            $names = implode(', ', array_keys($unknownRanks));
            $details[] = "unknown rank(s): {$names} — add them in Settings → Master Data → Ranks";
        }

        if ($skipped['invalid_start_date'] > 0) {
            $details[] = "invalid start_date format ({$skipped['invalid_start_date']} row(s)) — use YYYY-MM-DD";
        }

        if ($skipped['invalid_end_date'] > 0) {
            $details[] = "invalid end_date format ({$skipped['invalid_end_date']} row(s)) — use YYYY-MM-DD";
        }

        if ($skipped['invalid_date_range'] > 0) {
            $details[] = "end_date before start_date ({$skipped['invalid_date_range']} row(s))";
        }

        if ($details === []) {
            return 'No rows were imported. Check the CSV columns and use exact master data names from Settings.';
        }

        return 'No rows were imported. '.implode('; ', $details).'.';
    }

    /**
     * @param  array<string, int>  $skipped
     * @param  array<string, bool>  $unknownVesselTypes
     * @param  array<string, bool>  $unknownRanks
     */
    private function formatSeaServiceImportSkippedSummary(
        array $skipped,
        array $unknownVesselTypes,
        array $unknownRanks,
    ): string {
        $details = [];

        if ($skipped['invalid_start_date'] > 0 || $skipped['invalid_end_date'] > 0) {
            $dateSkipped = $skipped['invalid_start_date'] + $skipped['invalid_end_date'];
            $details[] = "{$dateSkipped} row(s) had unrecognised dates (use DD/MM/YYYY or YYYY-MM-DD)";
        }

        if ($skipped['invalid_date_range'] > 0) {
            $details[] = "{$skipped['invalid_date_range']} row(s) had end date before start date";
        }

        if ($skipped['unknown_vessel_type'] > 0) {
            $names = implode(', ', array_keys($unknownVesselTypes));
            $details[] = "unknown vessel type: {$names}";
        }

        if ($skipped['unknown_rank'] > 0) {
            $names = implode(', ', array_keys($unknownRanks));
            $details[] = "unknown rank: {$names}";
        }

        if ($skipped['missing_required_fields'] > 0) {
            $details[] = "{$skipped['missing_required_fields']} row(s) missing required fields";
        }

        if ($details === []) {
            return 'Some rows were skipped.';
        }

        return 'Skipped: '.implode('; ', $details).'.';
    }

    /**
     * @param  array<int, string|null>  $header
     * @return array<string, int>
     */
    private function resolveSeaServiceCsvHeaderMap(array $header): array
    {
        $map = [];

        foreach ($header as $index => $cell) {
            $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $cell)));

            if (in_array($normalized, ['vessel type', 'vessel_type', 'type', 'vessel category'], true)) {
                $map['vessel_type'] = (int) $index;
            } elseif (in_array($normalized, ['vessel name', 'vessel_name', 'vessel', 'ship name', 'ship'], true)) {
                $map['vessel_name'] = (int) $index;
            } elseif (in_array($normalized, ['rank', 'rank_name', 'position', 'job title', 'job_title'], true)) {
                $map['rank'] = (int) $index;
            } elseif (in_array($normalized, ['start date', 'start_date', 'date from', 'date_from', 'from', 'started'], true)) {
                $map['start_date'] = (int) $index;
            } elseif (in_array($normalized, ['end date', 'end_date', 'date to', 'date_to', 'to', 'finished', 'ended'], true)) {
                $map['end_date'] = (int) $index;
            } elseif (in_array($normalized, ['grt', 'gross tonnage', 'gross_tonnage'], true)) {
                $map['grt'] = (int) $index;
            } elseif (in_array($normalized, ['bhp', 'brake horsepower', 'horsepower'], true)) {
                $map['bhp'] = (int) $index;
            } elseif (in_array($normalized, ['client', 'client name', 'client_name', 'company', 'employer'], true)) {
                $map['client'] = (int) $index;
            } elseif (in_array($normalized, ['is offshore', 'is_offshore', 'offshore', 'off shore'], true)) {
                $map['is_offshore'] = (int) $index;
            }
        }

        return $map;
    }

    private function parseSeaServiceCsvBoolean(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));

        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'y', 'on', 'offshore'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function seaServiceRules(): array
    {
        return [
            'vessel_type_id' => ['required', Rule::exists('vessel_types', 'id')->where('is_active', true)],
            'vessel_name' => ['required', 'string', 'max:255'],
            'rank_id' => ['required', Rule::exists('ranks', 'id')->where('is_active', true)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'grt' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'bhp' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('is_active', true)],
            'is_offshore' => ['sometimes', 'boolean'],
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
    private function seaServiceAttributes(array $validated, ?EmployeeSeaService $existing = null): array
    {
        $startDate = EmployeeProfileTemplateRequestRules::persistedNullableValue(
            $validated,
            'start_date',
            $existing?->start_date,
        );
        $endDate = EmployeeProfileTemplateRequestRules::persistedNullableValue(
            $validated,
            'end_date',
            $existing?->end_date,
        );

        if ($startDate !== null && $endDate !== null) {
            $duration = SeaServiceDuration::fromDates(
                (string) $startDate,
                (string) $endDate,
            );
        } else {
            $duration = [
                'months' => (int) ($existing?->total_months ?? 0),
                'days' => (int) ($existing?->total_days ?? 0),
            ];
        }

        return [
            'vessel_type_id' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'vessel_type_id',
                $existing?->vessel_type_id,
                asInteger: true,
            ),
            'vessel_name' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'vessel_name',
                $existing?->vessel_name,
            ),
            'rank_id' => EmployeeProfileTemplateRequestRules::persistedNullableValue(
                $validated,
                'rank_id',
                $existing?->rank_id,
                asInteger: true,
            ),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_months' => $duration['months'],
            'total_days' => $duration['days'],
            'grt' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'grt')
                ? ($validated['grt'] ?? null)
                : $existing?->grt,
            'bhp' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'bhp')
                ? (isset($validated['bhp']) ? (int) $validated['bhp'] : null)
                : $existing?->bhp,
            'client_id' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'client_id')
                ? (isset($validated['client_id']) ? (int) $validated['client_id'] : null)
                : $existing?->client_id,
            'is_offshore' => EmployeeProfileTemplateRequestRules::hasValidated($validated, 'is_offshore')
                ? (bool) ($validated['is_offshore'] ?? false)
                : (bool) ($existing?->is_offshore ?? false),
        ];
    }
}
