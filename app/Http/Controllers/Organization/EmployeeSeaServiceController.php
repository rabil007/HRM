<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\ImportEmployeeSeaServiceRequest;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\VesselType;
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

        $validated = $request->validate($this->seaServiceRules());

        $maxSort = EmployeeSeaService::query()
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->max('sort_order');

        EmployeeSeaService::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
            ...$this->seaServiceAttributes($validated),
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

        $validated = $request->validate($this->seaServiceRules());

        $seaService->update($this->seaServiceAttributes($validated));

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

        $csv = "vessel_type,vessel_name,rank,total_months,total_days,grt,bhp,client,is_offshore\n";
        $csv .= "Tanker,MT Example,Chief Officer,12,15,50000,8000,Acme Corp,yes\n";

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

        if (! isset($map['vessel_type'], $map['vessel_name'], $map['rank'], $map['total_months'], $map['total_days'])) {
            fclose($handle);

            return back()->withErrors([
                'file' => 'The CSV must include vessel_type, vessel_name, rank, total_months, and total_days columns.',
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
            'invalid_duration' => 0,
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
            $totalMonthsRaw = trim((string) ($row[$map['total_months']] ?? ''));
            $totalDaysRaw = trim((string) ($row[$map['total_days']] ?? ''));

            if ($vesselTypeName === '' && $vesselName === '' && $rankName === '') {
                $skipped['empty_rows']++;

                continue;
            }

            if ($vesselTypeName === '') {
                $skipped['missing_vessel_type']++;

                continue;
            }

            if ($vesselName === '' || $rankName === '' || $totalMonthsRaw === '' || $totalDaysRaw === '') {
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

            if (! is_numeric($totalMonthsRaw) || ! is_numeric($totalDaysRaw)) {
                $skipped['invalid_duration']++;

                continue;
            }

            $totalMonths = (int) $totalMonthsRaw;
            $totalDays = (int) $totalDaysRaw;

            if ($totalMonths < 0 || $totalMonths > 1200 || $totalDays < 0 || $totalDays > 366) {
                $skipped['invalid_duration']++;

                continue;
            }

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
                'total_months' => $totalMonths,
                'total_days' => $totalDays,
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

        return back()->with('success', "Imported {$imported} sea service row(s).");
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
            $details[] = "missing vessel_name, rank, total_months, or total_days ({$skipped['missing_required_fields']} row(s))";
        }

        if ($skipped['unknown_vessel_type'] > 0) {
            $names = implode(', ', array_keys($unknownVesselTypes));
            $details[] = "unknown vessel type(s): {$names}";
        }

        if ($skipped['unknown_rank'] > 0) {
            $names = implode(', ', array_keys($unknownRanks));
            $details[] = "unknown rank(s): {$names} — add them in Settings → Master Data → Ranks";
        }

        if ($skipped['invalid_duration'] > 0) {
            $details[] = "invalid total_months or total_days ({$skipped['invalid_duration']} row(s))";
        }

        if ($details === []) {
            return 'No rows were imported. Check the CSV columns and use exact master data names from Settings.';
        }

        return 'No rows were imported. '.implode('; ', $details).'.';
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
            } elseif (in_array($normalized, ['total months', 'total_months', 'months', 'months served'], true)) {
                $map['total_months'] = (int) $index;
            } elseif (in_array($normalized, ['total days', 'total_days', 'days', 'days served'], true)) {
                $map['total_days'] = (int) $index;
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
            'total_months' => ['required', 'integer', 'min:0', 'max:1200'],
            'total_days' => ['required', 'integer', 'min:0', 'max:366'],
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
    private function seaServiceAttributes(array $validated): array
    {
        return [
            'vessel_type_id' => (int) $validated['vessel_type_id'],
            'vessel_name' => $validated['vessel_name'],
            'rank_id' => (int) $validated['rank_id'],
            'total_months' => $validated['total_months'],
            'total_days' => $validated['total_days'],
            'grt' => $validated['grt'] ?? null,
            'bhp' => isset($validated['bhp']) ? (int) $validated['bhp'] : null,
            'client_id' => isset($validated['client_id']) ? (int) $validated['client_id'] : null,
            'is_offshore' => (bool) ($validated['is_offshore'] ?? false),
        ];
    }
}
