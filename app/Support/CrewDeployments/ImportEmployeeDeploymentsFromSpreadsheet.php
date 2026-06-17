<?php

namespace App\Support\CrewDeployments;

use App\Models\Client;
use App\Models\CompanyVisaType;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Rank;
use App\Models\Vessel;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

final class ImportEmployeeDeploymentsFromSpreadsheet
{
    public function __construct(
        private readonly SyncSeaServiceFromDeployment $syncSeaService,
    ) {}

    /**
     * @return array{imported: int, skipped: int, errors: list<string>}
     */
    public function import(string $path, int $companyId): array
    {
        $spreadsheet = IOFactory::load($path);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        $headerRowIndex = $this->resolveHeaderRowIndex($rows);
        $map = $this->resolveHeaderMap($rows[$headerRowIndex] ?? []);

        if (! array_key_exists('employee_no', $map)) {
            return [
                'imported' => 0,
                'skipped' => 0,
                'errors' => ['Could not find an employee number column in the spreadsheet.'],
            ];
        }

        $employeesByNo = Employee::query()
            ->where('company_id', $companyId)
            ->get(['id', 'employee_no', 'rank_id'])
            ->mapWithKeys(fn (Employee $employee) => [
                $this->normalizeKey((string) $employee->employee_no) => $employee,
            ])
            ->all();

        $ranksByName = Rank::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Rank $rank) => [$this->normalizeKey($rank->name) => $rank->id])
            ->all();

        $clientsByName = Client::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Client $client) => [$this->normalizeKey($client->name) => $client->id])
            ->all();

        $companyVisaTypesByName = CompanyVisaType::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (CompanyVisaType $companyVisaType) => [$this->normalizeKey($companyVisaType->name) => $companyVisaType->id])
            ->all();

        $vesselsByName = Vessel::query()
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Vessel $vessel) => [Vessel::normalizeName($vessel->name) => $vessel->id])
            ->all();

        $imported = 0;
        $skipped = 0;
        $errors = [];

        for ($index = $headerRowIndex + 1; $index < count($rows); $index++) {
            $row = $rows[$index];

            if (! is_array($row) || $this->rowIsEmpty($row)) {
                continue;
            }

            $employeeNo = trim((string) ($row[$map['employee_no']] ?? ''));

            if ($employeeNo === '') {
                $skipped++;

                continue;
            }

            $employee = $employeesByNo[$this->normalizeKey($employeeNo)] ?? null;

            if (! $employee instanceof Employee) {
                $skipped++;
                $errors[] = 'Row '.($index + 1).": employee number {$employeeNo} not found in HRM.";

                continue;
            }

            $rankName = trim((string) ($row[$map['rank'] ?? -1] ?? ''));
            $clientName = trim((string) ($row[$map['client'] ?? -1] ?? ''));
            $companyVisaTypeName = trim((string) ($row[$map['company_visa_type'] ?? -1] ?? ''));

            $clientId = $clientName !== ''
                ? $this->resolveOrCreateClientId($clientsByName, $clientName)
                : null;

            $companyVisaTypeId = $companyVisaTypeName !== ''
                ? ($companyVisaTypesByName[$this->normalizeKey($companyVisaTypeName)] ?? null)
                : null;

            if ($companyVisaTypeName !== '' && $companyVisaTypeId === null) {
                $errors[] = 'Row '.($index + 1).": sponsor \"{$companyVisaTypeName}\" not found.";
            }

            $vesselName = $this->stringValue($row, $map, 'vessel');
            $vesselId = $vesselName !== null
                ? ($vesselsByName[Vessel::normalizeName($vesselName)] ?? null)
                : null;

            if ($vesselName !== null && $vesselId === null) {
                $errors[] = 'Row '.($index + 1).": vessel \"{$vesselName}\" not found.";
            }

            $maxSort = EmployeeDeployment::query()
                ->where('employee_id', $employee->id)
                ->where('company_id', $companyId)
                ->max('sort_order');

            $deployment = EmployeeDeployment::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
                'rank_id' => $rankName !== ''
                    ? ($ranksByName[$this->normalizeKey($rankName)] ?? $employee->rank_id)
                    : $employee->rank_id,
                'client_id' => $clientId,
                'company_visa_type_id' => $companyVisaTypeId,
                'vessel_id' => $vesselId,
                'arrived_date' => $this->dateValue($row, $map, 'arrived_date'),
                'join_standby_from' => $this->dateValue($row, $map, 'join_standby_from'),
                'join_standby_to' => $this->dateValue($row, $map, 'join_standby_to'),
                'leave_standby_from' => $this->dateValue($row, $map, 'leave_standby_from'),
                'leave_standby_to' => $this->dateValue($row, $map, 'leave_standby_to'),
                'joined_date' => $this->dateValue($row, $map, 'joined_date'),
                'disembarked_date' => $this->dateValue($row, $map, 'disembarked_date'),
                'travelled_date' => $this->dateValue($row, $map, 'travelled_date'),
                'remarks' => $this->stringValue($row, $map, 'remarks'),
            ]);

            $this->syncSeaService->sync($deployment);

            $imported++;
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 20),
        ];
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function resolveHeaderRowIndex(array $rows): int
    {
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized = array_map(
                fn ($cell) => $this->normalizeHeader((string) $cell),
                $row,
            );

            if (in_array('empno', $normalized, true) || in_array('employeeno', $normalized, true)) {
                return (int) $index;
            }
        }

        return 1;
    }

    /**
     * @param  array<int, mixed>  $header
     * @return array<string, int>
     */
    private function resolveHeaderMap(array $header): array
    {
        $map = [];

        foreach ($header as $index => $cell) {
            $normalized = $this->normalizeHeader((string) $cell);

            if (in_array($normalized, ['srno', 'sr', 'no'], true)) {
                continue;
            } elseif (in_array($normalized, ['empno', 'employeeno', 'employeeid'], true)) {
                $map['employee_no'] = (int) $index;
            } elseif ($normalized === 'name') {
                $map['name'] = (int) $index;
            } elseif ($normalized === 'rank') {
                $map['rank'] = (int) $index;
            } elseif ($normalized === 'nationality') {
                $map['nationality'] = (int) $index;
            } elseif (in_array($normalized, ['datearrived', 'arriveddate'], true)) {
                $map['arrived_date'] = (int) $index;
            } elseif (in_array($normalized, ['joinstandbyfrom', 'standbyfrom'], true)) {
                $map['join_standby_from'] = (int) $index;
            } elseif (in_array($normalized, ['joinstandbyto', 'standbyto'], true)) {
                $map['join_standby_to'] = (int) $index;
            } elseif (in_array($normalized, ['leavestandbyfrom'], true)) {
                $map['leave_standby_from'] = (int) $index;
            } elseif (in_array($normalized, ['leavestandbyto'], true)) {
                $map['leave_standby_to'] = (int) $index;
            } elseif (in_array($normalized, ['datejoined', 'joineddate'], true)) {
                $map['joined_date'] = (int) $index;
            } elseif (in_array($normalized, ['datedisembarked', 'disembarkeddate'], true)) {
                $map['disembarked_date'] = (int) $index;
            } elseif (in_array($normalized, ['datetravelled', 'travelleddate', 'datetraveled'], true)) {
                $map['travelled_date'] = (int) $index;
            } elseif ($normalized === 'vessel') {
                $map['vessel'] = (int) $index;
            } elseif (in_array($normalized, ['sponser', 'sponsor', 'companyvisatype', 'companyvisatypes'], true)) {
                $map['company_visa_type'] = (int) $index;
            } elseif (in_array($normalized, ['client', 'cleint'], true)) {
                $map['client'] = (int) $index;
            } elseif ($normalized === 'remarks') {
                $map['remarks'] = (int) $index;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $map
     */
    private function stringValue(array $row, array $map, string $key): ?string
    {
        if (! array_key_exists($key, $map)) {
            return null;
        }

        $value = trim((string) ($row[$map[$key]] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $map
     */
    private function dateValue(array $row, array $map, string $key): ?string
    {
        if (! array_key_exists($key, $map)) {
            return null;
        }

        $raw = $row[$map[$key]] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            try {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $raw))->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return CarbonImmutable::parse((string) $raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeHeader(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($value))) ?? '';
    }

    private function normalizeKey(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * @param  array<string, int>  $cache
     */
    private function resolveOrCreateClientId(array &$cache, string $name): int
    {
        $key = $this->normalizeKey($name);

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $client = Client::query()->create([
            'name' => $name,
            'is_active' => true,
        ]);

        $cache[$key] = $client->id;

        return $client->id;
    }
}
