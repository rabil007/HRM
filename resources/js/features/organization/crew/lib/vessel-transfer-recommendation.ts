type CurrentVessel = {
    vessel_id: number | null;
};

/**
 * A transfer is recommended only when the employee is already On Vessel and a
 * different, selected destination vessel has been chosen.
 */
export function recommendsVesselTransfer(
    current: CurrentVessel | null,
    destinationVesselId: number | null,
): boolean {
    if (
        current === null ||
        destinationVesselId === null ||
        destinationVesselId < 1
    ) {
        return false;
    }

    return (
        current.vessel_id === null || current.vessel_id !== destinationVesselId
    );
}
