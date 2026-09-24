<?php

namespace App\Support\Reports;

use Illuminate\Http\Request;

final class HotelCheckInCheckoutFilters
{
    public function __construct(
        public readonly string $search = '',
        public readonly string $hotelId = '',
        public readonly string $roomTypeId = '',
        public readonly string $stayType = '',
        public readonly string $stayStatus = '',
        public readonly string $accommodationStatus = '',
        public readonly string $checkInFrom = '',
        public readonly string $checkInTo = '',
        public readonly string $checkOutFrom = '',
        public readonly string $checkOutTo = '',
        public readonly string $vesselId = '',
        public readonly string $rankId = '',
        public readonly string $clientId = '',
        public readonly string $sort = 'check_in',
        public readonly string $direction = 'desc',
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: trim((string) $request->query('search', '')),
            hotelId: (string) $request->query('hotel_id', ''),
            roomTypeId: (string) $request->query('room_type_id', ''),
            stayType: (string) $request->query('stay_type', ''),
            stayStatus: (string) $request->query('stay_status', ''),
            accommodationStatus: (string) $request->query('accommodation_status', ''),
            checkInFrom: (string) $request->query('check_in_from', ''),
            checkInTo: (string) $request->query('check_in_to', ''),
            checkOutFrom: (string) $request->query('check_out_from', ''),
            checkOutTo: (string) $request->query('check_out_to', ''),
            vesselId: (string) $request->query('vessel_id', ''),
            rankId: (string) $request->query('rank_id', ''),
            clientId: (string) $request->query('client_id', ''),
            sort: (string) $request->query('sort', 'check_in'),
            direction: strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc',
        );
    }

    /**
     * @return array<string, string>
     */
    public function toQueryArray(): array
    {
        return array_filter(
            $this->toArray(),
            fn (string $value, string $key): bool => $value !== ''
                && ! ($key === 'sort' && $value === 'check_in')
                && ! ($key === 'direction' && $value === 'desc'),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'hotel_id' => $this->hotelId,
            'room_type_id' => $this->roomTypeId,
            'stay_type' => $this->stayType,
            'stay_status' => $this->stayStatus,
            'accommodation_status' => $this->accommodationStatus,
            'check_in_from' => $this->checkInFrom,
            'check_in_to' => $this->checkInTo,
            'check_out_from' => $this->checkOutFrom,
            'check_out_to' => $this->checkOutTo,
            'vessel_id' => $this->vesselId,
            'rank_id' => $this->rankId,
            'client_id' => $this->clientId,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ];
    }
}
