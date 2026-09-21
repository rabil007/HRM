<?php

namespace App\Support\CrewMovements\Historical;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\MasterData\ClientAssignmentRules;
use App\Support\Settings\CompanyTimezone;
use App\Support\Vessels\ResolvesCompanyVessels;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

final class HistoricalCrewImportPreviewService
{
    public function __construct(
        private readonly HistoricalCrewImportParser $parser,
        private readonly HistoricalCrewAssignmentValidator $validator,
    ) {}

    /**
     * Validate a workbook without persisting any records.
     *
     * @return array{
     *     summary: array{total: int, ready: int, warning: int, blocked: int},
     *     rows: list<array<string, mixed>>,
     *     phase_note: string
     * }
     */
    public function preview(int $companyId, UploadedFile $file, User $actor): array
    {
        $parsedRows = $this->parser->parse($file);
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $lookups = $this->buildLookups($companyId, $actor);

        $evaluated = [];

        foreach ($parsedRows as $parsedRow) {
            $evaluated[] = $this->evaluateRow($parsedRow, $companyId, $timezone, $actor, $lookups);
        }

        $this->applyWorkbookConflicts($evaluated);

        $ready = 0;
        $warning = 0;
        $blocked = 0;
        $rows = [];

        foreach ($evaluated as $row) {
            $status = $row['status'];

            if ($status === 'ready') {
                $ready++;
            } elseif ($status === 'warning') {
                $warning++;
            } else {
                $blocked++;
            }

            $rows[] = $this->presentRow($row);
        }

        return [
            'summary' => [
                'total' => count($rows),
                'ready' => $ready,
                'warning' => $warning,
                'blocked' => $blocked,
            ],
            'rows' => $rows,
            'phase_note' => 'Final bulk import will be enabled in Phase 3. Validation does not write any records.',
        ];
    }

    /**
     * @return array{
     *     employeesByNo: array<string, Employee>,
     *     vesselsByName: array<string, list<Vessel>>,
     *     ranksByName: array<string, list<Rank>>,
     *     clientsByName: array<string, list<Client>>
     * }
     */
    private function buildLookups(int $companyId, User $actor): array
    {
        $employeeQuery = Employee::query()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at');

        $employeeQuery = EmployeeVisibilityScope::apply($employeeQuery, $actor, $companyId);

        $employeesByNo = [];

        foreach ($employeeQuery->get() as $employee) {
            $no = trim((string) ($employee->employee_no ?? ''));

            if ($no === '') {
                continue;
            }

            $employeesByNo[mb_strtolower($no)] = $employee;
        }

        $vesselsByName = [];

        foreach (ResolvesCompanyVessels::queryForCompany($companyId)->get() as $vessel) {
            $key = Vessel::normalizeName((string) $vessel->name);
            $vesselsByName[$key] ??= [];
            $vesselsByName[$key][] = $vessel;
        }

        $ranksByName = [];

        foreach (Rank::query()->get(['id', 'name', 'is_active']) as $rank) {
            $key = mb_strtolower(trim((string) $rank->name));
            $ranksByName[$key] ??= [];
            $ranksByName[$key][] = $rank;
        }

        $clientsByName = [];

        foreach (Client::query()->get(['id', 'name', 'is_active']) as $client) {
            $key = mb_strtolower(trim((string) $client->name));
            $clientsByName[$key] ??= [];
            $clientsByName[$key][] = $client;
        }

        return [
            'employeesByNo' => $employeesByNo,
            'vesselsByName' => $vesselsByName,
            'ranksByName' => $ranksByName,
            'clientsByName' => $clientsByName,
        ];
    }

