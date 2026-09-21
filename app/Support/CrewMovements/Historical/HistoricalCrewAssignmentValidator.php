<?php

namespace App\Support\CrewMovements\Historical;

use App\Enums\CrewAssignmentStatus;
use App\Models\Client;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CrewMovements\SeaServiceSyncService;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Carbon\CarbonInterface;

final class HistoricalCrewAssignmentValidator
{
    public function __construct(
        private readonly SeaServiceSyncService $seaServiceSync,
    ) {}

    public function validate(HistoricalCrewAssignmentData $data, ?User $actor = null): HistoricalCrewAssignmentValidationResult
    {
        $timezone = CompanyTimezone::forCompanyId($data->companyId);
        $errors = [];
        $warnings = [];
        $checks = [];

        // 1. Employee Check
        $employee = Employee::query()
            ->where('company_id', $data->companyId)
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
        }

        $checks[] = [
            'key' => 'employee',
            'label' => 'Employee valid',
            'passed' => $employeeValid,
            'message' => $employeeMessage,
        ];

        // 2. Vessel Check
        $vessel = Vessel::query()
            ->where('company_id', $data->companyId)
            ->find($data->vesselId);

        $vesselValid = true;
        $vesselMessage = null;

        if ($vessel === null) {
            $vesselValid = false;
            $vesselMessage = 'Vessel not found in the active company.';
            $errors['vessel_id'] = $vesselMessage;
        } elseif (! $vessel->is_active) {
            $vesselValid = false;
            $vesselMessage = 'The selected vessel is inactive.';
            $errors['vessel_id'] = $vesselMessage;
        }

        $checks[] = [
            'key' => 'vessel',
            'label' => 'Vessel valid',
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
        } elseif (! $rank->is_active) {
            $rankValid = false;
            $rankMessage = 'The selected rank is inactive.';
            $errors['rank_id'] = $rankMessage;
        }

        $checks[] = [
            'key' => 'rank',
            'label' => 'Rank valid',
            'passed' => $rankValid,
            'message' => $rankMessage,
        ];

        // Client validation (if vessel or client supplied)
        $client = null;
        if ($data->clientId !== null) {
            $client = Client::query()->find($data->clientId);
            if ($client === null || ! $client->is_active) {
                $errors['client_id'] = 'The selected client is invalid or inactive.';
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

        // Future timestamp checks
        $allSuppliedTimestamps = [
            'mobilisation_start_at' => $data->mobilisationStartAt,
            'arrival_at' => $data->arrivalAt,
            'join_standby_at' => $data->joinStandbyAt,
            'training_start_at' => $data->trainingStartAt,
            'training_end_at' => $data->trainingEndAt,
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
            ['field' => 'join_standby_at', 'label' => 'Join Standby', 'ts' => $data->joinStandbyAt],
            ['field' => 'training_start_at', 'label' => 'Training Start', 'ts' => $data->trainingStartAt],
            ['field' => 'training_end_at', 'label' => 'Training End', 'ts' => $data->trainingEndAt],
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
            'key' => 'dates',
            'label' => 'Dates valid',
            'passed' => $datesValid,
            'message' => $datesMessage,
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
                    // Active assignment is open-ended from existingStart to infinity.
                    // Overlap if newEnd > existingStart.
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
                    // Completed or Cancelled assignment has an actual closed/end timestamp.
                    $existingEnd = $existing->closed_at ?? $existing->phases->whereNotNull('actual_end_at')->max('actual_end_at') ?? $existingStart;

                    // Positive duration intersection: left.start < right.end && right.start < left.end
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
            'key' => 'no_conflict',
            'label' => 'No conflicting assignment found',
            'passed' => $noConflict,
            'message' => $conflictMessage,
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

        $currentIsolated = true;
        $currentIsolatedMessage = $activeAssignment !== null
            ? "Current active assignment {$activeAssignment->assignment_no} will remain untouched."
            : 'No active operational assignment exists; operations will remain untouched.';

        $checks[] = [
            'key' => 'current_isolated',
            'label' => 'Existing current assignment will not be modified',
            'passed' => $currentIsolated,
            'message' => $currentIsolatedMessage,
        ];

        // 7. Sea Service Impact & Deduplication Check
        $seaDuration = $data->seaServiceDuration();
        $seaStartDate = $data->joinedVesselAt->toDateString();
        $seaEndDate = $data->disembarkedAt->toDateString();
        $syncEnabled = $this->seaServiceSync->isEnabled($data->companyId);

        $seaServiceImpact = [
            'status' => 'will_create',
            'days' => $seaDuration['days'],
            'months' => $seaDuration['months'],
            'message' => "{$seaDuration['days']} days will be recorded/synchronized to Sea Service.",
            'existing_id' => null,
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

                // Exact match: same vessel and exact same dates
                if ((int) $record->vessel_id === $data->vesselId
                    && $recordStart === $seaStartDate
                    && $recordEnd === $seaEndDate) {
                    if ($record->crew_assignment_phase_id === null) {
                        $seaServiceImpact['status'] = 'will_link';
                        $seaServiceImpact['existing_id'] = (int) $record->id;
                        $seaServiceImpact['message'] = "Matches existing unlinked Sea Service record #{$record->id} ({$seaDuration['days']} days) and will link safely without duplicating.";
                        break;
                    }
                } elseif ($recordStart !== null && $recordEnd !== null) {
                    // Conflicting partial overlap with another sea service record
                    $recStartCarbon = Carbon::parse($recordStart);
                    $recEndCarbon = Carbon::parse($recordEnd);
                    $seaStartCarbon = Carbon::parse($seaStartDate);
                    $seaEndCarbon = Carbon::parse($seaEndDate);

                    if ($seaStartCarbon->lt($recEndCarbon) && $recStartCarbon->lt($seaEndCarbon)) {
                        $conflictVesselName = $record->vessel?->name ?? 'another vessel';
                        $errMsg = sprintf(
                            'Overlaps existing Sea Service record (%s, %s -> %s).',
                            $conflictVesselName,
                            $recStartCarbon->format('d M Y'),
                            $recEndCarbon->format('d M Y'),
                        );
                        $errors['sea_service'] = $errMsg;
                        $seaServiceImpact['status'] = 'conflict';
                        $seaServiceImpact['message'] = $errMsg;
                        break;
                    }
                }
            }
        }

        // Build Timeline for preview
        $timeline = [];
        foreach ($data->phasesToCreate() as $phase) {
            $timeline[] = [
                'phase_code' => $phase['phase_code']->value,
                'label' => $phase['phase_code']->label(),
                'timestamp' => $phase['actual_start_at']->copy()->timezone($timezone)->toDateTimeString(),
                'formatted' => $phase['actual_start_at']->copy()->timezone($timezone)->format('d M Y H:i'),
            ];
            // If P4 or final phase, also show end
            if ($phase['phase_code']->value === 'p4') {
                $timeline[] = [
                    'phase_code' => 'p4_end',
                    'label' => 'Disembarked',
                    'timestamp' => $phase['actual_end_at']->copy()->timezone($timezone)->toDateTimeString(),
                    'formatted' => $phase['actual_end_at']->copy()->timezone($timezone)->format('d M Y H:i'),
                ];
            }
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
            timeline: $timeline,
            durationDays: $seaDuration['days'],
            seaService: $seaServiceImpact,
            conflictingAssignment: $conflictingAssignment,
        );
    }
}
