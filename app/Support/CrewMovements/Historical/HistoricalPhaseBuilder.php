<?php

namespace App\Support\CrewMovements\Historical;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use Carbon\CarbonInterface;

/**
 * Canonical historical/bootstrap phase reconstruction from movement EVENTS.
 *
 * Manual preview/store and Excel preview/import must all use this builder.
 * Derived boundaries are allowed only where a live movement action itself
 * defines both phase end and next phase start (e.g. Training End → P2A,
 * Disembarked → P5, Home → P6/completion).
 */
final class HistoricalPhaseBuilder
{
    public const EVENT_PRE_MOBILISATION = 'pre_mobilisation';

    public const EVENT_JOIN_STANDBY = 'join_standby';

    public const EVENT_TRAINING_START = 'training_start';

    public const EVENT_TRAINING_END = 'training_end';

    public const EVENT_ON_VESSEL = 'on_vessel';

    public const EVENT_DISEMBARKED = 'disembarked';

    public const EVENT_HOME = 'home_redeployment';

    /**
     * @return array{
     *     phases: list<array{
     *         phase_code: CrewPhaseCode,
     *         actual_start_at: CarbonInterface,
     *         actual_end_at: ?CarbonInterface,
     *         status: CrewPhaseStatus,
     *         remarks: ?string
     *     }>,
     *     assignment_status: CrewAssignmentStatus,
     *     closed_at: ?CarbonInterface,
     *     is_open: bool,
     *     inferred_state: array{
     *         phase_code: CrewPhaseCode,
     *         label: string,
     *         event_key: string,
     *         event_label: string,
     *         event_at: CarbonInterface
     *     },
     *     last_movement: array{
     *         event_key: string,
     *         event_label: string,
     *         event_at: CarbonInterface
     *     }
     * }
     */
    public static function build(HistoricalCrewAssignmentData $data): array
    {
        /** @var list<array{key: string, label: string, at: CarbonInterface}> $events */
        $events = [];

        if ($data->mobilisationStartAt !== null) {
            $events[] = [
                'key' => self::EVENT_PRE_MOBILISATION,
                'label' => 'Pre-Mobilisation',
                'at' => $data->mobilisationStartAt,
            ];
        }

        if ($data->joinStandbyAt !== null) {
            $events[] = [
                'key' => self::EVENT_JOIN_STANDBY,
                'label' => 'Join Standby',
                'at' => $data->joinStandbyAt,
            ];
        }

        if ($data->trainingStartAt !== null) {
            $events[] = [
                'key' => self::EVENT_TRAINING_START,
                'label' => 'Training Start',
                'at' => $data->trainingStartAt,
            ];
        }

        if ($data->trainingEndAt !== null) {
            $events[] = [
                'key' => self::EVENT_TRAINING_END,
                'label' => 'Training End',
                'at' => $data->trainingEndAt,
            ];
        }

        if ($data->joinedVesselAt !== null) {
            $events[] = [
                'key' => self::EVENT_ON_VESSEL,
                'label' => 'On Vessel',
                'at' => $data->joinedVesselAt,
            ];
        }

        if ($data->disembarkedAt !== null) {
            $events[] = [
                'key' => self::EVENT_DISEMBARKED,
                'label' => 'Disembarked',
                'at' => $data->disembarkedAt,
            ];
        }

        if ($data->travelHomeAt !== null) {
            $events[] = [
                'key' => self::EVENT_HOME,
                'label' => 'Home / Redeployment',
                'at' => $data->travelHomeAt,
            ];
        }

        if ($events === []) {
            throw new \InvalidArgumentException('At least one meaningful movement date must be supplied.');
        }

        $phases = [];
        $directHomeAtDisembark = $data->disembarkedAt !== null
            && $data->travelHomeAt !== null
            && $data->disembarkedAt->equalTo($data->travelHomeAt);

        foreach ($events as $event) {
            if ($event['key'] === self::EVENT_HOME && $directHomeAtDisembark) {
                // Handled with Disembarked as direct P4 → P6 (no fabricated P5).
                continue;
            }

            $nextPhase = self::phaseOpenedByEvent($event['key'], $directHomeAtDisembark);

            if ($nextPhase === null) {
                continue;
            }

            self::closeOpenPhase($phases, $event['at']);

            $phases[] = [
                'phase_code' => $nextPhase,
                'actual_start_at' => $event['at'],
                'actual_end_at' => null,
                'status' => CrewPhaseStatus::Active,
                'remarks' => $nextPhase === CrewPhaseCode::OnVessel ? $data->remarks : null,
            ];

            if ($event['key'] === self::EVENT_HOME
                || ($event['key'] === self::EVENT_DISEMBARKED && $directHomeAtDisembark)) {
                // Match travel_home / direct-home completion: zero-length completed P6.
                $last = count($phases) - 1;
                $phases[$last]['actual_end_at'] = $event['at'];
                $phases[$last]['status'] = CrewPhaseStatus::Completed;
            }
        }

        if ($phases === []) {
            throw new \InvalidArgumentException('Unable to reconstruct movement phases from the supplied events.');
        }

        $lastEvent = $events[array_key_last($events)];
        $lastPhase = $phases[array_key_last($phases)];
        $isOpen = $lastPhase['status'] === CrewPhaseStatus::Active
            && $lastPhase['actual_end_at'] === null;

        $inferredCode = $lastPhase['phase_code'];
        $assignmentStatus = $isOpen
            ? CrewAssignmentStatus::Active
            : CrewAssignmentStatus::Completed;
        $closedAt = $isOpen ? null : ($data->travelHomeAt ?? $lastPhase['actual_end_at']);

        return [
            'phases' => $phases,
            'assignment_status' => $assignmentStatus,
            'closed_at' => $closedAt,
            'is_open' => $isOpen,
            'inferred_state' => [
                'phase_code' => $inferredCode,
                'label' => $inferredCode->label(),
                'event_key' => $lastEvent['key'],
                'event_label' => $lastEvent['label'],
                'event_at' => $lastEvent['at'],
            ],
            'last_movement' => [
                'event_key' => $lastEvent['key'],
                'event_label' => $lastEvent['label'],
                'event_at' => $lastEvent['at'],
            ],
        ];
    }

