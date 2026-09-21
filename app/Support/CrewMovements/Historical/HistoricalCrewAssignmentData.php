<?php

namespace App\Support\CrewMovements\Historical;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
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
        public readonly string $timezone,
        public readonly ?CarbonInterface $joinedVesselAt = null,
        public readonly ?CarbonInterface $disembarkedAt = null,
        public readonly ?string $remarks = null,
        public readonly ?CarbonInterface $mobilisationStartAt = null,
        public readonly ?CarbonInterface $joinStandbyAt = null,
        public readonly ?CarbonInterface $trainingStartAt = null,
        public readonly ?CarbonInterface $trainingEndAt = null,
        public readonly ?CarbonInterface $travelHomeAt = null,
        public readonly string $source = self::SOURCE_MANUAL,
    ) {}

    public static function fromArray(
        array $data,
        int $companyId,
        string $timezone,
        string $source = self::SOURCE_MANUAL,
    ): self {
        return new self(
            companyId: $companyId,
            employeeId: (int) ($data['employee_id'] ?? 0),
            vesselId: (int) ($data['vessel_id'] ?? 0),
            rankId: (int) ($data['rank_id'] ?? 0),
            clientId: isset($data['client_id']) && $data['client_id'] !== '' && $data['client_id'] !== null ? (int) $data['client_id'] : null,
            timezone: $timezone,
            joinedVesselAt: self::parseTimestamp($data['joined_vessel_at'] ?? null, $timezone),
            disembarkedAt: self::parseTimestamp($data['disembarked_at'] ?? null, $timezone),
            remarks: isset($data['remarks']) && is_string($data['remarks']) && trim($data['remarks']) !== '' ? trim($data['remarks']) : null,
            mobilisationStartAt: self::parseTimestamp($data['mobilisation_start_at'] ?? $data['mobilisation_at'] ?? null, $timezone),
            joinStandbyAt: self::parseTimestamp($data['join_standby_at'] ?? null, $timezone),
            trainingStartAt: self::parseTimestamp($data['training_start_at'] ?? $data['training_started_at'] ?? null, $timezone),
            trainingEndAt: self::parseTimestamp($data['training_end_at'] ?? $data['training_ended_at'] ?? null, $timezone),
            travelHomeAt: self::parseTimestamp($data['travel_home_at'] ?? null, $timezone),
            source: $source,
        );
    }

    public static function parseTimestamp(mixed $value, string $timezone): ?CarbonInterface
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return null;
        }

        $hasExplicitTimezone = preg_match('/(Z|[+-]\d{2}(?::?\d{2})?)$/i', $trimmed) === 1;

        if ($hasExplicitTimezone) {
            return CarbonImmutable::parse($trimmed)->setTimezone($timezone);
        }

        return CarbonImmutable::parse($trimmed, $timezone);
    }

    public function hasAnyMovementDate(): bool
    {
        return $this->mobilisationStartAt !== null
            || $this->joinStandbyAt !== null
            || $this->trainingStartAt !== null
            || $this->trainingEndAt !== null
            || $this->joinedVesselAt !== null
            || $this->disembarkedAt !== null
            || $this->travelHomeAt !== null;
    }

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
    public function reconstruction(): array
    {
        return HistoricalPhaseBuilder::build($this);
    }

    public function earliestActualStart(): CarbonInterface
    {
        $candidates = array_values(array_filter([
            $this->mobilisationStartAt,
            $this->joinStandbyAt,
            $this->trainingStartAt,
            $this->trainingEndAt,
            $this->joinedVesselAt,
            $this->disembarkedAt,
            $this->travelHomeAt,
        ]));

        if ($candidates === []) {
            throw new \InvalidArgumentException('At least one meaningful movement date must be supplied.');
        }

        $earliest = $candidates[0];

        foreach ($candidates as $candidate) {
            if ($candidate->lt($earliest)) {
                $earliest = $candidate;
            }
        }

        return $earliest;
    }

    /**
     * Inclusive end of the reconstructed assignment interval.
     * Null means the assignment remains open (current operational bootstrap).
     */
    public function intervalEnd(): ?CarbonInterface
    {
        $reconstruction = $this->reconstruction();

        if ($reconstruction['is_open']) {
            return null;
        }

        return $reconstruction['closed_at'] ?? $this->travelHomeAt ?? $this->disembarkedAt;
    }

    /**
     * @deprecated Use intervalEnd(); retained for callers that still expect a closed end.
     */
    public function latestActualEnd(): CarbonInterface
    {
        return $this->intervalEnd() ?? $this->earliestActualStart();
    }

    public function hasCompletedSeaServicePeriod(): bool
    {
        return $this->joinedVesselAt !== null
            && $this->disembarkedAt !== null
            && $this->joinedVesselAt->lt($this->disembarkedAt);
    }

    public function seaServiceDuration(): array
    {
        if (! $this->hasCompletedSeaServicePeriod()) {
            return ['days' => 0, 'months' => 0];
        }

        return SeaServiceDuration::fromDates(
            $this->joinedVesselAt->toDateString(),
            $this->disembarkedAt->toDateString(),
        );
    }

    /**
     * @return list<array{
     *     phase_code: CrewPhaseCode,
     *     actual_start_at: CarbonInterface,
     *     actual_end_at: ?CarbonInterface,
     *     status: CrewPhaseStatus,
     *     remarks: ?string
     * }>
     */
    public function phasesToCreate(): array
    {
        return $this->reconstruction()['phases'];
    }
}
