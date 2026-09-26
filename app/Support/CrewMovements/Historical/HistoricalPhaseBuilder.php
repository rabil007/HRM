<?php

namespace App\Support\CrewMovements\Historical;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use Carbon\CarbonInterface;

/**
 * Canonical historical/bootstrap phase construction from simplified operational periods.
 *
 * Manual preview/store and Excel preview/import must all use this builder.
 * Unknown historical phases (Pre-Mobilisation, Travel, Training, Ready to Join)
 * are never fabricated.
 */
final class HistoricalPhaseBuilder
{
    public const EVENT_SIGN_ON_STANDBY = 'sign_on_standby';

    public const EVENT_ON_VESSEL = 'on_vessel';

    public const EVENT_SIGN_OFF_STANDBY = 'sign_off_standby';

    public const EVENT_HOME = 'home_available';

    /** @deprecated Legacy event key retained for reading older reconstructed history. */
    public const EVENT_PRE_MOBILISATION = 'pre_mobilisation';

    /** @deprecated Legacy event key retained for reading older reconstructed history. */
    public const EVENT_JOIN_STANDBY = 'join_standby';

    /** @deprecated Legacy event key retained for reading older reconstructed history. */
    public const EVENT_TRAINING_START = 'training_start';

    /** @deprecated Legacy event key retained for reading older reconstructed history. */
    public const EVENT_TRAINING_END = 'training_end';

