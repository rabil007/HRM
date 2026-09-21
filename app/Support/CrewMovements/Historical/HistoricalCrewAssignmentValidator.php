<?php

namespace App\Support\CrewMovements\Historical;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Models\Client;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\User;
use App\Support\CrewMovements\SeaServiceSyncService;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\MasterData\ClientAssignmentRules;
use Carbon\Carbon;
use Carbon\CarbonInterface;

final class HistoricalCrewAssignmentValidator
{
    public const ACTIVE_ASSIGNMENT_CONFLICT_MESSAGE = 'This employee already has an active Crew Assignment in OMS-HRM. Historical records before the active assignment may still be imported, but this row cannot become another current assignment.';

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

        // 1. Employee Check
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

            if ($employee->status !== 'active') {
                $statusLabel = str_replace('_', ' ', (string) $employee->status);
                $warnings[] = "Employee status is currently '{$statusLabel}'. Historical recording is still allowed.";
            }
        }

        $checks[] = [
            'code' => 'employee',
            'passed' => $employeeValid,
            'message' => $employeeMessage,
        ];

        // 2. Vessel Check
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

        // 3. Rank Check
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

        // Client is a historical snapshot — never force today's Vessel.client_id.
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

        // 4. Chronological & dependency validation
        $datesValid = true;
        $datesMessage = null;
        $now = Carbon::now($timezone);
        $reconstruction = null;

        if (! $data->hasAnyMovementDate()) {
            $datesValid = false;
            $errors['dates'] = 'At least one meaningful movement date must be supplied.';
        }

        $allSuppliedTimestamps = [
            'mobilisation_start_at' => $data->mobilisationStartAt,
            'join_standby_at' => $data->joinStandbyAt,
            'training_start_at' => $data->trainingStartAt,
            'training_end_at' => $data->trainingEndAt,
            'joined_vessel_at' => $data->joinedVesselAt,
            'disembarked_at' => $data->disembarkedAt,
            'travel_home_at' => $data->travelHomeAt,
        ];

        foreach ($allSuppliedTimestamps as $field => $ts) {
            if ($ts !== null && $ts->gt($now)) {
                $datesValid = false;
                $errors[$field] = 'Actual historical dates cannot be in the future.';
            }
        }

        if ($data->trainingEndAt !== null && $data->trainingStartAt === null) {
            $datesValid = false;
            $errors['training_start_at'] = 'Training Start is required when Training End is provided.';
        }

        if ($data->disembarkedAt !== null && $data->joinedVesselAt === null) {
            $datesValid = false;
            $errors['joined_vessel_at'] = 'On Vessel is required when Disembarked is provided.';
        }

        if ($data->travelHomeAt !== null && $data->disembarkedAt === null) {
            $datesValid = false;
            $errors['disembarked_at'] = 'Disembarked is required when Home / Redeployment is provided.';
        }

        if ($data->joinedVesselAt !== null && $data->disembarkedAt !== null
            && ! $data->joinedVesselAt->lt($data->disembarkedAt)) {
            $datesValid = false;
            $errors['disembarked_at'] = 'Disembarked must be after On Vessel.';
        }

        $chainDefinitions = [
            ['field' => 'mobilisation_start_at', 'label' => 'Pre-Mobilisation', 'ts' => $data->mobilisationStartAt],
            ['field' => 'join_standby_at', 'label' => 'Join Standby', 'ts' => $data->joinStandbyAt],
            ['field' => 'training_start_at', 'label' => 'Training Start', 'ts' => $data->trainingStartAt],
            ['field' => 'training_end_at', 'label' => 'Training End', 'ts' => $data->trainingEndAt],
            ['field' => 'joined_vessel_at', 'label' => 'On Vessel', 'ts' => $data->joinedVesselAt],
            ['field' => 'disembarked_at', 'label' => 'Disembarked', 'ts' => $data->disembarkedAt],
            ['field' => 'travel_home_at', 'label' => 'Home / Redeployment', 'ts' => $data->travelHomeAt],
        ];

        /** @var list<array{field: string, label: string, ts: CarbonInterface}> $suppliedChain */
        $suppliedChain = [];
        foreach ($chainDefinitions as $def) {
            if ($def['ts'] !== null) {
                $suppliedChain[] = $def;
            }
        }

        for ($i = 0; $i < count($suppliedChain) - 1; $i++) {
            $left = $suppliedChain[$i];
            $right = $suppliedChain[$i + 1];

            if ($left['field'] === 'joined_vessel_at' && $right['field'] === 'disembarked_at') {
                if (! $left['ts']->lt($right['ts'])) {
                    $datesValid = false;
                    $errors[$right['field']] = "{$right['label']} must be after {$left['label']}.";
                }
            } elseif ($left['field'] === 'disembarked_at' && $right['field'] === 'travel_home_at') {
                // Same timestamp is valid (direct P4 → P6).
                if ($left['ts']->gt($right['ts'])) {
                    $datesValid = false;
                    $errors[$right['field']] = "{$right['label']} cannot be before {$left['label']}.";
                    $errors[$left['field']] = "{$left['label']} cannot be after {$right['label']}.";
                }
            } else {
                if ($left['ts']->gt($right['ts'])) {
                    $datesValid = false;
                    $msg = "{$right['label']} cannot be before {$left['label']}.";
                    $errors[$right['field']] = $msg;
                    $errors[$left['field']] = "{$left['label']} cannot be after {$right['label']}.";

                    if ($left['field'] === 'mobilisation_start_at') {
                        $errors['mobilisation_at'] = $errors[$left['field']];
                    }
                    if ($right['field'] === 'mobilisation_start_at') {
                        $errors['mobilisation_at'] = $errors[$right['field']];
                    }
                }
            }
        }

        if ($datesValid && $data->hasAnyMovementDate()) {
            try {
                $reconstruction = $data->reconstruction();
            } catch (\InvalidArgumentException $exception) {
                $datesValid = false;
                $errors['dates'] = $exception->getMessage();
            }
        }

        if (! $datesValid && $datesMessage === null) {
            $datesMessage = 'One or more timestamps are chronologically invalid or in the future.';
        }

        $checks[] = [
            'code' => 'dates',
            'passed' => $datesValid,
            'message' => $datesMessage ?? 'Dates and chronological sequence are valid.',
        ];

        $isOpenBootstrap = $reconstruction['is_open'] ?? false;
        $newStart = null;
        $newEnd = null;

        if ($reconstruction !== null) {
            $newStart = $data->earliestActualStart();
            $newEnd = $data->intervalEnd();
        }

        // 5. Existing Active OMS assignment protection for open bootstrap rows
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

        if ($isOpenBootstrap && $activeAssignment !== null) {
            $errors['assignment'] = self::ACTIVE_ASSIGNMENT_CONFLICT_MESSAGE;
        }

        // 6. Overlap Check with Existing Assignment History
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

        // 7. Current operational isolation messaging
        if ($isOpenBootstrap && $activeAssignment === null) {
            $currentIsolatedMessage = 'No conflicting active OMS assignment exists; this row may bootstrap the current operational state.';
        } elseif ($activeAssignment !== null && ! $isOpenBootstrap) {
            $currentIsolatedMessage = "Current active assignment {$activeAssignment->assignment_no} will remain untouched.";
        } elseif ($activeAssignment !== null) {
            $currentIsolatedMessage = self::ACTIVE_ASSIGNMENT_CONFLICT_MESSAGE;
        } else {
            $currentIsolatedMessage = 'No active operational assignment exists.';
        }

        $checks[] = [
            'code' => 'current_isolated',
            'passed' => ! isset($errors['assignment']),
            'message' => $currentIsolatedMessage,
        ];

        // 8. Sea Service Impact (only for completed P4)
        $seaDuration = $data->seaServiceDuration();
        $seaStartDate = $data->joinedVesselAt?->toDateString();
        $seaEndDate = $data->disembarkedAt?->toDateString();
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
            'message' => 'Sea Service is created only for a completed On Vessel → Disembarked period.',
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
        }

        $summary = [
            'joined_vessel_at' => $data->joinedVesselAt?->copy()->timezone($timezone)->format('d M Y'),
            'disembarked_at' => $data->disembarkedAt?->copy()->timezone($timezone)->format('d M Y'),
            'sea_service_days' => $data->hasCompletedSeaServicePeriod() ? $seaDuration['days'] : null,
            'remarks' => $data->remarks,
            'assignment_status' => $reconstruction['assignment_status']->value ?? null,
            'is_open' => $reconstruction['is_open'] ?? null,
        ];

        $timeline = [];
        if ($reconstruction !== null) {
            foreach ($reconstruction['phases'] as $phase) {
                $phaseStart = $phase['actual_start_at']->copy()->timezone($timezone);
                $phaseEnd = $phase['actual_end_at']?->copy()->timezone($timezone);
                $duration = null;

                if ($phase['phase_code'] === CrewPhaseCode::OnVessel && $phaseEnd !== null) {
                    $duration = $seaDuration['days'];
                } elseif ($phaseEnd !== null && $phaseStart->toDateString() !== $phaseEnd->toDateString()) {
                    $duration = (int) $phaseStart->diffInDays($phaseEnd) + 1;
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
}
