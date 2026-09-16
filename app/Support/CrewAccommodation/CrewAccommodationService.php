<?php

namespace App\Support\CrewAccommodation;

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class CrewAccommodationService
{
    /**
     * @return array{
     *     status: 'open_hotel'|'no_accommodation'|'missing',
     *     stay_id: int|null,
     *     hotel_id: int|null,
     *     hotel_name: string|null,
     *     room_type_id: int|null,
     *     room_type_name: string|null,
     *     check_in_date: string|null,
     *     check_out_date: string|null,
     *     stay_days: int|null,
     *     warning: string|null
     * }
     */
    public function preJoinContext(CrewAssignment $assignment, ?string $timezone = null): array
    {
        $timezone ??= CompanyTimezone::forCompanyId((int) $assignment->company_id);
        $openStays = $this->openPreJoinHotelStays($assignment);

        if ($openStays->count() > 1) {
            return [
                'status' => 'missing',
                'stay_id' => null,
                'hotel_id' => null,
                'hotel_name' => null,
                'room_type_id' => null,
                'room_type_name' => null,
                'check_in_date' => null,
                'check_out_date' => null,
                'stay_days' => null,
                'warning' => 'Multiple open pre-join hotel stays were found. Resolve accommodation data before continuing.',
            ];
        }

        $openStay = $openStays->first();

        if ($openStay instanceof CrewAccommodationStay) {
            $openStay->loadMissing(['hotel', 'roomType']);

            return [
                'status' => 'open_hotel',
                'stay_id' => $openStay->id,
                'hotel_id' => $openStay->hotel_id,
                'hotel_name' => $openStay->hotel?->name,
                'room_type_id' => $openStay->room_type_id,
                'room_type_name' => $openStay->roomType?->name,
                'check_in_date' => $openStay->check_in_date?->toDateString(),
                'check_out_date' => null,
                'stay_days' => $this->stayDays($openStay->check_in_date, now($timezone), $timezone),
                'warning' => null,
            ];
        }

        if ($this->hasPreJoinNoAccommodationDecision($assignment)) {
            return [
                'status' => 'no_accommodation',
                'stay_id' => null,
                'hotel_id' => null,
                'hotel_name' => null,
                'room_type_id' => null,
                'room_type_name' => null,
                'check_in_date' => null,
                'check_out_date' => null,
                'stay_days' => null,
                'warning' => null,
            ];
        }

        return [
            'status' => 'missing',
            'stay_id' => null,
            'hotel_id' => null,
            'hotel_name' => null,
            'room_type_id' => null,
            'room_type_name' => null,
            'check_in_date' => null,
            'check_out_date' => null,
            'stay_days' => null,
            'warning' => 'Accommodation information is missing for this pre-join standby.',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function validatePreJoinCheckInPayload(
        CrewAssignment $assignment,
        array $payload,
        CarbonInterface $occurredAt,
    ): void {
        $status = $this->resolveAccommodationStatus($payload);

        if ($status === null) {
            return;
        }

        if ($this->openPreJoinHotelStays($assignment)->isNotEmpty()) {
            throw CrewMovementException::make(
                'An open pre-join hotel stay already exists for this assignment.',
                'pre_join_accommodation_exists',
            );
        }

        if ($this->hasPreJoinNoAccommodationDecision($assignment)) {
            throw CrewMovementException::make(
                'A pre-join no-accommodation decision already exists for this assignment.',
                'pre_join_accommodation_exists',
            );
        }

        if ($status === CrewAccommodationStatus::NoAccommodation) {
            return;
        }

        $companyId = (int) $assignment->company_id;
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $hotelId = isset($payload['hotel_id']) ? (int) $payload['hotel_id'] : 0;
        $checkInDate = $this->parseDate($payload['check_in_date'] ?? null, $timezone);

        if ($hotelId <= 0) {
            throw CrewMovementException::make('Hotel is required for pre-join accommodation.', 'hotel_required');
        }

        if ($checkInDate === null) {
            throw CrewMovementException::make('Check-in date is required for pre-join accommodation.', 'check_in_required');
        }

        $this->assertActiveHotel($companyId, $hotelId);

        if (! empty($payload['room_type_id'])) {
            $this->assertActiveRoomType($companyId, (int) $payload['room_type_id']);
        }

        $arrivalLocalDate = $occurredAt->copy()->timezone($timezone)->startOfDay();

        if ($checkInDate->lt($arrivalLocalDate)) {
            throw CrewMovementException::make(
                'Hotel check-in cannot be before the actual arrival date.',
                'check_in_before_arrival',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordPreJoinCheckIn(
        CrewAssignment $assignment,
        array $payload,
        CrewAssignmentPhase $startedFromPhase,
        ?int $actorId = null,
    ): ?CrewAccommodationStay {
        $status = $this->resolveAccommodationStatus($payload);

        if ($status === null) {
            return null;
        }

        $companyId = (int) $assignment->company_id;

        if ($status === CrewAccommodationStatus::NoAccommodation) {
            return CrewAccommodationStay::query()->create([
                'company_id' => $companyId,
                'crew_assignment_id' => $assignment->id,
                'stay_type' => CrewAccommodationStayType::PreJoin,
                'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
                'started_from_phase_id' => $startedFromPhase->id,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
        }

        $timezone = CompanyTimezone::forCompanyId($companyId);
        $hotelId = (int) $payload['hotel_id'];
        $roomTypeId = ! empty($payload['room_type_id']) ? (int) $payload['room_type_id'] : null;
        $checkInDate = $this->parseDate($payload['check_in_date'] ?? null, $timezone);

        return CrewAccommodationStay::query()->create([
            'company_id' => $companyId,
            'crew_assignment_id' => $assignment->id,
            'hotel_id' => $hotelId,
            'room_type_id' => $roomTypeId,
            'stay_type' => CrewAccommodationStayType::PreJoin,
            'accommodation_status' => CrewAccommodationStatus::Hotel,
            'check_in_date' => $checkInDate,
            'check_out_date' => null,
            'started_from_phase_id' => $startedFromPhase->id,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function validatePreJoinCheckOutPayload(
        CrewAssignment $assignment,
        array $payload,
        CarbonInterface $occurredAt,
    ): void {
        $openStays = $this->openPreJoinHotelStays($assignment);

        if ($openStays->count() > 1) {
            throw CrewMovementException::make(
                'Multiple open pre-join hotel stays were found. Resolve accommodation data before joining the vessel.',
                'pre_join_accommodation_integrity',
            );
        }

        $openStay = $openStays->first();

        if (! $openStay instanceof CrewAccommodationStay) {
            return;
        }

        $timezone = CompanyTimezone::forCompanyId((int) $assignment->company_id);
        $checkOutDate = $this->parseDate($payload['check_out_date'] ?? null, $timezone);
        $joinLocalDate = $occurredAt->copy()->timezone($timezone)->startOfDay();

        if ($checkOutDate === null) {
            throw CrewMovementException::make(
                'Hotel check-out date is required before joining the vessel.',
                'check_out_required',
            );
        }

        if ($openStay->check_in_date !== null && $checkOutDate->lt($openStay->check_in_date)) {
            throw CrewMovementException::make(
                'Hotel check-out cannot be before check-in.',
                'check_out_before_check_in',
            );
        }

        if ($checkOutDate->gt($joinLocalDate)) {
            throw CrewMovementException::make(
                'Hotel check-out cannot be after the actual vessel join date.',
                'check_out_after_join',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordPreJoinCheckOut(
        CrewAssignment $assignment,
        array $payload,
        ?int $actorId = null,
    ): void {
        $openStays = $this->openPreJoinHotelStays($assignment);

        if ($openStays->count() > 1) {
            throw CrewMovementException::make(
                'Multiple open pre-join hotel stays were found. Resolve accommodation data before joining the vessel.',
                'pre_join_accommodation_integrity',
            );
        }

        $openStay = $openStays->first();

        if (! $openStay instanceof CrewAccommodationStay) {
            return;
        }

        $timezone = CompanyTimezone::forCompanyId((int) $assignment->company_id);
        $checkOutDate = $this->parseDate($payload['check_out_date'] ?? null, $timezone);

        if ($checkOutDate === null) {
            throw CrewMovementException::make(
                'Hotel check-out date is required before joining the vessel.',
                'check_out_required',
            );
        }

        $openStay->update([
            'check_out_date' => $checkOutDate,
            'updated_by' => $actorId,
        ]);
    }

    /**
     * @return Collection<int, CrewAccommodationStay>
     */
    public function openPreJoinHotelStays(CrewAssignment $assignment): Collection
    {
        if ($assignment->relationLoaded('accommodationStays')) {
            return $assignment->accommodationStays
                ->filter(fn (CrewAccommodationStay $stay): bool => $stay->stay_type === CrewAccommodationStayType::PreJoin
                    && $stay->accommodation_status === CrewAccommodationStatus::Hotel
                    && $stay->check_out_date === null)
                ->sortBy('id')
                ->values();
        }

        return CrewAccommodationStay::query()
            ->where('company_id', $assignment->company_id)
            ->where('crew_assignment_id', $assignment->id)
            ->where('stay_type', CrewAccommodationStayType::PreJoin)
            ->where('accommodation_status', CrewAccommodationStatus::Hotel)
            ->whereNull('check_out_date')
            ->orderBy('id')
            ->get();
    }

    public function hasPreJoinNoAccommodationDecision(CrewAssignment $assignment): bool
    {
        return $this->hasNoAccommodationDecision($assignment, CrewAccommodationStayType::PreJoin);
    }

    /**
     * @return array{
     *     status: 'open_hotel'|'no_accommodation'|'missing',
     *     stay_id: int|null,
     *     hotel_id: int|null,
     *     hotel_name: string|null,
     *     room_type_id: int|null,
     *     room_type_name: string|null,
     *     check_in_date: string|null,
     *     check_out_date: string|null,
     *     stay_days: int|null,
     *     warning: string|null
     * }
     */
    public function postSignoffContext(CrewAssignment $assignment, ?string $timezone = null): array
    {
        $timezone ??= CompanyTimezone::forCompanyId((int) $assignment->company_id);
        $openStays = $this->openPostSignoffHotelStays($assignment);

        if ($openStays->count() > 1) {
            return [
                'status' => 'missing',
                'stay_id' => null,
                'hotel_id' => null,
                'hotel_name' => null,
                'room_type_id' => null,
                'room_type_name' => null,
                'check_in_date' => null,
                'check_out_date' => null,
                'stay_days' => null,
                'warning' => 'Multiple open post-sign-off hotel stays were found. Resolve accommodation data before returning home.',
            ];
        }

        $openStay = $openStays->first();

        if ($openStay instanceof CrewAccommodationStay) {
            $openStay->loadMissing(['hotel', 'roomType']);

            return [
                'status' => 'open_hotel',
                'stay_id' => $openStay->id,
                'hotel_id' => $openStay->hotel_id,
                'hotel_name' => $openStay->hotel?->name,
                'room_type_id' => $openStay->room_type_id,
                'room_type_name' => $openStay->roomType?->name,
                'check_in_date' => $openStay->check_in_date?->toDateString(),
                'check_out_date' => null,
                'stay_days' => $this->stayDays($openStay->check_in_date, now($timezone), $timezone),
                'warning' => null,
            ];
        }

        if ($this->hasPostSignoffNoAccommodationDecision($assignment)) {
            return [
                'status' => 'no_accommodation',
                'stay_id' => null,
                'hotel_id' => null,
                'hotel_name' => null,
                'room_type_id' => null,
                'room_type_name' => null,
                'check_in_date' => null,
                'check_out_date' => null,
                'stay_days' => null,
                'warning' => null,
            ];
        }

        return [
            'status' => 'missing',
            'stay_id' => null,
            'hotel_id' => null,
            'hotel_name' => null,
            'room_type_id' => null,
            'room_type_name' => null,
            'check_in_date' => null,
            'check_out_date' => null,
            'stay_days' => null,
            'warning' => 'Accommodation information is missing for this post-sign-off standby.',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function validatePostSignoffCheckInPayload(
        CrewAssignment $assignment,
        array $payload,
        CarbonInterface $occurredAt,
    ): void {
        $status = $this->resolveAccommodationStatus($payload);

        if ($status === null) {
            return;
        }

        if ($this->openPostSignoffHotelStays($assignment)->isNotEmpty()) {
            throw CrewMovementException::make(
                'An open post-sign-off hotel stay already exists for this assignment.',
                'post_signoff_accommodation_exists',
            );
        }

        if ($this->hasPostSignoffNoAccommodationDecision($assignment)) {
            throw CrewMovementException::make(
                'A post-sign-off no-accommodation decision already exists for this assignment.',
                'post_signoff_accommodation_exists',
            );
        }

        if ($status === CrewAccommodationStatus::NoAccommodation) {
            return;
        }

        $companyId = (int) $assignment->company_id;
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $hotelId = isset($payload['hotel_id']) ? (int) $payload['hotel_id'] : 0;
        $checkInDate = $this->parseDate($payload['check_in_date'] ?? null, $timezone);

        if ($hotelId <= 0) {
            throw CrewMovementException::make('Hotel is required for post-sign-off accommodation.', 'hotel_required');
        }

        if ($checkInDate === null) {
            throw CrewMovementException::make('Check-in date is required for post-sign-off accommodation.', 'check_in_required');
        }

        $this->assertActiveHotel($companyId, $hotelId);

        if (! empty($payload['room_type_id'])) {
            $this->assertActiveRoomType($companyId, (int) $payload['room_type_id']);
        }

        $disembarkationLocalDate = $occurredAt->copy()->timezone($timezone)->startOfDay();

        if ($checkInDate->lt($disembarkationLocalDate)) {
            throw CrewMovementException::make(
                'Hotel check-in cannot be before the actual disembarkation date.',
                'check_in_before_disembarkation',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordPostSignoffCheckIn(
        CrewAssignment $assignment,
        array $payload,
        CrewAssignmentPhase $startedFromPhase,
        ?int $actorId = null,
    ): ?CrewAccommodationStay {
        $status = $this->resolveAccommodationStatus($payload);

        if ($status === null) {
            return null;
        }

        $companyId = (int) $assignment->company_id;

        if ($status === CrewAccommodationStatus::NoAccommodation) {
            return CrewAccommodationStay::query()->create([
                'company_id' => $companyId,
                'crew_assignment_id' => $assignment->id,
                'stay_type' => CrewAccommodationStayType::PostSignoff,
                'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
                'started_from_phase_id' => $startedFromPhase->id,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
        }

        $timezone = CompanyTimezone::forCompanyId($companyId);
        $hotelId = (int) $payload['hotel_id'];
        $roomTypeId = ! empty($payload['room_type_id']) ? (int) $payload['room_type_id'] : null;
        $checkInDate = $this->parseDate($payload['check_in_date'] ?? null, $timezone);

        return CrewAccommodationStay::query()->create([
            'company_id' => $companyId,
            'crew_assignment_id' => $assignment->id,
            'hotel_id' => $hotelId,
            'room_type_id' => $roomTypeId,
            'stay_type' => CrewAccommodationStayType::PostSignoff,
            'accommodation_status' => CrewAccommodationStatus::Hotel,
            'check_in_date' => $checkInDate,
            'check_out_date' => null,
            'started_from_phase_id' => $startedFromPhase->id,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function validatePostSignoffCheckOutPayload(
        CrewAssignment $assignment,
        array $payload,
        CarbonInterface $occurredAt,
    ): void {
        $openStays = $this->openPostSignoffHotelStays($assignment);

        if ($openStays->count() > 1) {
            throw CrewMovementException::make(
                'Multiple open post-sign-off hotel stays were found. Resolve accommodation data before returning home.',
                'post_signoff_accommodation_integrity',
            );
        }

        $openStay = $openStays->first();

        if (! $openStay instanceof CrewAccommodationStay) {
            return;
        }

        $timezone = CompanyTimezone::forCompanyId((int) $assignment->company_id);
        $checkOutDate = $this->parseDate($payload['check_out_date'] ?? null, $timezone);
        $returnHomeLocalDate = $occurredAt->copy()->timezone($timezone)->startOfDay();

        if ($checkOutDate === null) {
            throw CrewMovementException::make(
                'Hotel check-out date is required before returning home.',
                'check_out_required',
            );
        }

        if ($openStay->check_in_date !== null && $checkOutDate->lt($openStay->check_in_date)) {
            throw CrewMovementException::make(
                'Hotel check-out cannot be before check-in.',
                'check_out_before_check_in',
            );
        }

        if ($checkOutDate->gt($returnHomeLocalDate)) {
            throw CrewMovementException::make(
                'Hotel check-out cannot be after the actual return-home date.',
                'check_out_after_return_home',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordPostSignoffCheckOut(
        CrewAssignment $assignment,
        array $payload,
        ?int $actorId = null,
    ): void {
        $openStays = $this->openPostSignoffHotelStays($assignment);

        if ($openStays->count() > 1) {
            throw CrewMovementException::make(
                'Multiple open post-sign-off hotel stays were found. Resolve accommodation data before returning home.',
                'post_signoff_accommodation_integrity',
            );
        }

        $openStay = $openStays->first();

        if (! $openStay instanceof CrewAccommodationStay) {
            return;
        }

        $timezone = CompanyTimezone::forCompanyId((int) $assignment->company_id);
        $checkOutDate = $this->parseDate($payload['check_out_date'] ?? null, $timezone);

        if ($checkOutDate === null) {
            throw CrewMovementException::make(
                'Hotel check-out date is required before returning home.',
                'check_out_required',
            );
        }

        $openStay->update([
            'check_out_date' => $checkOutDate,
            'updated_by' => $actorId,
        ]);
    }

    /**
     * @return Collection<int, CrewAccommodationStay>
     */
    public function openPostSignoffHotelStays(CrewAssignment $assignment): Collection
    {
        if ($assignment->relationLoaded('accommodationStays')) {
            return $assignment->accommodationStays
                ->filter(fn (CrewAccommodationStay $stay): bool => $stay->stay_type === CrewAccommodationStayType::PostSignoff
                    && $stay->accommodation_status === CrewAccommodationStatus::Hotel
                    && $stay->check_out_date === null)
                ->sortBy('id')
                ->values();
        }

        return CrewAccommodationStay::query()
            ->where('company_id', $assignment->company_id)
            ->where('crew_assignment_id', $assignment->id)
            ->where('stay_type', CrewAccommodationStayType::PostSignoff)
            ->where('accommodation_status', CrewAccommodationStatus::Hotel)
            ->whereNull('check_out_date')
            ->orderBy('id')
            ->get();
    }

    public function hasPostSignoffNoAccommodationDecision(CrewAssignment $assignment): bool
    {
        return $this->hasNoAccommodationDecision($assignment, CrewAccommodationStayType::PostSignoff);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function assignmentAccommodationSummary(CrewAssignment $assignment, ?string $timezone = null): array
    {
        $timezone ??= CompanyTimezone::forCompanyId((int) $assignment->company_id);

        $stays = $assignment->relationLoaded('accommodationStays')
            ? $assignment->accommodationStays
            : CrewAccommodationStay::query()
                ->where('company_id', $assignment->company_id)
                ->where('crew_assignment_id', $assignment->id)
                ->with(['hotel', 'roomType', 'startedFromPhase'])
                ->get();

        $stays = $this->sortAccommodationStaysForHistory($stays);

        return $stays
            ->map(function (CrewAccommodationStay $stay) use ($timezone): array {
                if (! $stay->relationLoaded('hotel')) {
                    $stay->loadMissing(['hotel', 'roomType']);
                }

                $isOpenHotel = $stay->accommodation_status === CrewAccommodationStatus::Hotel
                    && $stay->check_out_date === null;

                return [
                    'id' => $stay->id,
                    'stay_type' => $stay->stay_type->value,
                    'stay_type_label' => $stay->stay_type->label(),
                    'accommodation_status' => $stay->accommodation_status->value,
                    'accommodation_status_label' => $stay->accommodation_status->label(),
                    'hotel_name' => $stay->hotel?->name,
                    'room_type_name' => $stay->roomType?->name,
                    'check_in_date' => $stay->check_in_date?->toDateString(),
                    'check_out_date' => $stay->check_out_date?->toDateString(),
                    'is_open' => $isOpenHotel,
                    'stay_days' => $stay->accommodation_status === CrewAccommodationStatus::Hotel
                        ? $this->stayDays(
                            $stay->check_in_date,
                            $stay->check_out_date ?? now($timezone),
                            $timezone,
                        )
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasNoAccommodationDecision(
        CrewAssignment $assignment,
        CrewAccommodationStayType $stayType,
    ): bool {
        if ($assignment->relationLoaded('accommodationStays')) {
            return $assignment->accommodationStays->contains(
                fn (CrewAccommodationStay $stay): bool => $stay->stay_type === $stayType
                    && $stay->accommodation_status === CrewAccommodationStatus::NoAccommodation,
            );
        }

        return CrewAccommodationStay::query()
            ->where('company_id', $assignment->company_id)
            ->where('crew_assignment_id', $assignment->id)
            ->where('stay_type', $stayType)
            ->where('accommodation_status', CrewAccommodationStatus::NoAccommodation)
            ->exists();
    }

    /**
     * @param  Collection<int, CrewAccommodationStay>  $stays
     * @return Collection<int, CrewAccommodationStay>
     */
    private function sortAccommodationStaysForHistory(Collection $stays): Collection
    {
        return $stays
            ->loadMissing(['hotel', 'roomType', 'startedFromPhase'])
            ->sortBy(fn (CrewAccommodationStay $stay): array => [
                $stay->startedFromPhase?->sequence ?? PHP_INT_MAX,
                $stay->check_in_date?->toDateString() ?? '',
                $stay->id,
            ])
            ->values();
    }

    private function resolveAccommodationStatus(array $payload): ?CrewAccommodationStatus
    {
        if (! array_key_exists('accommodation_status', $payload)
            || $payload['accommodation_status'] === null
            || $payload['accommodation_status'] === '') {
            return null;
        }

        $status = CrewAccommodationStatus::tryFrom((string) $payload['accommodation_status']);

        if ($status === null) {
            throw CrewMovementException::make(
                'Invalid accommodation status.',
                'invalid_accommodation_status',
            );
        }

        return $status;
    }

    private function assertActiveHotel(int $companyId, int $hotelId): void
    {
        $exists = Hotel::query()
            ->whereKey($hotelId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            throw CrewMovementException::make('The selected hotel is invalid or inactive.', 'invalid_hotel');
        }
    }

    private function assertActiveRoomType(int $companyId, int $roomTypeId): void
    {
        $exists = RoomType::query()
            ->whereKey($roomTypeId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            throw CrewMovementException::make('The selected room type is invalid or inactive.', 'invalid_room_type');
        }
    }

    private function parseDate(mixed $value, string $timezone): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value, $timezone)->startOfDay();
    }

    private function stayDays(
        ?CarbonInterface $checkIn,
        CarbonInterface $through,
        string $timezone,
    ): ?int {
        if ($checkIn === null) {
            return null;
        }

        $from = $checkIn->copy()->timezone($timezone)->startOfDay();
        $to = $through->copy()->timezone($timezone)->startOfDay();

        return max(0, (int) $from->diffInDays($to));
    }
}