    /** @deprecated Legacy event key retained for reading older reconstructed history. */
    public const EVENT_DISEMBARKED = 'disembarked';

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
     *     },
     *     known_periods: list<array{
     *         key: string,
     *         label: string,
     *         from: string,
     *         to: ?string,
     *         to_display: string,
     *         days: ?int,
     *         is_open: bool
     *     }>
     * }
     */
    public static function build(HistoricalCrewAssignmentData $data): array
    {
        $timezone = $data->timezone;
        $periods = $data->enteredOperationalPeriods();
        $phases = [];

        foreach ($periods as $period) {
            $isOpen = $period['to'] === null;

            $phases[] = [
                'phase_code' => $period['phase_code'],
                'actual_start_at' => $period['from'],
                'actual_end_at' => $period['to'],
                'status' => $isOpen ? CrewPhaseStatus::Active : CrewPhaseStatus::Completed,
                'remarks' => $period['phase_code'] === CrewPhaseCode::OnVessel ? $data->remarks : null,
            ];
        }

        if ($data->homeAvailableFrom !== null) {
            self::closeOpenPhase($phases, $data->homeAvailableFrom);

            $phases[] = [
                'phase_code' => CrewPhaseCode::HomeRedeploy,
                'actual_start_at' => $data->homeAvailableFrom,
                'actual_end_at' => $data->homeAvailableFrom,
                'status' => CrewPhaseStatus::Completed,
                'remarks' => null,
            ];
        }

        if ($phases === []) {
            throw new \InvalidArgumentException('At least one meaningful movement period must be supplied.');
        }

        $lastPhase = $phases[array_key_last($phases)];
        $isOpen = $lastPhase['status'] === CrewPhaseStatus::Active
            && $lastPhase['actual_end_at'] === null
            && $data->homeAvailableFrom === null;

        $inferredCode = $lastPhase['phase_code'];
        $assignmentStatus = $isOpen
            ? CrewAssignmentStatus::Active
            : CrewAssignmentStatus::Completed;
        $closedAt = $isOpen ? null : ($data->homeAvailableFrom ?? $lastPhase['actual_end_at']);

        [$eventKey, $eventLabel, $eventAt] = self::describeCurrentState($data, $lastPhase);

        $knownPeriods = [];

        foreach ($periods as $period) {
            $fromLocal = $period['from']->copy()->timezone($timezone);
            $toLocal = $period['to']?->copy()->timezone($timezone);
            $isPeriodOpen = $period['to'] === null;

            $knownPeriods[] = [
                'key' => $period['key'],
                'label' => $period['label'],
                'from' => $fromLocal->format('d M Y'),
                'to' => $toLocal?->format('d M Y'),
                'to_display' => $isPeriodOpen ? 'Current' : ($toLocal?->format('d M Y') ?? 'Current'),
                'days' => HistoricalCrewAssignmentData::inclusiveDays($period['from'], $period['to']),
                'is_open' => $isPeriodOpen,
            ];
        }

        if ($data->homeAvailableFrom !== null) {
            $homeLocal = $data->homeAvailableFrom->copy()->timezone($timezone);
            $knownPeriods[] = [
                'key' => self::EVENT_HOME,
                'label' => 'Home / Available',
                'from' => $homeLocal->format('d M Y'),
                'to' => $homeLocal->format('d M Y'),
                'to_display' => $homeLocal->format('d M Y'),
                'days' => null,
                'is_open' => false,
            ];
        }

        return [
            'phases' => $phases,
            'assignment_status' => $assignmentStatus,
            'closed_at' => $closedAt,
            'is_open' => $isOpen,
            'inferred_state' => [
                'phase_code' => $inferredCode,
                'label' => $inferredCode->label(),
                'event_key' => $eventKey,
                'event_label' => $eventLabel,
                'event_at' => $eventAt,
            ],
            'last_movement' => [
                'event_key' => $eventKey,
                'event_label' => $eventLabel,
                'event_at' => $eventAt,
            ],
            'known_periods' => $knownPeriods,
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

    /**
     * @param  array{
     *     phase_code: CrewPhaseCode,
     *     actual_start_at: CarbonInterface,
     *     actual_end_at: ?CarbonInterface,
     *     status: CrewPhaseStatus,
     *     remarks: ?string
     * }  $lastPhase
     * @return array{0: string, 1: string, 2: CarbonInterface}
     */
    private static function describeCurrentState(HistoricalCrewAssignmentData $data, array $lastPhase): array
    {
        if ($data->homeAvailableFrom !== null) {
            return [self::EVENT_HOME, 'Home / Available', $data->homeAvailableFrom];
        }

        return match ($lastPhase['phase_code']) {
            CrewPhaseCode::JoinStandby => [
                self::EVENT_SIGN_ON_STANDBY,
                'Sign-On Standby',
                $lastPhase['actual_start_at'],
            ],
            CrewPhaseCode::OnVessel => [
                self::EVENT_ON_VESSEL,
                'On Vessel',
                $lastPhase['actual_start_at'],
            ],
            CrewPhaseCode::DemobStandby => [
                self::EVENT_SIGN_OFF_STANDBY,
                'Sign-Off Standby',
                $lastPhase['actual_start_at'],
            ],
            CrewPhaseCode::HomeRedeploy => [
                self::EVENT_HOME,
                'Home / Available',
                $lastPhase['actual_start_at'],
            ],
            default => [
                $lastPhase['phase_code']->value,
                $lastPhase['phase_code']->label(),
                $lastPhase['actual_start_at'],
            ],
        };
    }

    /**
     * Derive Last Movement vs Inferred State from persisted phases.
     *
     * Training End that opens P2A must report Last Movement = Training End,
     * while Inferred State remains Join Standby.
     *
     * @param  iterable<object|array{
     *     phase_code: CrewPhaseCode|string|null,
     *     actual_start_at: ?CarbonInterface,
     *     actual_end_at?: ?CarbonInterface,
     *     sequence?: int|null
     * }>  $phases
     * @return array{event_key: string, event_label: string, event_at: ?CarbonInterface, inferred_label: string}|null
     */
    public static function lastMovementFromPhases(iterable $phases): ?array
    {
        $normalized = [];

        foreach ($phases as $phase) {
            $code = is_array($phase)
                ? ($phase['phase_code'] ?? null)
                : ($phase->phase_code ?? null);
            $start = is_array($phase)
                ? ($phase['actual_start_at'] ?? null)
                : ($phase->actual_start_at ?? null);
            $end = is_array($phase)
                ? ($phase['actual_end_at'] ?? null)
                : ($phase->actual_end_at ?? null);
            $sequence = is_array($phase)
                ? ($phase['sequence'] ?? null)
                : ($phase->sequence ?? null);

            if ($code instanceof CrewPhaseCode) {
                $phaseCode = $code;
            } elseif (is_string($code) && $code !== '') {
                $phaseCode = CrewPhaseCode::tryFrom($code);
            } else {
                $phaseCode = null;
            }

            if ($phaseCode === null || $start === null) {
                continue;
            }

            $normalized[] = [
                'phase_code' => $phaseCode,
                'actual_start_at' => $start,
                'actual_end_at' => $end,
                'sequence' => $sequence,
            ];
        }

        if ($normalized === []) {
            return null;
        }

        usort($normalized, function (array $a, array $b): int {
            $seqA = $a['sequence'];
            $seqB = $b['sequence'];

            if ($seqA !== null && $seqB !== null && $seqA !== $seqB) {
                return $seqA <=> $seqB;
            }

            return $a['actual_start_at']->timestamp <=> $b['actual_start_at']->timestamp;
        });

        $last = $normalized[array_key_last($normalized)];
        $previous = count($normalized) > 1 ? $normalized[count($normalized) - 2] : null;
        $inferredLabel = $last['phase_code']->label();

        $fromTrainingEnd = $last['phase_code'] === CrewPhaseCode::JoinStandby
            && $previous !== null
            && $previous['phase_code'] === CrewPhaseCode::Training
            && $previous['actual_end_at'] !== null
            && $previous['actual_end_at']->equalTo($last['actual_start_at']);

        [$eventKey, $eventLabel] = match (true) {
            $last['phase_code'] === CrewPhaseCode::PreMobilisation => [
                self::EVENT_PRE_MOBILISATION,
                'Pre-Mobilisation',
            ],
            $fromTrainingEnd => [
                self::EVENT_TRAINING_END,
                'Training End',
            ],
            $last['phase_code'] === CrewPhaseCode::JoinStandby => [
                self::EVENT_SIGN_ON_STANDBY,
                'Sign-On Standby',
            ],
            $last['phase_code'] === CrewPhaseCode::Training => [
                self::EVENT_TRAINING_START,
                'Training Start',
            ],
            $last['phase_code'] === CrewPhaseCode::OnVessel => [
                self::EVENT_ON_VESSEL,
                'On Vessel',
            ],
            $last['phase_code'] === CrewPhaseCode::DemobStandby => [
                self::EVENT_SIGN_OFF_STANDBY,
                'Sign-Off Standby',
            ],
            $last['phase_code'] === CrewPhaseCode::HomeRedeploy => [
                self::EVENT_HOME,
                'Home / Available',
            ],
            default => [
                $last['phase_code']->value,
                $last['phase_code']->label(),
            ],
        };

        return [
            'event_key' => $eventKey,
            'event_label' => $eventLabel,
            'event_at' => $last['actual_start_at'],
            'inferred_label' => $inferredLabel,
        ];
    }
}
