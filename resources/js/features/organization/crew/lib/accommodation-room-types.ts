export type AccommodationRoomTypeOption = {
    id: number;
    name: string;
    hotel_id: number | null;
};

export function roomTypesForHotel(
    roomTypes: AccommodationRoomTypeOption[] | undefined,
    hotelId: number | null | undefined,
): AccommodationRoomTypeOption[] {
    if (hotelId === null || hotelId === undefined) {
        return [];
    }

    return (roomTypes ?? []).filter(
        (roomType) => roomType.hotel_id === hotelId,
    );
}
