<?php

namespace App\Support\CrewMovements\Historical;

use App\Enums\CrewPhaseCode;
use App\Support\Employees\SeaServiceDuration;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class HistoricalCrewAssignmentData
{
    public const SOURCE_MANUAL = 'historical_manual';

    public const SOURCE_IMPORT = 'historical_import';

    public function __construct(
        public readonly int $companyId,
        public readonly int $employeeId,
        public readonly int $vesselId,
        public readonly int $rankId,
        public readonly ?int $clientId,
        public readonly CarbonInterface $joinedVesselAt,
        public readonly CarbonInterface $disembarkedAt,
        public readonly ?string $remarks = null,
        public readonly ?CarbonInterface $mobilisationStartAt = null,
        public readonly ?CarbonInterface $arrivalAt = null,
        public readonly ?CarbonInterface $joinStandbyAt = null,
        public readonly ?CarbonInterface $trainingStartAt = null,
        public readonly ?CarbonInterface $trainingEndAt = null,
        public readonly ?CarbonInterface $readyToJoinAt = null,
        public readonly ?CarbonInterface $demobStandbyAt = null,
        public readonly ?CarbonInterface $travelHomeAt = null,
        public readonly ?CarbonInterface $assignmentClosedAt = null,
        public readonly string $source = self::SOURCE_MANUAL,
    ) {}

    public static function fromArray(
        array $data,
        int $companyId,
        string $timezone,
        string $source = self::SOURCE_MANUAL,
    ): self {
        $joinedVesselAt = self::parseTimestamp((string) ($data['joined_vessel_at'] ?? ''), $timezone)
            ?? throw new \InvalidArgumentException('joined_vessel_at is required.');

        $disembarkedAt = self::parseTimestamp((string) ($data['disembarked_at'] ?? ''), $timezone)
            ?? throw new \InvalidArgumentException('disembarked_at is required.');

        return new self(
            companyId: $companyId,
            employeeId: (int) ($data['employee_id'] ?? 0),
            vesselId: (int) ($data['vessel_id'] ?? 0),
            rankId: (int) ($data['rank_id'] ?? 0),
            clientId: isset($data['client_id']) && $data['client_id'] !== '' && $data['client_id'] !== null ? (int) $data['client_id'] : null,
            joinedVesselAt: $joinedVesselAt,
            disembarkedAt: $disembarkedAt,
            remarks: isset($data['remarks']) && is_string($data['remarks']) && trim($data['remarks']) !== '' ? trim($data['remarks']) : null,
            mobilisationStartAt: self::parseTimestamp($data['mobilisation_start_at'] ?? $data['mobilisation_at'] ?? null, $timezone),
            arrivalAt: self::parseTimestamp($data['arrival_at'] ?? null, $timezone),
            joinStandbyAt: self::parseTimestamp($data['join_standby_at'] ?? null, $timezone),
            trainingStartAt: self::parseTimestamp($data['training_start_at'] ?? $data['training_started_at'] ?? null, $timezone),
            trainingEndAt: self::parseTimestamp($data['training_end_at'] ?? $data['training_ended_at'] ?? null, $timezone),
            readyToJoinAt: self::parseTimestamp($data['ready_to_join_at'] ?? null, $timezone),
            demobStandbyAt: self::parseTimestamp($data['demob_standby_at'] ?? $data['post_signoff_standby_at'] ?? null, $timezone),
            travelHomeAt: self::parseTimestamp($data['travel_home_at'] ?? null, $timezone),
            assignmentClosedAt: self::parseTimestamp($data['assignment_closed_at'] ?? null, $timezone),
            source: $source,
        );
    }

    public static function parseTimestamp(?string $value, string $timezone): ?CarbonInterface
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $trimmed = trim($value);
        $hasExplicitTimezone = preg_match('/(Z|[+-]\d{2}(?::?\d{2})?)$/i', $trimmed) === 1;

        return $hasExplicitTimezone
            ? CarbonImmutable::parse($trimmed)
            : CarbonImmutable::parse($trimmed, $timezone)->setTimezone('UTC');
    }

    public function earliestActualStart(): CarbonInterface
    {
        $candidates = array_filter([
            $this->mobilisationStartAt,
            $this->arrivalAt,
            $this->joinStandbyAt,
            $this->trainingStartAt,
            $this->readyToJoinAt,
            $this->joinedVesselAt,
        ]);

        $earliest = $this->joinedVesselAt;
        foreach ($candidates as $candidate) {
            if ($candidate->lt($earliest)) {
                $earliest = $candidate;
            }
        }

        return $earliest;
    }

    public function latestActualEnd(): CarbonInterface
    {
        $candidates = array_filter([
            $this->disembarkedAt,
            $this->demobStandbyAt,
            $this->travelHomeAt,
            $this->assignmentClosedAt,
        ]);

        $latest = $this->disembarkedAt;
        foreach ($candidates as $candidate) {
            if ($candidate->gt($latest)) {
                $latest = $candidate;
            }
        }

        return $latest;
    }

    public function seaServiceDuration(): array
    {
        if ($this->joinedVesselAt->gte($this->disembarkedAt)) {
            return ['days' => 0, 'months' => 0];
        }

        return SeaServiceDuration::fromDates(
            $this->joinedVesselAt->toDateString(),
            $this->disembarkedAt->toDateString(),
        );
    }

    /**
     * Constructs the exact ordered phases to persist without inventing any history.
     *
     * @return list<array{
     *     phase_code: CrewPhaseCode,
     *     actual_start_at: CarbonInterface,
     *     actual_end_at: CarbonInterface,
     *     remarks: ?string
     * }>
     */
    public function phasesToCreate(): array
    {
        $phases = [];

        // Pre-P4 phases:
        if ($this->mobilisationStartAt !== null) {
            $nextStart = $this->arrivalAt
                ?? $this->joinStandbyAt
                ?? $this->trainingStartAt
                ?? $this->readyToJoinAt
                ?? $this->joinedVesselAt;

            $phases[] = [
                'phase_code' => CrewPhaseCode::PreMobilisation,
                'actual_start_at' => $this->mobilisationStartAt,
                'actual_end_at' => $nextStart,
                'remarks' => null,
            ];
        }

        if ($this->arrivalAt !== null) {
            $nextStart = $this->joinStandbyAt
                ?? $this->trainingStartAt
                ?? $this->readyToJoinAt
                ?? $this->joinedVesselAt;

            $phases[] = [
                'phase_code' => CrewPhaseCode::TravelIn,
                'actual_start_at' => $this->arrivalAt,
                'actual_end_at' => $nextStart,
                'remarks' => null,
            ];
        }

        if ($this->joinStandbyAt !== null) {
            $nextStart = $this->trainingStartAt
                ?? $this->readyToJoinAt
                ?? $this->joinedVesselAt;

            $phases[] = [
                'phase_code' => CrewPhaseCode::JoinStandby,
                'actual_start_at' => $this->joinStandbyAt,
                'actual_end_at' => $nextStart,
                'remarks' => null,
            ];
        }

        if ($this->trainingStartAt !== null) {
            $trainingEnd = $this->trainingEndAt
                ?? $this->readyToJoinAt
                ?? $this->joinedVesselAt;

            $phases[] = [
                'phase_code' => CrewPhaseCode::Training,
                'actual_start_at' => $this->trainingStartAt,
                'actual_end_at' => $trainingEnd,
                'remarks' => null,
            ];
        }

        if ($this->readyToJoinAt !== null) {
            $phases[] = [
                'phase_code' => CrewPhaseCode::ReadyToJoin,
                'actual_start_at' => $this->readyToJoinAt,
                'actual_end_at' => $this->joinedVesselAt,
                'remarks' => null,
            ];
        }

        // P4 is mandatory:
        $phases[] = [
            'phase_code' => CrewPhaseCode::OnVessel,
            'actual_start_at' => $this->joinedVesselAt,
            'actual_end_at' => $this->disembarkedAt,
            'remarks' => $this->remarks,
        ];

        // Post-P4 phases:
        if ($this->demobStandbyAt !== null) {
            $nextStart = $this->travelHomeAt
                ?? $this->assignmentClosedAt
                ?? $this->demobStandbyAt;

            $phases[] = [
                'phase_code' => CrewPhaseCode::DemobStandby,
                'actual_start_at' => $this->demobStandbyAt,
                'actual_end_at' => $nextStart,
                'remarks' => null,
            ];
        }

        if ($this->travelHomeAt !== null) {
            $homeEnd = $this->assignmentClosedAt ?? $this->travelHomeAt;

            $phases[] = [
                'phase_code' => CrewPhaseCode::HomeRedeploy,
                'actual_start_at' => $this->travelHomeAt,
                'actual_end_at' => $homeEnd,
                'remarks' => null,
            ];
        }

        return $phases;
    }
}
