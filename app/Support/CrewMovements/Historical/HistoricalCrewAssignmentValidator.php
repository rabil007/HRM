<?php

namespace App\Support\CrewMovements\Historical;

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Models\Client;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Hotel;
use App\Models\Rank;
use App\Models\RoomType;
use App\Models\User;
use App\Support\CrewMovements\SeaServiceSyncService;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\MasterData\ClientAssignmentRules;
use Carbon\Carbon;
use Carbon\CarbonInterface;

final class HistoricalCrewAssignmentValidator
{
    public const ACTIVE_ASSIGNMENT_CONFLICT_MESSAGE = 'This employee already has an active Crew Assignment in OMS-HRM. Historical records before the active assignment may still be imported, but this row cannot become another current assignment.';

    public const OPEN_BOOTSTRAP_REQUIRES_ACTIVE_EMPLOYEE_MESSAGE = 'This historical record would become the employee\'s current active Crew Assignment, but the employee is not currently Active. Complete the historical movement through Home / Available, or reactivate the employee before using this record to establish their current Crew state.';

    public function __construct(
        private readonly SeaServiceSyncService $seaServiceSync,
        private readonly HistoricalSeaServiceMatchResolver $seaServiceMatchResolver,
    ) {}

    public function validate(
        HistoricalCrewAssignmentData $data,
        ?User $actor = null,
        ?HistoricalCrewBulkValidationContext $bulk = null,
    ): HistoricalCrewAssignmentValidationResult {
        $timezone = $data->timezone;
        $errors = [];
        $warnings = [];
        $checks = [];

        $employee = $bulk?->employee($data->employeeId)
            ?? Employee::query()
                ->where('company_id', $data->companyId)
                ->whereNull('deleted_at')
                ->find($data->employeeId);

        $employeeValid = true;
        $employeeMessage = null;

        if ($employee === null) {
            $employeeValid = false;
            $employeeMessage = 'Employee not found in the active company.';
            $errors['employee_id'] = $employeeMessage;
        } elseif ($bulk === null && $actor !== null && ! EmployeeVisibilityScope::canAccess($actor, $employee, $data->companyId)) {
            $employeeValid = false;
            $employeeMessage = 'You do not have permission to view or manage this employee.';
            $errors['employee_id'] = $employeeMessage;
        } else {
            $employeeMessage = 'Employee belongs to active company and is visible to your role.';
        }

        $checks[] = [
            'code' => 'employee',
            'passed' => $employeeValid,
            'message' => $employeeMessage,
        ];

        $vessel = $bulk?->vessel($data->vesselId)
            ?? ClientAssignmentRules::findCompanyVessel($data->companyId, $data->vesselId);

        $vesselValid = true;
        $vesselMessage = null;

        if ($vessel === null) {
            $vesselValid = false;
            $vesselMessage = 'Vessel not found in the active company.';
            $errors['vessel_id'] = $vesselMessage;
        } else {
            $vesselMessage = 'Vessel belongs to active company.';
            if (! $vessel->is_active) {
                $warnings[] = "Vessel '{$vessel->name}' is currently inactive in master data, but recorded for historical service.";
            }
        }

        $checks[] = [
            'code' => 'vessel',
            'passed' => $vesselValid,
            'message' => $vesselMessage,
        ];

        $rank = $bulk?->rank($data->rankId) ?? Rank::query()->find($data->rankId);
        $rankValid = true;
        $rankMessage = null;

        if ($rank === null) {
            $rankValid = false;
            $rankMessage = 'The selected rank is invalid.';
            $errors['rank_id'] = $rankMessage;
        } else {
            $rankMessage = 'Rank is valid.';
            if (! $rank->is_active) {
                $warnings[] = "Rank '{$rank->name}' is currently inactive in master data.";
            }
        }

        $checks[] = [
            'code' => 'rank',
            'passed' => $rankValid,
            'message' => $rankMessage,
        ];

        $client = null;
        if ($data->clientId !== null) {
            $client = $bulk?->client($data->clientId) ?? Client::query()->find($data->clientId);
            if ($client === null) {
                $errors['client_id'] = 'The selected client is invalid.';
            } elseif ($vessel !== null && $vessel->client_id !== null && (int) $vessel->client_id !== $data->clientId) {
                $currentClient = $bulk?->client((int) $vessel->client_id)
                    ?? Client::query()->find((int) $vessel->client_id);
                $currentName = $currentClient?->name ?? '#'.$vessel->client_id;
                $warnings[] = "Historical Client \"{$client->name}\" differs from the Vessel's current Client \"{$currentName}\". The historical Client snapshot will be preserved.";
            }
        }

        if ($client !== null && ! $client->is_active) {
            $warnings[] = "Client '{$client->name}' is currently inactive in master data.";
        }

        $datesValid = true;
        $datesMessage = null;
        $now = Carbon::now($timezone);
        $reconstruction = null;

        if (! $data->hasAnyMovementPeriod()) {
            $datesValid = false;
            $errors['dates'] = 'At least one meaningful movement period must be supplied.';
        }

        $allSuppliedTimestamps = [
            'sign_on_standby_from' => $data->signOnStandbyFrom,
            'sign_on_standby_to' => $data->signOnStandbyTo,
            'onsite_from' => $data->onsiteFrom,
            'onsite_to' => $data->onsiteTo,
            'sign_off_standby_from' => $data->signOffStandbyFrom,
            'sign_off_standby_to' => $data->signOffStandbyTo,
            'home_available_from' => $data->homeAvailableFrom,
            'sign_on_hotel_check_in' => $data->signOnHotelCheckIn,
            'sign_on_hotel_check_out' => $data->signOnHotelCheckOut,
            'sign_off_hotel_check_in' => $data->signOffHotelCheckIn,
            'sign_off_hotel_check_out' => $data->signOffHotelCheckOut,
        ];

        foreach ($allSuppliedTimestamps as $field => $ts) {
            if ($ts !== null && $ts->gt($now)) {
                $datesValid = false;
                $errors[$field] = 'Actual historical dates cannot be in the future.';
            }
        }

        $this->validatePeriodBounds($data, $errors, $datesValid);
        $this->validatePeriodSequence($data, $errors, $datesValid);
        $this->validateOpenCurrentState($data, $errors, $datesValid);
        $this->validateAccommodation($data, $errors, $warnings);

        if ($datesValid && $data->hasAnyMovementPeriod() && empty(array_intersect_key($errors, array_flip([
            'dates',
            'sign_on_standby_from',
            'sign_on_standby_to',
            'onsite_from',
            'onsite_to',
            'sign_off_standby_from',
            'sign_off_standby_to',
            'home_available_from',
        ])))) {
            try {
                $reconstruction = $data->reconstruction();
            } catch (\InvalidArgumentException $exception) {
                $datesValid = false;
                $errors['dates'] = $exception->getMessage();
            }
        }

        if (! $datesValid && $datesMessage === null) {
            $datesMessage = 'One or more movement periods are chronologically invalid, overlapping, or leave the current state ambiguous.';
        }

        $checks[] = [
            'code' => 'dates',
            'passed' => $datesValid && ! isset($errors['dates']),
            'message' => $datesMessage ?? 'Dates and chronological sequence are valid.',
        ];

        $isOpenBootstrap = $reconstruction['is_open'] ?? false;
        $newStart = null;
        $newEnd = null;

        if ($reconstruction !== null) {
            $newStart = $data->earliestActualStart();
            $newEnd = $data->intervalEnd();
        }

        if ($employee !== null && $employee->status !== 'active') {
            $statusLabel = str_replace('_', ' ', (string) $employee->status);

            if ($isOpenBootstrap) {
                $errors['assignment'] = self::OPEN_BOOTSTRAP_REQUIRES_ACTIVE_EMPLOYEE_MESSAGE;
            } else {
                $warnings[] = "Employee status is currently '{$statusLabel}'. Completed historical recording is still allowed.";
            }
        }

        $activeAssignment = null;

        if ($employee !== null) {
            if ($bulk !== null) {
                $activeAssignment = $bulk->assignmentsForEmployee($data->employeeId)
                    ->first(fn (CrewAssignment $assignment): bool => $assignment->status === CrewAssignmentStatus::Active
                        && $assignment->voided_at === null);
            } else {
                $activeAssignment = CrewAssignment::query()
                    ->where('company_id', $data->companyId)
                    ->where('employee_id', $data->employeeId)
                    ->where('status', CrewAssignmentStatus::Active)
                    ->whereNull('voided_at')
                    ->first();
            }
        }

        if ($isOpenBootstrap && $activeAssignment !== null && ! isset($errors['assignment'])) {
            $errors['assignment'] = self::ACTIVE_ASSIGNMENT_CONFLICT_MESSAGE;
        }

        $noConflict = true;
        $conflictMessage = null;
        $conflictingAssignment = null;

        if ($employee !== null && $newStart !== null && ! isset($errors['assignment'])) {
            $existingAssignments = $bulk?->assignmentsForEmployee($data->employeeId)
                ?? CrewAssignment::query()
                    ->where('company_id', $data->companyId)
                    ->where('employee_id', $data->employeeId)
                    ->whereNull('voided_at')
                    ->with(['phases'])
                    ->get();

            foreach ($existingAssignments as $existing) {
                $existingStart = $existing->started_at ?? $existing->phases->whereNotNull('actual_start_at')->min('actual_start_at');

                if ($existingStart === null) {
                    continue;
                }

                $existingEnd = $existing->status === CrewAssignmentStatus::Active
                    ? null
                    : ($existing->closed_at ?? $existing->phases->whereNotNull('actual_end_at')->max('actual_end_at') ?? $existingStart);

                if (HistoricalAssignmentIntervalOverlap::intervalsOverlap($newStart, $newEnd, $existingStart, $existingEnd)) {
                    $noConflict = false;
                    $conflictingAssignment = $existing;
                    $overlapStart = $newStart->gt($existingStart) ? $newStart : $existingStart;
                    $overlapEndLabel = $newEnd !== null && $existingEnd !== null
                        ? ($newEnd->lt($existingEnd) ? $newEnd : $existingEnd)->copy()->timezone($timezone)->format('d M Y')
                        : 'Current';
                    $conflictMessage = sprintf(
                        'Overlaps %s (%s -> %s)',
                        $existing->assignment_no,
                        $overlapStart->copy()->timezone($timezone)->format('d M Y'),
                        $overlapEndLabel,
                    );
                    $errors['overlap'] = $conflictMessage;
                    break;
                }
            }
        }

        $checks[] = [
            'code' => 'no_conflict',
            'passed' => $noConflict && ! isset($errors['assignment']),
            'message' => $errors['assignment'] ?? $conflictMessage ?? 'No conflicting assignment found.',
        ];

        if (isset($errors['assignment'])) {
            $currentIsolatedMessage = $errors['assignment'];
        } elseif ($isOpenBootstrap && $activeAssignment === null) {
            $currentIsolatedMessage = 'No conflicting active OMS assignment exists; this row may bootstrap the current operational state.';
        } elseif ($activeAssignment !== null && ! $isOpenBootstrap) {
            $currentIsolatedMessage = "Current active assignment {$activeAssignment->assignment_no} will remain untouched.";
        } else {
            $currentIsolatedMessage = 'No active operational assignment exists.';
        }

        $checks[] = [
            'code' => 'current_isolated',
            'passed' => ! isset($errors['assignment']),
            'message' => $currentIsolatedMessage,
        ];

        $seaDuration = $data->seaServiceDuration();
        $seaStartDate = $data->joinedVesselAt()?->toDateString();
        $seaEndDate = $data->disembarkedAt()?->toDateString();
        $vesselName = $vessel?->name ?? 'Unknown Vessel';
        $syncEnabled = $bulk?->seaServiceSyncEnabled ?? $this->seaServiceSync->isEnabled($data->companyId);

        $seaServiceImpact = [
            'status' => 'not_applicable',
            'days' => 0,
            'months' => 0,
            'start_date' => $seaStartDate,
            'end_date' => $seaEndDate,
            'vessel_id' => $data->vesselId,
            'vessel_name' => $vesselName,
            'existing_id' => null,
            'message' => 'Sea Service is created only for a completed Onsite / On Vessel period.',
        ];

        if ($data->hasCompletedSeaServicePeriod() && $seaStartDate !== null && $seaEndDate !== null) {
            $seaServiceImpact = [
                'status' => 'will_create',
                'days' => $seaDuration['days'],
                'months' => $seaDuration['months'],
                'start_date' => $seaStartDate,
                'end_date' => $seaEndDate,
                'vessel_id' => $data->vesselId,
                'vessel_name' => $vesselName,
                'existing_id' => null,
                'message' => "{$seaDuration['days']} days will be recorded/synchronized to Sea Service.",
            ];

            if (! $syncEnabled) {
                $seaServiceImpact['status'] = 'disabled';
                $seaServiceImpact['message'] = 'Sea Service synchronization is disabled in company settings. No sea service record will be created.';
            } elseif ($employee !== null) {
                $existingSeaServices = $bulk?->seaServicesForEmployee($data->employeeId)
                    ?? EmployeeSeaService::query()
                        ->where('company_id', $data->companyId)
                        ->where('employee_id', $data->employeeId)
                        ->with(['vessel'])
                        ->get();

                $exactMatch = $this->seaServiceMatchResolver->resolveExactMatch(
                    data: $data,
                    seaStartDate: $seaStartDate,
                    seaEndDate: $seaEndDate,
                    seaDays: $seaDuration['days'],
                    proposedRankName: $rank?->name,
                    existingForEmployee: $existingSeaServices,
                );

                $seaServiceImpact['status'] = $exactMatch['status'];
                $seaServiceImpact['existing_id'] = $exactMatch['existing_id'];
                $seaServiceImpact['message'] = $exactMatch['message'];

                if ($exactMatch['error'] !== null) {
                    $errors['sea_service'] = $exactMatch['error'];
                } else {
                    foreach ($existingSeaServices as $record) {
                        $recordStart = $record->start_date?->toDateString();
                        $recordEnd = $record->end_date?->toDateString();

                        if ($recordStart === null || $recordEnd === null) {
                            continue;
                        }

                        if ((int) $record->vessel_id === $data->vesselId
                            && $recordStart === $seaStartDate
                            && $recordEnd === $seaEndDate) {
                            continue;
                        }

                        if ($recordStart <= $seaEndDate && $seaStartDate <= $recordEnd) {
                            $conflictVesselName = $record->vessel?->name ?? 'another vessel';
                            $recStartFormatted = Carbon::parse($recordStart)->format('d M Y');
                            $recEndFormatted = Carbon::parse($recordEnd)->format('d M Y');
                            $errMsg = sprintf(
                                'Overlaps existing Sea Service record #%d (%s, %s -> %s).',
                                $record->id,
                                $conflictVesselName,
                                $recStartFormatted,
                                $recEndFormatted,
                            );
                            $errors['sea_service'] = $errMsg;
                            $seaServiceImpact['status'] = 'conflict';
                            $seaServiceImpact['message'] = $errMsg;
                            break;
                        }
                    }
                }
            }
        }

        $inferredState = null;
        $lastMovement = null;
        $knownPeriods = [];
        $accommodationSummary = [];

        if ($reconstruction !== null) {
            $inferred = $reconstruction['inferred_state'];
            $last = $reconstruction['last_movement'];
            $inferredState = [
                'phase_code' => $inferred['phase_code']->value,
                'label' => $inferred['label'],
                'event_key' => $inferred['event_key'],
                'event_label' => $inferred['event_label'],
                'event_at' => $inferred['event_at']->copy()->timezone($timezone)->format('d M Y'),
                'assignment_status' => $reconstruction['assignment_status']->value,
                'is_open' => $reconstruction['is_open'],
            ];
            $lastMovement = [
                'event_key' => $last['event_key'],
                'event_label' => $last['event_label'],
                'event_at' => $last['event_at']->copy()->timezone($timezone)->format('d M Y'),
                'display' => $last['event_label'].' — '.$last['event_at']->copy()->timezone($timezone)->format('d M Y'),
            ];
            $knownPeriods = $reconstruction['known_periods'];
            $accommodationSummary = $this->accommodationPreviewSummary($data);
        }

        $summary = [
            'onsite_from' => $data->onsiteFrom?->copy()->timezone($timezone)->format('d M Y'),
            'onsite_to' => $data->onsiteTo?->copy()->timezone($timezone)->format('d M Y'),
            'joined_vessel_at' => $data->onsiteFrom?->copy()->timezone($timezone)->format('d M Y'),
            'disembarked_at' => $data->onsiteTo?->copy()->timezone($timezone)->format('d M Y'),
            'sea_service_days' => $data->hasCompletedSeaServicePeriod() ? $seaDuration['days'] : null,
            'remarks' => $data->remarks,
            'assignment_status' => $reconstruction['assignment_status']->value ?? null,
            'is_open' => $reconstruction['is_open'] ?? null,
            'known_periods' => $knownPeriods,
            'accommodation' => $accommodationSummary,
        ];

        $timeline = [];
        if ($reconstruction !== null) {
            foreach ($reconstruction['phases'] as $phase) {
                $phaseStart = $phase['actual_start_at']->copy()->timezone($timezone);
                $phaseEnd = $phase['actual_end_at']?->copy()->timezone($timezone);
                $duration = null;

                if ($phase['phase_code'] === CrewPhaseCode::OnVessel && $phaseEnd !== null) {
                    $duration = $seaDuration['days'];
                } elseif ($phaseEnd !== null) {
                    $duration = HistoricalCrewAssignmentData::inclusiveDays($phase['actual_start_at'], $phase['actual_end_at']);
                }

                $timeline[] = [
                    'phase_code' => $phase['phase_code']->value,
                    'phase_label' => $phase['phase_code']->label(),
                    'start' => $phaseStart->format('d M Y'),
                    'end' => $phaseEnd?->format('d M Y'),
                    'end_display' => $phaseEnd !== null ? $phaseEnd->format('d M Y') : 'Current',
                    'is_open' => $phase['actual_end_at'] === null,
                    'duration_days' => $duration,
                ];
            }
        }

        $isValid = empty($errors);
        $errors = HistoricalCrewAssignmentErrorMapper::withFormAliases($errors);

        return new HistoricalCrewAssignmentValidationResult(
            valid: $isValid,
            errors: $errors,
            warnings: $warnings,
            checks: $checks,
            employee: $employee !== null ? [
                'id' => (int) $employee->id,
                'name' => (string) $employee->name,
                'employee_no' => $employee->employee_no,
            ] : ['id' => $data->employeeId, 'name' => 'Unknown', 'employee_no' => null],
            vessel: $vessel !== null ? [
                'id' => (int) $vessel->id,
                'name' => (string) $vessel->name,
            ] : ['id' => $data->vesselId, 'name' => 'Unknown'],
            rank: $rank !== null ? [
                'id' => (int) $rank->id,
                'name' => (string) $rank->name,
            ] : ['id' => $data->rankId, 'name' => 'Unknown'],
            client: $client !== null ? [
                'id' => (int) $client->id,
                'name' => (string) $client->name,
            ] : null,
            summary: $summary,
            timeline: $timeline,
            seaService: $seaServiceImpact,
            conflictingAssignment: $conflictingAssignment,
            inferredState: $inferredState,
            lastMovement: $lastMovement,
        );
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function validatePeriodBounds(HistoricalCrewAssignmentData $data, array &$errors, bool &$datesValid): void
    {
        $pairs = [
            ['from' => $data->signOnStandbyFrom, 'to' => $data->signOnStandbyTo, 'from_field' => 'sign_on_standby_from', 'to_field' => 'sign_on_standby_to', 'label' => 'Sign-On Standby'],
            ['from' => $data->onsiteFrom, 'to' => $data->onsiteTo, 'from_field' => 'onsite_from', 'to_field' => 'onsite_to', 'label' => 'Onsite / On Vessel'],
            ['from' => $data->signOffStandbyFrom, 'to' => $data->signOffStandbyTo, 'from_field' => 'sign_off_standby_from', 'to_field' => 'sign_off_standby_to', 'label' => 'Sign-Off Standby'],
        ];

        foreach ($pairs as $pair) {
            if ($pair['to'] !== null && $pair['from'] === null) {
                $datesValid = false;
                $errors[$pair['from_field']] = "{$pair['label']} From is required when To is provided.";
            }

            if ($pair['from'] !== null && $pair['to'] !== null && $pair['from']->gt($pair['to'])) {
                $datesValid = false;
                $errors[$pair['to_field']] = "{$pair['label']} From must not be after To.";
            }
        }

        if ($data->signOnStandbyTo !== null && $data->signOnStandbyFrom === null) {
            // already covered
        }

        if ($data->signOnAccommodation !== HistoricalCrewAssignmentData::ACCOMMODATION_NOT_RECORDED
            && $data->signOnStandbyFrom === null) {
            $errors['sign_on_accommodation'] = 'Sign-On Standby period is required when accommodation is recorded.';
        }

        if ($data->signOffAccommodation !== HistoricalCrewAssignmentData::ACCOMMODATION_NOT_RECORDED
            && $data->signOffStandbyFrom === null) {
            $errors['sign_off_accommodation'] = 'Sign-Off Standby period is required when accommodation is recorded.';
        }
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function validatePeriodSequence(HistoricalCrewAssignmentData $data, array &$errors, bool &$datesValid): void
    {
        $ordered = $data->enteredOperationalPeriods();

        for ($i = 0; $i < count($ordered) - 1; $i++) {
            $left = $ordered[$i];
            $right = $ordered[$i + 1];

            if ($left['from']->gt($right['from'])) {
                $datesValid = false;
                $errors[$right['key'].'_from'] = "{$right['label']} cannot start before {$left['label']}.";
            }

            if ($left['to'] !== null && $left['to']->gt($right['from'])) {
                $datesValid = false;
                $errors[$left['key'] === 'sign_on_standby' ? 'sign_on_standby_to' : ($left['key'] === 'onsite' ? 'onsite_to' : 'sign_off_standby_to')]
                    = "{$left['label']} overlaps {$right['label']}. Periods may meet on the same date, but must not improperly overlap.";
            }
        }

        if ($data->homeAvailableFrom !== null) {
            foreach ($ordered as $period) {
                if ($period['from']->gt($data->homeAvailableFrom)) {
                    $datesValid = false;
                    $errors['home_available_from'] = 'Home / Available From cannot precede known movement periods.';
                }

                if ($period['to'] !== null && $period['to']->gt($data->homeAvailableFrom)) {
                    $datesValid = false;
                    $errors['home_available_from'] = 'Home / Available From cannot precede known movement periods.';
                }
            }
        }
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function validateOpenCurrentState(HistoricalCrewAssignmentData $data, array &$errors, bool &$datesValid): void
    {
        $periods = $data->enteredOperationalPeriods();

        if ($periods === [] && $data->homeAvailableFrom === null) {
            return;
        }

        $openIndexes = [];

        foreach ($periods as $index => $period) {
            if ($period['to'] === null) {
                $openIndexes[] = $index;
            }
        }

        if ($data->homeAvailableFrom !== null) {
            if ($openIndexes !== []) {
                $datesValid = false;
                $errors['dates'] = 'Close every movement period before Home / Available From, or leave Home empty for an open current period.';
            }

            return;
        }

        if ($openIndexes === []) {
            $datesValid = false;
            $errors['dates'] = HistoricalCrewAssignmentData::AMBIGUOUS_CURRENT_STATE_MESSAGE;

            return;
        }

        if (count($openIndexes) > 1) {
            $datesValid = false;
            $errors['dates'] = 'Only the latest chronological movement period may remain open.';

            return;
        }

        $openIndex = $openIndexes[0];
        $lastIndex = count($periods) - 1;

        if ($openIndex !== $lastIndex) {
            $datesValid = false;
            $errors['dates'] = 'Only the latest chronological movement period may remain open.';
        }
    }

    /**
     * @param  array<string, string>  $errors
     * @param  list<string>  $warnings
     */
    private function validateAccommodation(HistoricalCrewAssignmentData $data, array &$errors, array &$warnings): void
    {
        $this->validateStandbyAccommodation(
            choice: $data->signOnAccommodation,
            standbyFrom: $data->signOnStandbyFrom,
            standbyTo: $data->signOnStandbyTo,
            hotelId: $data->signOnHotelId,
            roomTypeId: $data->signOnRoomTypeId,
            checkIn: $data->signOnHotelCheckIn,
            checkOut: $data->signOnHotelCheckOut,
            prefix: 'sign_on',
            label: 'Sign-On Standby',
            companyId: $data->companyId,
            errors: $errors,
            warnings: $warnings,
        );

        $this->validateStandbyAccommodation(
            choice: $data->signOffAccommodation,
            standbyFrom: $data->signOffStandbyFrom,
            standbyTo: $data->signOffStandbyTo,
            hotelId: $data->signOffHotelId,
            roomTypeId: $data->signOffRoomTypeId,
            checkIn: $data->signOffHotelCheckIn,
            checkOut: $data->signOffHotelCheckOut,
            prefix: 'sign_off',
            label: 'Sign-Off Standby',
            companyId: $data->companyId,
            errors: $errors,
            warnings: $warnings,
        );
    }

    /**
     * @param  array<string, string>  $errors
     * @param  list<string>  $warnings
     */
    private function validateStandbyAccommodation(
        string $choice,
        ?CarbonInterface $standbyFrom,
        ?CarbonInterface $standbyTo,
        ?int $hotelId,
        ?int $roomTypeId,
        ?CarbonInterface $checkIn,
        ?CarbonInterface $checkOut,
        string $prefix,
        string $label,
        int $companyId,
        array &$errors,
        array &$warnings,
    ): void {
        if ($choice === HistoricalCrewAssignmentData::ACCOMMODATION_NOT_RECORDED) {
            if ($hotelId !== null || $roomTypeId !== null || $checkIn !== null || $checkOut !== null) {
                $errors[$prefix.'_accommodation'] = "{$label} hotel fields must be empty when Accommodation is Not recorded.";
            }

            return;
        }

        if ($choice === HistoricalCrewAssignmentData::ACCOMMODATION_NO_ACCOMMODATION) {
            if ($hotelId !== null || $roomTypeId !== null || $checkIn !== null || $checkOut !== null) {
                $errors[$prefix.'_accommodation'] = "{$label} hotel fields must be empty when Accommodation is No accommodation.";
            }

            return;
        }

        if ($hotelId === null) {
            $errors[$prefix.'_hotel_id'] = 'Hotel is required when Accommodation is Hotel.';
        } else {
            $hotel = Hotel::query()
                ->where('company_id', $companyId)
                ->whereKey($hotelId)
                ->first();

            if ($hotel === null) {
                $errors[$prefix.'_hotel_id'] = 'Hotel must belong to the current company.';
            } elseif (! $hotel->is_active) {
                $warnings[] = "{$label} hotel '{$hotel->name}' is currently inactive in master data.";
            }
        }

        if ($roomTypeId !== null) {
            $roomType = RoomType::query()
                ->where('company_id', $companyId)
                ->whereKey($roomTypeId)
                ->first();

            if ($roomType === null) {
                $errors[$prefix.'_room_type_id'] = 'Room type must belong to the current company.';
            } elseif ($hotelId !== null && $roomType->hotel_id !== null && (int) $roomType->hotel_id !== $hotelId) {
                $errors[$prefix.'_room_type_id'] = 'Room type must belong to the selected hotel.';
            }
        }

        $effectiveCheckIn = $checkIn ?? $standbyFrom;

        if ($effectiveCheckIn === null) {
            $errors[$prefix.'_hotel_check_in'] = 'Hotel check-in is required when Accommodation is Hotel.';
        }

        $standbyIsOpen = $standbyFrom !== null && $standbyTo === null;
        $effectiveCheckOut = $checkOut ?? ($standbyIsOpen ? null : $standbyTo);

        if (! $standbyIsOpen && $effectiveCheckOut === null) {
            $errors[$prefix.'_hotel_check_out'] = 'Hotel check-out is required for a closed standby period.';
        }

        if ($effectiveCheckIn !== null && $effectiveCheckOut !== null && $effectiveCheckOut->lt($effectiveCheckIn)) {
            $errors[$prefix.'_hotel_check_out'] = 'Check-out date cannot be before check-in date.';
        }
    }

    /**
     * @return list<array{label: string, detail: string}>
     */
    private function accommodationPreviewSummary(HistoricalCrewAssignmentData $data): array
    {
        $summary = [];

        if ($data->signOnAccommodation === HistoricalCrewAssignmentData::ACCOMMODATION_HOTEL) {
            $hotelName = $data->signOnHotelId !== null
                ? (Hotel::query()->find($data->signOnHotelId)?->name ?? 'Hotel')
                : 'Hotel';
            $summary[] = [
                'label' => 'Pre-Join Hotel',
                'detail' => $hotelName,
            ];
        } elseif ($data->signOnAccommodation === HistoricalCrewAssignmentData::ACCOMMODATION_NO_ACCOMMODATION) {
            $summary[] = [
                'label' => 'Pre-Join',
                'detail' => CrewAccommodationStatus::NoAccommodation->label(),
            ];
        }

        if ($data->signOffAccommodation === HistoricalCrewAssignmentData::ACCOMMODATION_HOTEL) {
            $hotelName = $data->signOffHotelId !== null
                ? (Hotel::query()->find($data->signOffHotelId)?->name ?? 'Hotel')
                : 'Hotel';
            $summary[] = [
                'label' => 'Post-Sign-Off Hotel',
                'detail' => $hotelName,
            ];
        } elseif ($data->signOffAccommodation === HistoricalCrewAssignmentData::ACCOMMODATION_NO_ACCOMMODATION) {
            $summary[] = [
                'label' => 'Post-Sign-Off',
                'detail' => CrewAccommodationStatus::NoAccommodation->label(),
            ];
        }

        return $summary;
    }
}