    /**
     * @param  array{
     *     employeesByNo: array<string, Employee>,
     *     vesselsByName: array<string, list<Vessel>>,
     *     ranksByName: array<string, list<Rank>>,
     *     clientsByName: array<string, list<Client>>
     * }  $lookups
     * @return array<string, mixed>
     */
    private function evaluateRow(
        HistoricalCrewImportRow $parsedRow,
        int $companyId,
        string $timezone,
        User $actor,
        array $lookups,
    ): array {
        $errors = $parsedRow->fieldErrors;
        $warnings = [];
        $resolveErrors = [];

        foreach (HistoricalCrewImportColumns::requiredHeaders() as $required) {
            $value = $parsedRow->raw[$required] ?? null;

            if (($value === null || $value === '') && ! isset($errors[$required])) {
                $resolveErrors[$required] = match ($required) {
                    HistoricalCrewImportColumns::EMPLOYEE_NO => 'employee_no is required.',
                    HistoricalCrewImportColumns::VESSEL => 'vessel is required.',
                    HistoricalCrewImportColumns::RANK => 'rank is required.',
                    HistoricalCrewImportColumns::VESSEL_JOIN_DATE => 'vessel_join_date is required.',
                    HistoricalCrewImportColumns::DISEMBARK_DATE => 'disembark_date is required.',
                    default => "{$required} is required.",
                };
            }
        }

        $employee = null;
        $vessel = null;
        $rank = null;
        $client = null;

        $employeeNo = $parsedRow->employeeNo();

        if ($employeeNo !== null && ! isset($resolveErrors[HistoricalCrewImportColumns::EMPLOYEE_NO])) {
            $employee = $lookups['employeesByNo'][mb_strtolower($employeeNo)] ?? null;

            if ($employee === null) {
                // Distinguish hidden vs missing: if an employee exists in company but is not in visibility-scoped map.
                $existsHidden = Employee::query()
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->whereRaw('LOWER(employee_no) = ?', [mb_strtolower($employeeNo)])
                    ->exists();

                $resolveErrors[HistoricalCrewImportColumns::EMPLOYEE_NO] = $existsHidden
                    ? 'Employee is not visible to your role (hidden department / visibility restriction).'
                    : "Employee number \"{$employeeNo}\" was not found in the active company.";
            }
        }

        $vesselName = $parsedRow->vesselName();

        if ($vesselName !== null && ! isset($resolveErrors[HistoricalCrewImportColumns::VESSEL])) {
            $matches = $lookups['vesselsByName'][Vessel::normalizeName($vesselName)] ?? [];

            if ($matches === []) {
                $resolveErrors[HistoricalCrewImportColumns::VESSEL] = "Vessel \"{$vesselName}\" was not found in the active company.";
            } elseif (count($matches) > 1) {
                $ids = collect($matches)->map(fn (Vessel $v) => '#'.$v->id)->implode(', ');
                $resolveErrors[HistoricalCrewImportColumns::VESSEL] = "Vessel \"{$vesselName}\" is ambiguous ({$ids}). Use the exact name from Reference Data.";
            } else {
                $vessel = $matches[0];
            }
        }

        $rankName = $parsedRow->rankName();

        if ($rankName !== null && ! isset($resolveErrors[HistoricalCrewImportColumns::RANK])) {
            $matches = $lookups['ranksByName'][mb_strtolower(trim($rankName))] ?? [];

            if ($matches === []) {
                $resolveErrors[HistoricalCrewImportColumns::RANK] = "Rank \"{$rankName}\" was not found.";
            } elseif (count($matches) > 1) {
                $ids = collect($matches)->map(fn (Rank $r) => '#'.$r->id)->implode(', ');
                $resolveErrors[HistoricalCrewImportColumns::RANK] = "Rank \"{$rankName}\" is ambiguous ({$ids}).";
            } else {
                $rank = $matches[0];
            }
        }

        $clientName = $parsedRow->clientName();

        if ($clientName !== null) {
            $matches = $lookups['clientsByName'][mb_strtolower(trim($clientName))] ?? [];

            if ($matches === []) {
                $resolveErrors[HistoricalCrewImportColumns::CLIENT] = "Client \"{$clientName}\" was not found.";
            } elseif (count($matches) > 1) {
                $ids = collect($matches)->map(fn (Client $c) => '#'.$c->id)->implode(', ');
                $resolveErrors[HistoricalCrewImportColumns::CLIENT] = "Client \"{$clientName}\" is ambiguous ({$ids}).";
            } else {
                $client = $matches[0];
            }
        } elseif ($vessel !== null && $vessel->client_id !== null) {
            $client = Client::query()->find((int) $vessel->client_id);
        }

        $errors = array_merge($errors, $resolveErrors);

        $domainResult = null;
        $data = null;

        if ($errors === [] && $employee !== null && $vessel !== null && $rank !== null) {
            $clientId = $client?->id;

            if ($clientId === null) {
                $clientId = ClientAssignmentRules::resolveClientIdFromVessel($companyId, (int) $vessel->id);
            }

            try {
                $data = HistoricalCrewAssignmentData::fromArray(
                    data: [
                        'employee_id' => (int) $employee->id,
                        'vessel_id' => (int) $vessel->id,
                        'rank_id' => (int) $rank->id,
                        'client_id' => $clientId,
                        'joined_vessel_at' => $parsedRow->vesselJoinDate(),
                        'disembarked_at' => $parsedRow->disembarkDate(),
                        'mobilisation_start_at' => $parsedRow->raw[HistoricalCrewImportColumns::MOBILISATION_DATE] ?? null,
                        'arrival_at' => $parsedRow->raw[HistoricalCrewImportColumns::TRAVEL_IN_DATE] ?? null,
                        'join_standby_at' => $parsedRow->raw[HistoricalCrewImportColumns::JOIN_STANDBY_DATE] ?? null,
                        'training_start_at' => $parsedRow->raw[HistoricalCrewImportColumns::TRAINING_START_DATE] ?? null,
                        'training_end_at' => $parsedRow->raw[HistoricalCrewImportColumns::TRAINING_END_DATE] ?? null,
                        'post_training_join_standby_at' => $parsedRow->raw[HistoricalCrewImportColumns::POST_TRAINING_JOIN_STANDBY_DATE] ?? null,
                        'ready_to_join_at' => $parsedRow->raw[HistoricalCrewImportColumns::READY_TO_JOIN_DATE] ?? null,
                        'demob_standby_at' => $parsedRow->raw[HistoricalCrewImportColumns::DEMOB_STANDBY_DATE] ?? null,
                        'travel_home_at' => $parsedRow->raw[HistoricalCrewImportColumns::TRAVEL_HOME_DATE] ?? null,
                        'assignment_closed_at' => $parsedRow->raw[HistoricalCrewImportColumns::ASSIGNMENT_CLOSE_DATE] ?? null,
                        'remarks' => $parsedRow->remarks(),
                    ],
                    companyId: $companyId,
                    timezone: $timezone,
                    source: HistoricalCrewAssignmentData::SOURCE_IMPORT,
                );

                $domainResult = $this->validator->validate($data, $actor);
                $errors = array_merge($errors, $domainResult->errors);
                $warnings = array_merge($warnings, $domainResult->warnings);
            } catch (\InvalidArgumentException $exception) {
                $errors['dates'] = $exception->getMessage();
            }
        }

        $status = $this->classifyStatus($errors, $warnings);

        $intervalStart = null;
        $intervalEnd = null;

        if ($data !== null) {
            $intervalStart = $data->earliestActualStart()->toDateString();
            $intervalEnd = $data->latestActualEnd()->toDateString();
        } elseif ($parsedRow->vesselJoinDate() !== null && $parsedRow->disembarkDate() !== null) {
            $intervalStart = $parsedRow->vesselJoinDate();
            $intervalEnd = $parsedRow->disembarkDate();
        }

        return [
            'row_number' => $parsedRow->rowNumber,
            'status' => $status,
            'errors' => HistoricalCrewAssignmentErrorMapper::withFormAliases($errors),
            'warnings' => array_values(array_unique($warnings)),
            'raw' => $parsedRow->raw,
            'exact_key' => $parsedRow->exactDuplicateKey(),
            'employee_id' => $employee?->id,
            'employee_no' => $employee?->employee_no ?? $employeeNo,
            'employee_name' => $employee?->name,
            'vessel_id' => $vessel?->id,
            'vessel_name' => $vessel?->name ?? $vesselName,
            'rank_id' => $rank?->id,
            'rank_name' => $rank?->name ?? $rankName,
            'client_id' => $client?->id,
            'client_name' => $client?->name ?? $clientName,
            'joined_vessel_at' => $parsedRow->vesselJoinDate(),
            'disembarked_at' => $parsedRow->disembarkDate(),
            'interval_start' => $intervalStart,
            'interval_end' => $intervalEnd,
            'domain' => $domainResult,
            'workbook_messages' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $evaluated
     */
    private function applyWorkbookConflicts(array &$evaluated): void
    {
        $byExactKey = [];

        foreach ($evaluated as $index => $row) {
            $key = $row['exact_key'];
            $byExactKey[$key] ??= [];
            $byExactKey[$key][] = $index;
        }

        foreach ($byExactKey as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            $rowLabels = array_map(fn (int $i): string => (string) $evaluated[$i]['row_number'], $indexes);

            foreach ($indexes as $index) {
                $message = 'Exact duplicate of workbook row(s) '.implode(', ', array_filter(
                    $rowLabels,
                    fn (string $label): bool => $label !== (string) $evaluated[$index]['row_number'],
                )).'.';
                $evaluated[$index]['workbook_messages'][] = $message;
                $evaluated[$index]['errors']['workbook'] = $message;
                $evaluated[$index]['status'] = 'blocked';
            }
        }

        $byEmployee = [];

        foreach ($evaluated as $index => $row) {
            $employeeId = $row['employee_id'] ?? null;

            if ($employeeId === null || $row['interval_start'] === null || $row['interval_end'] === null) {
                continue;
            }

            $byEmployee[$employeeId] ??= [];
            $byEmployee[$employeeId][] = $index;
        }

        foreach ($byEmployee as $indexes) {
            $count = count($indexes);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $left = $evaluated[$indexes[$i]];
                    $right = $evaluated[$indexes[$j]];

                    if ($this->intervalsOverlap(
                        (string) $left['interval_start'],
                        (string) $left['interval_end'],
                        (string) $right['interval_start'],
                        (string) $right['interval_end'],
                    )) {
                        $leftMsg = "Overlaps workbook row {$right['row_number']} ({$right['interval_start']} → {$right['interval_end']}).";
                        $rightMsg = "Overlaps workbook row {$left['row_number']} ({$left['interval_start']} → {$left['interval_end']}).";

                        $evaluated[$indexes[$i]]['workbook_messages'][] = $leftMsg;
                        $evaluated[$indexes[$i]]['errors']['workbook'] = $leftMsg;
                        $evaluated[$indexes[$i]]['status'] = 'blocked';

                        $evaluated[$indexes[$j]]['workbook_messages'][] = $rightMsg;
                        $evaluated[$indexes[$j]]['errors']['workbook'] = $rightMsg;
                        $evaluated[$indexes[$j]]['status'] = 'blocked';
                    }
                }
            }
        }
    }

