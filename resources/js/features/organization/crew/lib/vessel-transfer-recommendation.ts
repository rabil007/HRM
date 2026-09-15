import type { EmployeeOperationalStatus } from '../types';

type CurrentVessel = {
    vessel_id: number | null;
    can_transfer?: boolean;
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

/**
 * Manual Start may open the Transfer Vessel recommendation when the employee
 * is already On Vessel on a different vessel. Planning handoff must never use
 * that path because the planning row is not carried into the transfer flow.
 */
export function canUseManualTransferRecommendation(
    fromPlanning: boolean,
    bulkMode: boolean,
    currentOnVessel: CurrentVessel | null,
    destinationVesselId: number | null,
): boolean {
    if (fromPlanning || bulkMode) {
        return false;
    }

    return (
        recommendsVesselTransfer(currentOnVessel, destinationVesselId) &&
        currentOnVessel?.can_transfer === true
    );
}

export function hasPlanningStartActiveAssignmentConflict(
    fromPlanning: boolean,
    bulkMode: boolean,
    employeeStatus: EmployeeOperationalStatus | null | undefined,
    canUseRecommendedTransfer: boolean,
): boolean {
    if (bulkMode || !employeeStatus?.has_active_assignment) {
        return false;
    }

    if (fromPlanning) {
        return true;
    }

    return !canUseRecommendedTransfer;
}

export function shouldShowPlanningTransferGuidance(
    employeeStatus: EmployeeOperationalStatus | null | undefined,
    currentOnVessel: CurrentVessel | null,
    destinationVesselId: number | null,
): boolean {
    return (
        employeeStatus?.status === 'on_vessel' &&
        recommendsVesselTransfer(currentOnVessel, destinationVesselId)
    );
}
