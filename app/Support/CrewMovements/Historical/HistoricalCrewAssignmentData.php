<?php

namespace App\Support\CrewMovements\Historical;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Support\Employees\SeaServiceDuration;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Simplified past-crew operational periods (UI/import input).
 *
 * Maps onto canonical CrewAssignment → CrewAssignmentPhase records.
 * Does not invent Pre-Mobilisation, Travel, Training, or Ready-to-Join phases.
 */
final class HistoricalCrewAssignmentData
{
    public const SOURCE_MANUAL = 'historical_manual';

    public const SOURCE_IMPORT = 'historical_import';

    public const ACCOMMODATION_NOT_RECORDED = 'not_recorded';

    public const ACCOMMODATION_NO_ACCOMMODATION = 'no_accommodation';

    public const ACCOMMODATION_HOTEL = 'hotel';

    public const AMBIGUOUS_CURRENT_STATE_MESSAGE = 'All entered movement periods are closed. Enter Home / Available From, or leave the employee’s current movement period open.';

    public function __construct(
        public readonly int $companyId,
        public readonly int $employeeId,
        public readonly int $vesselId,
        public readonly int $rankId,
        public readonly ?int $clientId,
        public readonly string $timezone,
        public readonly ?CarbonInterface $signOnStandbyFrom = null,
        public readonly ?CarbonInterface $signOnStandbyTo = null,
        public readonly ?CarbonInterface $onsiteFrom = null,
        public readonly ?CarbonInterface $onsiteTo = null,
        public readonly ?CarbonInterface $signOffStandbyFrom = null,
        public readonly ?CarbonInterface $signOffStandbyTo = null,
        public readonly ?CarbonInterface $homeAvailableFrom = null,
        public readonly ?string $remarks = null,
        public readonly string $signOnAccommodation = self::ACCOMMODATION_NOT_RECORDED,
        public readonly ?int $signOnHotelId = null,
        public readonly ?int $signOnRoomTypeId = null,
        public readonly ?CarbonInterface $signOnHotelCheckIn = null,
        public readonly ?CarbonInterface $signOnHotelCheckOut = null,
        public readonly string $signOffAccommodation = self::ACCOMMODATION_NOT_RECORDED,
        public readonly ?int $signOffHotelId = null,
        public readonly ?int $signOffRoomTypeId = null,
        public readonly ?CarbonInterface $signOffHotelCheckIn = null,
        public readonly ?CarbonInterface $signOffHotelCheckOut = null,
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
            clientId: isset($data['client_id']) && $data['client_id'] !== '' && $data['client_id'] !== null
                ? (int) $data['client_id']
                : null,
            timezone: $timezone,
            signOnStandbyFrom: self::parseTimestamp($data['sign_on_standby_from'] ?? null, $timezone),
            signOnStandbyTo: self::parseTimestamp($data['sign_on_standby_to'] ?? null, $timezone),
            onsiteFrom: self::parseTimestamp($data['onsite_from'] ?? null, $timezone),
            onsiteTo: self::parseTimestamp($data['onsite_to'] ?? null, $timezone),
            signOffStandbyFrom: self::parseTimestamp($data['sign_off_standby_from'] ?? null, $timezone),
            signOffStandbyTo: self::parseTimestamp($data['sign_off_standby_to'] ?? null, $timezone),
            homeAvailableFrom: self::parseTimestamp($data['home_available_from'] ?? null, $timezone),
            remarks: isset($data['remarks']) && is_string($data['remarks']) && trim($data['remarks']) !== ''
                ? trim($data['remarks'])
                : null,
            signOnAccommodation: self::normalizeAccommodationChoice($data['sign_on_accommodation'] ?? null),
            signOnHotelId: self::nullableInt($data['sign_on_hotel_id'] ?? null),
            signOnRoomTypeId: self::nullableInt($data['sign_on_room_type_id'] ?? null),
            signOnHotelCheckIn: self::parseTimestamp($data['sign_on_hotel_check_in'] ?? null, $timezone),
            signOnHotelCheckOut: self::parseTimestamp($data['sign_on_hotel_check_out'] ?? null, $timezone),
            signOffAccommodation: self::normalizeAccommodationChoice($data['sign_off_accommodation'] ?? null),
            signOffHotelId: self::nullableInt($data['sign_off_hotel_id'] ?? null),
            signOffRoomTypeId: self::nullableInt($data['sign_off_room_type_id'] ?? null),
            signOffHotelCheckIn: self::parseTimestamp($data['sign_off_hotel_check_in'] ?? null, $timezone),
            signOffHotelCheckOut: self::parseTimestamp($data['sign_off_hotel_check_out'] ?? null, $timezone),
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

    /**
     * Canonicalize blank/known accommodation labels for FormRequest merging.
     * Blank/null → not_recorded. Unrecognized non-blank values are preserved
     * so Laravel Rule::in can reject them (never silently discarded).
     */
    public static function canonicalizeAccommodationInput(mixed $value): mixed
    {
        if ($value === null) {
            return self::ACCOMMODATION_NOT_RECORDED;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return $value;
        }

        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return self::ACCOMMODATION_NOT_RECORDED;
        }

        $normalized = mb_strtolower($trimmed);
        $normalized = preg_replace('/[\s\-]+/', '_', $normalized) ?? $normalized;

        return match ($normalized) {
            'not_recorded', 'notrecorded' => self::ACCOMMODATION_NOT_RECORDED,
            'no_accommodation', 'noaccommodation', 'none' => self::ACCOMMODATION_NO_ACCOMMODATION,
            'hotel' => self::ACCOMMODATION_HOTEL,
            default => $trimmed,
        };
    }