    private function intervalsOverlap(string $startA, string $endA, string $startB, string $endB): bool
    {
        $aStart = CarbonImmutable::parse($startA)->startOfDay();
        $aEnd = CarbonImmutable::parse($endA)->startOfDay();
        $bStart = CarbonImmutable::parse($startB)->startOfDay();
        $bEnd = CarbonImmutable::parse($endB)->startOfDay();

        return $aStart->lte($bEnd) && $bStart->lte($aEnd);
    }

    /**
     * @param  array<string, string>  $errors
     * @param  list<string>  $warnings
     */
    private function classifyStatus(array $errors, array $warnings): string
    {
        if ($errors !== []) {
            return 'blocked';
        }

        if ($warnings !== []) {
            return 'warning';
        }

        return 'ready';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function presentRow(array $row): array
    {
        /** @var HistoricalCrewAssignmentValidationResult|null $domain */
        $domain = $row['domain'];

        $errorMessages = array_values(array_unique(array_filter(array_values($row['errors']))));
        $workbookMessages = $row['workbook_messages'];

        return [
            'row' => $row['row_number'],
            'status' => $row['status'],
            'employee' => [
                'id' => $row['employee_id'],
                'employee_no' => $row['employee_no'],
                'name' => $row['employee_name'],
                'label' => $this->employeeLabel($row['employee_no'], $row['employee_name']),
            ],
            'vessel' => [
                'id' => $row['vessel_id'],
                'name' => $row['vessel_name'],
            ],
            'rank' => [
                'id' => $row['rank_id'],
                'name' => $row['rank_name'],
            ],
            'client' => $row['client_id'] !== null || $row['client_name'] !== null
                ? [
                    'id' => $row['client_id'],
                    'name' => $row['client_name'],
                ]
                : null,
            'joined_vessel_at' => $row['joined_vessel_at'],
            'disembarked_at' => $row['disembarked_at'],
            'errors' => $errorMessages,
            'error_fields' => $row['errors'],
            'warnings' => $row['warnings'],
            'workbook_messages' => $workbookMessages,
            'checks' => $domain?->checks ?? [],
            'timeline' => $domain?->timeline ?? [],
            'sea_service' => $domain?->seaService ?? null,
            'summary' => $domain?->summary ?? [
                'joined_vessel_at' => $row['joined_vessel_at'],
                'disembarked_at' => $row['disembarked_at'],
                'sea_service_days' => null,
                'remarks' => $row['raw'][HistoricalCrewImportColumns::REMARKS] ?? null,
            ],
            'conflicting_assignment' => $domain?->conflictingAssignment !== null
                ? [
                    'id' => (int) $domain->conflictingAssignment->id,
                    'assignment_no' => $domain->conflictingAssignment->assignment_no,
                    'started_at' => $domain->conflictingAssignment->started_at?->toDateString(),
                    'closed_at' => $domain->conflictingAssignment->closed_at?->toDateString(),
                ]
                : null,
        ];
    }

    private function employeeLabel(?string $employeeNo, ?string $name): string
    {
        if ($employeeNo !== null && $name !== null) {
            return "{$employeeNo} — {$name}";
        }

        return $employeeNo ?? $name ?? 'Unknown';
    }
}
