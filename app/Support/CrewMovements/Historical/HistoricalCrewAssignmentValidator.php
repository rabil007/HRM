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
    public function __construct(
        private readonly SeaServiceSyncService $seaServiceSync,
    ) {}

    public function validate(HistoricalCrewAssignmentData $data, ?User $actor = null): HistoricalCrewAssignmentValidationResult
    {
        $timezone = $data->timezone;
        $errors = [];
        $warnings = [];
        $checks = [];

        // 1. Employee Check: must belong to company, not soft-deleted, and visible to actor
        $employee = Employee::query()
            ->where('company_id', $data->companyId)
            ->whereNull('deleted_at')
            ->find($data->employeeId);

        $employeeValid = true;
        $employeeMessage = null;

        if ($employee === null) {
            $employeeValid = false;
            $employeeMessage = 'Employee not found in the active company.';
            $errors['employee_id'] = $employeeMessage;
        } elseif ($actor !== null && ! EmployeeVisibilityScope::canAccess($actor, $employee, $data->companyId)) {
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

        // 2. Vessel Check: must belong to company via ClientAssignmentRules
        $vessel = ClientAssignmentRules::findCompanyVessel($data->companyId, $data->vesselId);

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
        $rank = Rank::query()->find($data->rankId);
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

        // Client validation: auto-resolve or validate compatibility
        $client = null;
        if ($data->clientId !== null) {
            $client = Client::query()->find($data->clientId);
            if ($client === null) {
                $errors['client_id'] = 'The selected client is invalid.';
            } elseif ($vessel !== null && $vessel->client_id !== null && (int) $vessel->client_id !== $data->clientId) {
                $errors['client_id'] = 'The selected client does not match the vessel’s assigned client.';
            }
        } elseif ($vessel !== null && $vessel->client_id !== null) {
            $client = Client::query()->find((int) $vessel->client_id);
        }

        // 4. Chronological & Future Dates Check
        $datesValid = true;
        $datesMessage = null;
        $now = Carbon::now($timezone);

        $allSuppliedTimestamps = [
            'mobilisation_start_at' => $data->mobilisationStartAt,
            'arrival_at' => $data->arrivalAt,
            'join_standby_at' => $data->joinStandbyAt,
            'training_start_at' => $data->trainingStartAt,
            'training_end_at' => $data->trainingEndAt,
            'post_training_join_standby_at' => $data->postTrainingJoinStandbyAt,
            'ready_to_join_at' => $data->readyToJoinAt,
            'joined_vessel_at' => $data->joinedVesselAt,
            'disembarked_at' => $data->disembarkedAt,
            'demob_standby_at' => $data->demobStandbyAt,
            'travel_home_at' => $data->travelHomeAt,
            'assignment_closed_at' => $data->assignmentClosedAt,
        ];

        foreach ($allSuppliedTimestamps as $field => $ts) {
            if ($ts !== null && $ts->gt($now)) {
                $datesValid = false;
                $errors[$field] = 'Actual historical dates cannot be in the future.';
            }
        }

        // Mandatory P4 check: joined_vessel_at < disembarked_at
        if (! $data->joinedVesselAt->lt($data->disembarkedAt)) {
            $datesValid = false;
            $errors['disembarked_at'] = 'Disembarkation must be after joined vessel date.';
        }

        // Training start & end consistency
        if ($data->trainingEndAt !== null && $data->trainingStartAt === null) {
            $datesValid = false;
            $errors['training_start_at'] = 'Training start date is required when training end is provided.';
        }

        // Chronological chain comparison
        $chainDefinitions = [
            ['field' => 'mobilisation_start_at', 'label' => 'Mobilisation Start', 'ts' => $data->mobilisationStartAt],
            ['field' => 'arrival_at', 'label' => 'Arrival / Travel In', 'ts' => $data->arrivalAt],
            ['field' => 'join_standby_at', 'label' => 'Join Standby Start', 'ts' => $data->joinStandbyAt],
            ['field' => 'training_start_at', 'label' => 'Training Start', 'ts' => $data->trainingStartAt],
            ['field' => 'training_end_at', 'label' => 'Training End', 'ts' => $data->trainingEndAt],
            ['field' => 'post_training_join_standby_at', 'label' => 'Post-Training Standby', 'ts' => $data->postTrainingJoinStandbyAt],
            ['field' => 'ready_to_join_at', 'label' => 'Ready to Join', 'ts' => $data->readyToJoinAt],
            ['field' => 'joined_vessel_at', 'label' => 'Joined Vessel', 'ts' => $data->joinedVesselAt],
            ['field' => 'disembarked_at', 'label' => 'Disembarked', 'ts' => $data->disembarkedAt],
            ['field' => 'demob_standby_at', 'label' => 'Demobilisation Standby', 'ts' => $data->demobStandbyAt],
            ['field' => 'travel_home_at', 'label' => 'Travel Home', 'ts' => $data->travelHomeAt],
            ['field' => 'assignment_closed_at', 'label' => 'Assignment Closed', 'ts' => $data->assignmentClosedAt],
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

        if (! $datesValid && $datesMessage === null) {
            $datesMessage = 'One or more timestamps are chronologically invalid or in the future.';
        }

        $checks[] = [
            'code' => 'dates',
            'passed' => $datesValid,
            'message' => $datesMessage ?? 'Dates and chronological sequence are valid.',
        ];

        // 5. Overlap Check with Existing Assignment History
        $newStart = $data->earliestActualStart();
        $newEnd = $data->latestActualEnd();

        $noConflict = true;
        $conflictMessage = null;
        $conflictingAssignment = null;

        if ($employee !== null) {
            $existingAssignments = CrewAssignment::query()
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

                if ($existing->status === CrewAssignmentStatus::Active) {
                    if ($newEnd->gt($existingStart)) {
                        $noConflict = false;
                        $conflictingAssignment = $existing;
                        $overlapStart = $newStart->gt($existingStart) ? $newStart : $existingStart;
                        $overlapEnd = $newEnd;
                        $conflictMessage = sprintf(
                            'Overlaps %s (%s -> %s)',
                            $existing->assignment_no,
                            $overlapStart->copy()->timezone($timezone)->format('d M Y'),
                            $overlapEnd->copy()->timezone($timezone)->format('d M Y'),
                        );
                        $errors['overlap'] = $conflictMessage;
                        break;
                    }
                } else {
                    $existingEnd = $existing->closed_at ?? $existing->phases->whereNotNull('actual_end_at')->max('actual_end_at') ?? $existingStart;

                    if ($newStart->lt($existingEnd) && $existingStart->lt($newEnd)) {
                        $noConflict = false;
                        $conflictingAssignment = $existing;
                        $overlapStart = $newStart->gt($existingStart) ? $newStart : $existingStart;
                        $overlapEnd = $newEnd->lt($existingEnd) ? $newEnd : $existingEnd;
                        $conflictMessage = sprintf(
                            'Overlaps %s (%s -> %s)',
                            $existing->assignment_no,
                            $overlapStart->copy()->timezone($timezone)->format('d M Y'),
                            $overlapEnd->copy()->timezone($timezone)->format('d M Y'),
                        );
                        $errors['overlap'] = $conflictMessage;
                        break;
                    }
                }
            }
        }

        $checks[] = [
            'code' => 'no_conflict',
            'passed' => $noConflict,
            'message' => $conflictMessage ?? 'No conflicting assignment found.',
        ];

        // 6. Current Operational State Isolation Check
        $activeAssignment = $employee !== null
            ? CrewAssignment::query()
                ->where('company_id', $data->companyId)
                ->where('employee_id', $data->employeeId)
                ->where('status', CrewAssignmentStatus::Active)
                ->whereNull('voided_at')
                ->first()
            : null;

        $currentIsolatedMessage = $activeAssignment !== null
            ? "Current active assignment {$activeAssignment->assignment_no} will remain untouched."
            : 'No active operational assignment exists; operations will remain untouched.';

        $checks[] = [
            'code' => 'current_isolated',
            'passed' => true,
            'message' => $currentIsolatedMessage,
        ];

        // 7. Sea Service Impact & Inclusive Overlap Check
        $seaDuration = $data->seaServiceDuration();
        $seaStartDate = $data->joinedVesselAt->toDateString();
        $seaEndDate = $data->disembarkedAt->toDateString();
        $vesselName = $vessel?->name ?? 'Unknown Vessel';
        $syncEnabled = $this->seaServiceSync->isEnabled($data->companyId);

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
            $existingSeaServices = EmployeeSeaService::query()
                ->where('company_id', $data->companyId)
                ->where('employee_id', $data->employeeId)
                ->with(['vessel'])
                ->get();

            foreach ($existingSeaServices as $record) {
                $recordStart = $record->start_date?->toDateString();
                $recordEnd = $record->end_date?->toDateString();

                if ($recordStart === null || $recordEnd === null) {
                    continue;
                }

                // Check Exact Match: same vessel and exact same calendar dates
                if ((int) $record->vessel_id === $data->vesselId
                    && $recordStart === $seaStartDate
                    && $recordEnd === $seaEndDate) {

                    if ($record->crew_assignment_phase_id !== null) {
                        $errMsg = "Matches existing Sea Service record #{$record->id} which is already linked to another assignment phase.";
                        $errors['sea_service'] = $errMsg;
                        $seaServiceImpact['status'] = 'conflict';
                        $seaServiceImpact['message'] = $errMsg;
                        break;
                    }

                    // Check for conflicting HR history: Rank conflict
                    if ($record->rank_id !== null && (int) $record->rank_id !== $data->rankId) {
                        $existingRankName = Rank::query()->find($record->rank_id)?->name ?? '#'.$record->rank_id;
                        $proposedRankName = $rank?->name ?? '#'.$data->rankId;
                        $errMsg = "Matches existing Sea Service record #{$record->id} with conflicting rank ({$existingRankName} vs {$proposedRankName}). Cannot automatically overwrite HR history.";
                        $errors['sea_service'] = $errMsg;
                        $seaServiceImpact['status'] = 'conflict';
                        $seaServiceImpact['message'] = $errMsg;
                        break;
                    }

                    // Check for conflicting client
                    if ($record->client_id !== null && $data->clientId !== null && (int) $record->client_id !== $data->clientId) {
                        $errMsg = "Matches existing Sea Service record #{$record->id} with conflicting client.";
                        $errors['sea_service'] = $errMsg;
                        $seaServiceImpact['status'] = 'conflict';
                        $seaServiceImpact['message'] = $errMsg;
                        break;
                    }

                    // Compatible exact unlinked match: safe to link
                    $seaServiceImpact['status'] = 'will_link';
                    $seaServiceImpact['existing_id'] = (int) $record->id;
                    $seaServiceImpact['message'] = "Matches existing unlinked Sea Service record #{$record->id} ({$seaDuration['days']} days) and will link safely without duplicating.";
                    break;
                }

                // Non-exact match: Check inclusive calendar date overlap: [start1, end1] and [start2, end2]
                // Touching boundary (same day) is an overlap on inclusive calendar days!
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

        // Summary for preview
        $summary = [
            'joined_vessel_at' => $data->joinedVesselAt->copy()->timezone($timezone)->format('d M Y'),
            'disembarked_at' => $data->disembarkedAt->copy()->timezone($timezone)->format('d M Y'),
            'sea_service_days' => $seaDuration['days'],
            'remarks' => $data->remarks,
        ];

        // Build Timeline for preview
        $timeline = [];
        foreach ($data->phasesToCreate() as $phase) {
            $phaseStart = $phase['actual_start_at']->copy()->timezone($timezone);
            $phaseEnd = $phase['actual_end_at']->copy()->timezone($timezone);
            $duration = null;
            if ($phase['phase_code'] === CrewPhaseCode::OnVessel) {
                $duration = $seaDuration['days'];
            } elseif ($phaseStart->toDateString() !== $phaseEnd->toDateString()) {
                $duration = (int) $phaseStart->diffInDays($phaseEnd) + 1;
            }

            $timeline[] = [
                'phase_code' => $phase['phase_code']->value,
                'phase_label' => $phase['phase_code']->label(),
                'start' => $phaseStart->format('d M Y H:i'),
                'end' => $phaseEnd->format('d M Y H:i'),
                'duration_days' => $duration,
            ];
        }

        $isValid = empty($errors);

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
        );
    }
}
