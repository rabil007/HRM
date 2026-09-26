<?php

namespace App\Support\CrewMovements\Historical;

use App\Models\Client;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Hotel;
use App\Models\Rank;
use App\Models\RoomType;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CrewMovements\SeaServiceSyncService;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Settings\CompanyTimezone;
use App\Support\Vessels\ResolvesCompanyVessels;
use Illuminate\Http\UploadedFile;

final class HistoricalCrewImportPreviewService
{
    public function __construct(
        private readonly HistoricalCrewImportParser $parser,
        private readonly HistoricalCrewAssignmentValidator $validator,
        private readonly SeaServiceSyncService $seaServiceSync,
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
        $result = $this->evaluateWorkbook($companyId, $file, $actor);

        return [
            'summary' => [
                'total' => $result['summary']['total'],
                'ready' => $result['summary']['ready'],
                'warning' => $result['summary']['warning'],
                'blocked' => $result['summary']['blocked'],
                'importable' => $result['summary']['importable'],
            ],
            'rows' => $result['rows'],
            'phase_note' => 'Ready and Warning rows can be imported after confirmation. Blocked rows will not be imported. Final import revalidates every row before writing.',
        ];
    }

    /**
     * Shared evaluation used by preview and final import.
     *
     * @return array{
     *     summary: array{total: int, ready: int, warning: int, blocked: int, importable: int},
     *     evaluated: list<array<string, mixed>>,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function evaluateWorkbook(int $companyId, UploadedFile $file, User $actor): array
    {
        $parsedRows = $this->parser->parse($file);
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $lookups = $this->buildLookups($companyId, $actor);
        $bulk = $this->buildBulkValidationContext($companyId, $lookups);

        $evaluated = [];

        foreach ($parsedRows as $parsedRow) {
            $evaluated[] = $this->evaluateRow($parsedRow, $companyId, $timezone, $actor, $lookups, $bulk);
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
                'importable' => $ready + $warning,
            ],
            'evaluated' => $evaluated,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     employeesByNo: array<string, Employee>,
     *     vesselsByName: array<string, list<Vessel>>,
     *     ranksByName: array<string, list<Rank>>,
     *     clientsByName: array<string, list<Client>>,
     *     hotelsByName: array<string, list<Hotel>>,
     *     roomTypesByHotelAndName: array<string, list<RoomType>>
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

        $hotelsByName = [];

        foreach (Hotel::query()->where('company_id', $companyId)->get(['id', 'name', 'is_active', 'company_id']) as $hotel) {
            $key = mb_strtolower(trim((string) $hotel->name));
            $hotelsByName[$key] ??= [];
            $hotelsByName[$key][] = $hotel;
        }

        $roomTypesByHotelAndName = [];

        foreach (RoomType::query()
            ->where('company_id', $companyId)
            ->whereNotNull('hotel_id')
            ->get(['id', 'name', 'hotel_id', 'company_id', 'is_active']) as $roomType) {
            $key = ((int) $roomType->hotel_id).'|'.mb_strtolower(trim((string) $roomType->name));
            $roomTypesByHotelAndName[$key] ??= [];
            $roomTypesByHotelAndName[$key][] = $roomType;
        }

        return [
            'employeesByNo' => $employeesByNo,
            'vesselsByName' => $vesselsByName,
            'ranksByName' => $ranksByName,
            'clientsByName' => $clientsByName,
            'hotelsByName' => $hotelsByName,
            'roomTypesByHotelAndName' => $roomTypesByHotelAndName,
        ];
    }

    /**
     * @param  array{
     *     employeesByNo: array<string, Employee>,
     *     vesselsByName: array<string, list<Vessel>>,
     *     ranksByName: array<string, list<Rank>>,
     *     clientsByName: array<string, list<Client>>
     * }  $lookups
     */
    private function buildBulkValidationContext(int $companyId, array $lookups): HistoricalCrewBulkValidationContext
    {
        $employeesById = [];
        foreach ($lookups['employeesByNo'] as $employee) {
            $employeesById[(int) $employee->id] = $employee;
        }

        $vesselsById = [];
        foreach ($lookups['vesselsByName'] as $matches) {
            foreach ($matches as $vessel) {
                $vesselsById[(int) $vessel->id] = $vessel;
            }
        }

        $ranksById = [];
        foreach ($lookups['ranksByName'] as $matches) {
            foreach ($matches as $rank) {
                $ranksById[(int) $rank->id] = $rank;
            }
        }

        $clientsById = [];
        foreach ($lookups['clientsByName'] as $matches) {
            foreach ($matches as $client) {
                $clientsById[(int) $client->id] = $client;
            }
        }

        foreach ($vesselsById as $vessel) {
            if ($vessel->client_id !== null && ! isset($clientsById[(int) $vessel->client_id])) {
                $client = Client::query()->find((int) $vessel->client_id);
                if ($client !== null) {
                    $clientsById[(int) $client->id] = $client;
                }
            }
        }

        $employeeIds = array_keys($employeesById);

        $assignmentsByEmployeeId = [];
        $seaServicesByEmployeeId = [];

        if ($employeeIds !== []) {
            $assignments = CrewAssignment::query()
                ->where('company_id', $companyId)
                ->whereIn('employee_id', $employeeIds)
                ->whereNull('voided_at')
                ->with(['phases'])
                ->get();

            foreach ($assignments as $assignment) {
                $assignmentsByEmployeeId[(int) $assignment->employee_id] ??= collect();
                $assignmentsByEmployeeId[(int) $assignment->employee_id]->push($assignment);
            }

            $seaServices = EmployeeSeaService::query()
                ->where('company_id', $companyId)
                ->whereIn('employee_id', $employeeIds)
                ->with(['vessel'])
                ->get();

            foreach ($seaServices as $seaService) {
                $seaServicesByEmployeeId[(int) $seaService->employee_id] ??= collect();
                $seaServicesByEmployeeId[(int) $seaService->employee_id]->push($seaService);
            }
        }

        return new HistoricalCrewBulkValidationContext(
            employeesById: $employeesById,
            vesselsById: $vesselsById,
            ranksById: $ranksById,
            clientsById: $clientsById,
            assignmentsByEmployeeId: $assignmentsByEmployeeId,
            seaServicesByEmployeeId: $seaServicesByEmployeeId,
            seaServiceSyncEnabled: $this->seaServiceSync->isEnabled($companyId),
        );
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
        HistoricalCrewBulkValidationContext $bulk,
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
                    default => "{$required} is required.",
                };
            }
        }

        $hasMovementDate = false;
        foreach (HistoricalCrewImportColumns::movementPeriodHeaders() as $dateHeader) {
            $value = $parsedRow->raw[$dateHeader] ?? null;
            if ($value !== null && $value !== '') {
                $hasMovementDate = true;
                break;
            }
        }

        if (! $hasMovementDate) {
            $resolveErrors['dates'] = 'At least one meaningful movement period must be supplied.';
        }

        $employee = null;
        $vessel = null;
        $rank = null;
        $client = null;

        $employeeNo = $parsedRow->employeeNo();

        if ($employeeNo !== null && ! isset($resolveErrors[HistoricalCrewImportColumns::EMPLOYEE_NO])) {
            $employee = $lookups['employeesByNo'][mb_strtolower($employeeNo)] ?? null;

            if ($employee === null) {
                $resolveErrors[HistoricalCrewImportColumns::EMPLOYEE_NO] =
                    "Employee number \"{$employeeNo}\" was not found or is unavailable.";
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
        }

        $errors = array_merge($errors, $resolveErrors);

        $domainResult = null;
        $data = null;

        $signOnHotelId = null;
        $signOnRoomTypeId = null;
        $signOffHotelId = null;
        $signOffRoomTypeId = null;

        if ($errors === [] && $employee !== null && $vessel !== null && $rank !== null) {
            [$signOnHotelId, $signOnRoomTypeId, $hotelErrors] = $this->resolveHotelSelection(
                lookups: $lookups,
                accommodationRaw: $parsedRow->raw[HistoricalCrewImportColumns::PRE_JOIN_ACCOMMODATION] ?? null,
                hotelName: $parsedRow->raw[HistoricalCrewImportColumns::PRE_JOIN_HOTEL] ?? null,
                roomTypeName: $parsedRow->raw[HistoricalCrewImportColumns::PRE_JOIN_ROOM_TYPE] ?? null,
                accommodationField: 'sign_on_accommodation',
                hotelField: 'sign_on_hotel_id',
                roomTypeField: 'sign_on_room_type_id',
            );
            $errors = array_merge($errors, $hotelErrors);

            [$signOffHotelId, $signOffRoomTypeId, $hotelErrors] = $this->resolveHotelSelection(
                lookups: $lookups,
                accommodationRaw: $parsedRow->raw[HistoricalCrewImportColumns::POST_SIGNOFF_ACCOMMODATION] ?? null,
                hotelName: $parsedRow->raw[HistoricalCrewImportColumns::POST_SIGNOFF_HOTEL] ?? null,
                roomTypeName: $parsedRow->raw[HistoricalCrewImportColumns::POST_SIGNOFF_ROOM_TYPE] ?? null,
                accommodationField: 'sign_off_accommodation',
                hotelField: 'sign_off_hotel_id',
                roomTypeField: 'sign_off_room_type_id',
            );
            $errors = array_merge($errors, $hotelErrors);
        }

        if ($errors === [] && $employee !== null && $vessel !== null && $rank !== null) {
            try {
                $data = HistoricalCrewAssignmentData::fromArray(
                    data: [
                        'employee_id' => (int) $employee->id,
                        'vessel_id' => (int) $vessel->id,
                        'rank_id' => (int) $rank->id,
                        'client_id' => $client?->id,
                        'sign_on_standby_from' => $parsedRow->raw[HistoricalCrewImportColumns::SIGN_ON_STANDBY_FROM] ?? null,
                        'sign_on_standby_to' => $parsedRow->raw[HistoricalCrewImportColumns::SIGN_ON_STANDBY_TO] ?? null,
                        'onsite_from' => $parsedRow->onsiteFrom(),
                        'onsite_to' => $parsedRow->onsiteTo(),
                        'sign_off_standby_from' => $parsedRow->raw[HistoricalCrewImportColumns::SIGN_OFF_STANDBY_FROM] ?? null,
                        'sign_off_standby_to' => $parsedRow->raw[HistoricalCrewImportColumns::SIGN_OFF_STANDBY_TO] ?? null,
                        'home_available_from' => $parsedRow->raw[HistoricalCrewImportColumns::HOME_AVAILABLE_FROM] ?? null,
                        'sign_on_accommodation' => HistoricalCrewAssignmentData::normalizeAccommodationChoice(
                            $parsedRow->raw[HistoricalCrewImportColumns::PRE_JOIN_ACCOMMODATION] ?? null,
                        ),
                        'sign_on_hotel_id' => $signOnHotelId,
                        'sign_on_room_type_id' => $signOnRoomTypeId,
                        'sign_on_hotel_check_in' => $parsedRow->raw[HistoricalCrewImportColumns::PRE_JOIN_HOTEL_CHECK_IN] ?? null,
                        'sign_on_hotel_check_out' => $parsedRow->raw[HistoricalCrewImportColumns::PRE_JOIN_HOTEL_CHECK_OUT] ?? null,
                        'sign_off_accommodation' => HistoricalCrewAssignmentData::normalizeAccommodationChoice(
                            $parsedRow->raw[HistoricalCrewImportColumns::POST_SIGNOFF_ACCOMMODATION] ?? null,
                        ),
                        'sign_off_hotel_id' => $signOffHotelId,
                        'sign_off_room_type_id' => $signOffRoomTypeId,
                        'sign_off_hotel_check_in' => $parsedRow->raw[HistoricalCrewImportColumns::POST_SIGNOFF_HOTEL_CHECK_IN] ?? null,
                        'sign_off_hotel_check_out' => $parsedRow->raw[HistoricalCrewImportColumns::POST_SIGNOFF_HOTEL_CHECK_OUT] ?? null,
                        'remarks' => $parsedRow->remarks(),
                    ],
                    companyId: $companyId,
                    timezone: $timezone,
                    source: HistoricalCrewAssignmentData::SOURCE_IMPORT,
                );

                $domainResult = $this->validator->validate($data, $actor, $bulk);
                $errors = array_merge($errors, $domainResult->errors);
                $warnings = array_merge($warnings, $domainResult->warnings);
            } catch (\InvalidArgumentException $exception) {
                $errors['dates'] = $exception->getMessage();
            }
        }

        $status = $this->classifyStatus($errors, $warnings);

        $intervalStart = null;
        $intervalEnd = null;
        $isOpen = false;
        $inferredState = null;
        $lastMovement = null;

        if ($data !== null && $domainResult?->valid) {
            $intervalStart = $data->earliestActualStart()->toDateString();
            $intervalEnd = $data->intervalEnd()?->toDateString();
            $isOpen = $domainResult->inferredState['is_open'] ?? false;
            $inferredState = $domainResult->inferredState;
            $lastMovement = $domainResult->lastMovement;
        } elseif ($data !== null) {
            try {
                $intervalStart = $data->earliestActualStart()->toDateString();
                $intervalEnd = $data->intervalEnd()?->toDateString();
                $reconstruction = $data->reconstruction();
                $isOpen = $reconstruction['is_open'];
            } catch (\InvalidArgumentException) {
                // Keep interval null when reconstruction is impossible.
            }
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
            'joined_vessel_at' => $parsedRow->onsiteFrom(),
            'disembarked_at' => $parsedRow->onsiteTo(),
            'onsite_from' => $parsedRow->onsiteFrom(),
            'onsite_to' => $parsedRow->onsiteTo(),
            'interval_start' => $intervalStart,
            'interval_end' => $intervalEnd,
            'is_open' => $isOpen,
            'inferred_state' => $inferredState,
            'last_movement' => $lastMovement,
            'domain' => $domainResult,
            'data' => $data,
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

            if ($employeeId === null || $row['interval_start'] === null) {
                continue;
            }

            $byEmployee[$employeeId] ??= [];
            $byEmployee[$employeeId][] = $index;
        }

        foreach ($byEmployee as $indexes) {
            usort($indexes, function (int $a, int $b) use ($evaluated): int {
                $startCmp = strcmp((string) $evaluated[$a]['interval_start'], (string) $evaluated[$b]['interval_start']);

                if ($startCmp !== 0) {
                    return $startCmp;
                }

                return $evaluated[$a]['row_number'] <=> $evaluated[$b]['row_number'];
            });

            $count = count($indexes);
            $openIndexes = [];

            for ($i = 0; $i < $count; $i++) {
                if ($evaluated[$indexes[$i]]['is_open'] ?? false) {
                    $openIndexes[] = $indexes[$i];
                }
            }

            if (count($openIndexes) > 1) {
                foreach ($openIndexes as $openIndex) {
                    $others = array_map(
                        fn (int $idx): string => (string) $evaluated[$idx]['row_number'],
                        array_values(array_filter($openIndexes, fn (int $idx): bool => $idx !== $openIndex)),
                    );
                    $message = 'Multiple open assignments for the same employee in this workbook (rows '
                        .implode(', ', $others)
                        .'). At most one open/current assignment is allowed per employee.';
                    $evaluated[$openIndex]['workbook_messages'][] = $message;
                    $evaluated[$openIndex]['errors']['workbook'] = $message;
                    $evaluated[$openIndex]['status'] = 'blocked';
                }
            }

            foreach ($indexes as $position => $index) {
                if (! ($evaluated[$index]['is_open'] ?? false)) {
                    continue;
                }

                $laterIndexes = array_slice($indexes, $position + 1);

                if ($laterIndexes === []) {
                    continue;
                }

                $laterRows = array_map(
                    fn (int $idx): string => (string) $evaluated[$idx]['row_number'],
                    $laterIndexes,
                );
                $message = sprintf(
                    'Row %d remains open at %s, but Row %s contains a later assignment for the same employee. Complete Row %d\'s later movements before importing Row %s.',
                    $evaluated[$index]['row_number'],
                    $evaluated[$index]['inferred_state']['label'] ?? 'an open phase',
                    $laterRows[0],
                    $evaluated[$index]['row_number'],
                    $laterRows[0],
                );
                $evaluated[$index]['workbook_messages'][] = $message;
                $evaluated[$index]['errors']['workbook'] = $message;
                $evaluated[$index]['status'] = 'blocked';
            }

            for ($i = 0; $i < $count; $i++) {
                $left = $evaluated[$indexes[$i]];

                for ($j = $i + 1; $j < $count; $j++) {
                    $right = $evaluated[$indexes[$j]];

                    $leftEnd = $left['interval_end'];
                    $rightEnd = $right['interval_end'];

                    if ($leftEnd !== null && (string) $right['interval_start'] >= (string) $leftEnd) {
                        break;
                    }

                    if (HistoricalAssignmentIntervalOverlap::dateStringsOverlap(
                        (string) $left['interval_start'],
                        $leftEnd !== null ? (string) $leftEnd : null,
                        (string) $right['interval_start'],
                        $rightEnd !== null ? (string) $rightEnd : null,
                    )) {
                        $leftEndLabel = $leftEnd ?? 'Current';
                        $rightEndLabel = $rightEnd ?? 'Current';
                        $leftMsg = "Overlaps workbook row {$right['row_number']} ({$right['interval_start']} → {$rightEndLabel}).";
                        $rightMsg = "Overlaps workbook row {$left['row_number']} ({$left['interval_start']} → {$leftEndLabel}).";

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

    /**
     * @param  array{
     *     hotelsByName: array<string, list<Hotel>>,
     *     roomTypesByHotelAndName: array<string, list<RoomType>>
     * }  $lookups
     * @return array{0: ?int, 1: ?int, 2: array<string, string>}
     */
    private function resolveHotelSelection(
        array $lookups,
        mixed $accommodationRaw,
        mixed $hotelName,
        mixed $roomTypeName,
        string $accommodationField,
        string $hotelField,
        string $roomTypeField,
    ): array {
        $errors = [];

        try {
            $choice = HistoricalCrewAssignmentData::normalizeAccommodationChoice($accommodationRaw);
        } catch (\InvalidArgumentException $exception) {
            $errors[$accommodationField] = $exception->getMessage();

            return [null, null, $errors];
        }

        if ($choice !== HistoricalCrewAssignmentData::ACCOMMODATION_HOTEL) {
            return [null, null, $errors];
        }

        $hotelId = null;
        $roomTypeId = null;
        $hotelLabel = is_string($hotelName) ? trim($hotelName) : '';

        if ($hotelLabel === '') {
            $errors[$hotelField] = 'Hotel is required when Accommodation is Hotel.';
        } else {
            $matches = $lookups['hotelsByName'][mb_strtolower($hotelLabel)] ?? [];

            if ($matches === []) {
                $errors[$hotelField] = "Hotel \"{$hotelLabel}\" was not found in the active company.";
            } elseif (count($matches) > 1) {
                $ids = collect($matches)->map(fn (Hotel $hotel) => '#'.$hotel->id)->implode(', ');
                $errors[$hotelField] = "Hotel \"{$hotelLabel}\" is ambiguous ({$ids}).";
            } else {
                $hotelId = (int) $matches[0]->id;
            }
        }

        $roomLabel = is_string($roomTypeName) ? trim($roomTypeName) : '';

        if ($roomLabel !== '' && $hotelId !== null) {
            $key = $hotelId.'|'.mb_strtolower($roomLabel);
            $matches = $lookups['roomTypesByHotelAndName'][$key] ?? [];

            if ($matches === []) {
                $errors[$roomTypeField] = "Room type \"{$roomLabel}\" was not found for the selected hotel.";
            } elseif (count($matches) > 1) {
                $ids = collect($matches)->map(fn (RoomType $roomType) => '#'.$roomType->id)->implode(', ');
                $errors[$roomTypeField] = "Room type \"{$roomLabel}\" is ambiguous ({$ids}).";
            } else {
                $roomTypeId = (int) $matches[0]->id;
            }
        }

        return [$hotelId, $roomTypeId, $errors];
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
            'last_movement' => $row['last_movement'],
            'inferred_state' => $row['inferred_state'] ?? $domain?->inferredState,
            'is_open' => $row['is_open'],
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