    /**
     * Strict accommodation parse for domain persistence/import.
     * Blank/null → not_recorded. Non-blank unrecognized values throw.
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizeAccommodationChoice(mixed $value): string
    {
        $canonical = self::canonicalizeAccommodationInput($value);

        if (! is_string($canonical) || ! in_array($canonical, [
            self::ACCOMMODATION_NOT_RECORDED,
            self::ACCOMMODATION_NO_ACCOMMODATION,
            self::ACCOMMODATION_HOTEL,
        ], true)) {
            $display = is_scalar($value) ? trim((string) $value) : 'provided';

            throw new \InvalidArgumentException(
                "Accommodation value \"{$display}\" is invalid. Use Not recorded, No accommodation, or Hotel.",
            );
        }

        return $canonical;
    }

    /**
     * @return list<string>
     */
    public static function accommodationChoices(): array
    {
        return [
            self::ACCOMMODATION_NOT_RECORDED,
            self::ACCOMMODATION_NO_ACCOMMODATION,
            self::ACCOMMODATION_HOTEL,
        ];
    }

    public static function inclusiveDays(?CarbonInterface $from, ?CarbonInterface $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        $start = CarbonImmutable::parse($from->toDateString())->startOfDay();
        $end = CarbonImmutable::parse($to->toDateString())->startOfDay();

        if ($end->lt($start)) {
            return null;
        }

        return (int) $start->diffInDays($end) + 1;
    }

    public function hasAnyMovementPeriod(): bool
    {
        return $this->signOnStandbyFrom !== null
            || $this->onsiteFrom !== null
            || $this->signOffStandbyFrom !== null
            || $this->homeAvailableFrom !== null;
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     phase_code: CrewPhaseCode,
     *     from: CarbonInterface,
     *     to: ?CarbonInterface
     * }>
     */
    public function enteredOperationalPeriods(): array
    {
        $periods = [];

        if ($this->signOnStandbyFrom !== null) {
            $periods[] = [
                'key' => 'sign_on_standby',
                'label' => 'Sign-On Standby',
                'phase_code' => CrewPhaseCode::JoinStandby,
                'from' => $this->signOnStandbyFrom,
                'to' => $this->signOnStandbyTo,
            ];
        }

        if ($this->onsiteFrom !== null) {
            $periods[] = [
                'key' => 'onsite',
                'label' => 'On Vessel',
                'phase_code' => CrewPhaseCode::OnVessel,
                'from' => $this->onsiteFrom,
                'to' => $this->onsiteTo,
            ];
        }

        if ($this->signOffStandbyFrom !== null) {
            $periods[] = [
                'key' => 'sign_off_standby',
                'label' => 'Sign-Off Standby',
                'phase_code' => CrewPhaseCode::DemobStandby,
                'from' => $this->signOffStandbyFrom,
                'to' => $this->signOffStandbyTo,
            ];
        }

        return $periods;
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
    public function reconstruction(): array
    {
        return HistoricalPhaseBuilder::build($this);
    }

    public function earliestActualStart(): CarbonInterface
    {
        $candidates = array_values(array_filter([
            $this->signOnStandbyFrom,
            $this->onsiteFrom,
            $this->signOffStandbyFrom,
            $this->homeAvailableFrom,
        ]));

        if ($candidates === []) {
            throw new \InvalidArgumentException('At least one meaningful movement period must be supplied.');
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

        return $reconstruction['closed_at'] ?? $this->homeAvailableFrom;
    }

    /**
     * @deprecated Use intervalEnd(); retained for callers that still expect a closed end.
     */
    public function latestActualEnd(): CarbonInterface
    {
        return $this->intervalEnd() ?? $this->earliestActualStart();
    }

    public function joinedVesselAt(): ?CarbonInterface
    {
        return $this->onsiteFrom;
    }

    public function disembarkedAt(): ?CarbonInterface
    {
        return $this->onsiteTo;
    }

    public function hasCompletedSeaServicePeriod(): bool
    {
        return $this->onsiteFrom !== null
            && $this->onsiteTo !== null
            && $this->onsiteFrom->lte($this->onsiteTo);
    }

    public function seaServiceDuration(): array
    {
        if (! $this->hasCompletedSeaServicePeriod()) {
            return ['days' => 0, 'months' => 0];
        }

        return SeaServiceDuration::fromDates(
            $this->onsiteFrom->toDateString(),
            $this->onsiteTo->toDateString(),
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

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