    /**
     * @param  list<array{
     *     phase_code: CrewPhaseCode,
     *     actual_start_at: CarbonInterface,
     *     actual_end_at: ?CarbonInterface,
     *     status: CrewPhaseStatus,
     *     remarks: ?string
     * }>  $phases
     */
    private static function closeOpenPhase(array &$phases, CarbonInterface $at): void
    {
        if ($phases === []) {
            return;
        }

        $last = count($phases) - 1;

        if ($phases[$last]['actual_end_at'] !== null) {
            return;
        }

        $phases[$last]['actual_end_at'] = $at;
        $phases[$last]['status'] = CrewPhaseStatus::Completed;
    }

    private static function phaseOpenedByEvent(string $eventKey, bool $directHomeAtDisembark): ?CrewPhaseCode
    {
        return match ($eventKey) {
            self::EVENT_PRE_MOBILISATION => CrewPhaseCode::PreMobilisation,
            self::EVENT_JOIN_STANDBY, self::EVENT_TRAINING_END => CrewPhaseCode::JoinStandby,
            self::EVENT_TRAINING_START => CrewPhaseCode::Training,
            self::EVENT_ON_VESSEL => CrewPhaseCode::OnVessel,
            self::EVENT_DISEMBARKED => $directHomeAtDisembark
                ? CrewPhaseCode::HomeRedeploy
                : CrewPhaseCode::DemobStandby,
            self::EVENT_HOME => CrewPhaseCode::HomeRedeploy,
            default => null,
        };
    }
}
