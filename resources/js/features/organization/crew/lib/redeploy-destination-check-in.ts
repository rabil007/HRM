export function resolveDestinationCheckInDateOnP1AEntry({
    redeployDate,
    autoSynced,
    noHotelAccommodation,
}: {
    redeployDate: string;
    autoSynced: boolean;
    noHotelAccommodation: boolean;
}): string | null {
    if (noHotelAccommodation || redeployDate === '' || !autoSynced) {
        return null;
    }

    return redeployDate;
}
