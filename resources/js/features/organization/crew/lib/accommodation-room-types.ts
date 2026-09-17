export type AccommodationRoomTypeOption = {
    id: number;
    name: string;
    hotel_id: number | null;
};

export function roomTypesForHotel(
    roomTypes: AccommodationRoomTypeOption[] | undefined,
    hotelId: number | null | undefined,
    selectedRoomTypeId?: number | null,
): AccommodationRoomTypeOption[] {
    if (hotelId === null || hotelId === undefined) {
        return [];
    }

    const scoped = (roomTypes ?? []).filter(
        (roomType) => roomType.hotel_id === hotelId,
    );

    if (
        selectedRoomTypeId !== null &&
        selectedRoomTypeId !== undefined &&
        !scoped.some((roomType) => roomType.id === selectedRoomTypeId)
    ) {
        const selected = (roomTypes ?? []).find(
            (roomType) => roomType.id === selectedRoomTypeId,
        );

        if (selected !== undefined) {
            return [...scoped, selected];
        }
    }

    return scoped;
}
