import type { CrewAssignmentFormOptions } from '@/features/organization/crew/types';

type ClientVesselFields = {
    client_id: number | null;
    vessel_id: number | null;
};

export function filterVesselsForClient(
    vessels: CrewAssignmentFormOptions['vessels'],
    clientId: number | null,
    vesselId: number | null,
): CrewAssignmentFormOptions['vessels'] {
    return vessels.filter((vessel) => {
        if (vesselId !== null && vessel.id === vesselId) {
            return true;
        }

        if (vessel.client_id == null) {
            return false;
        }

        if (clientId === null) {
            return true;
        }

        return vessel.client_id === clientId;
    });
}

export function applyClientChange(
    data: ClientVesselFields,
    formOptions: CrewAssignmentFormOptions,
    value: string,
): ClientVesselFields {
    const nextClientId = value ? Number(value) : null;
    const selectedVessel = formOptions.vessels.find(
        (vessel) => vessel.id === data.vessel_id,
    );
    const vesselMatches =
        selectedVessel != null &&
        selectedVessel.is_active !== false &&
        selectedVessel.client_id != null &&
        nextClientId !== null &&
        selectedVessel.client_id === nextClientId;

    return {
        client_id: nextClientId,
        vessel_id: vesselMatches ? data.vessel_id : null,
    };
}

export function applyVesselChange(
    data: ClientVesselFields,
    formOptions: CrewAssignmentFormOptions,
    value: string,
): ClientVesselFields {
    const nextVesselId = value ? Number(value) : null;
    const selectedVessel = formOptions.vessels.find(
        (vessel) => vessel.id === nextVesselId,
    );

    return {
        vessel_id: nextVesselId,
        client_id:
            selectedVessel?.client_id != null
                ? selectedVessel.client_id
                : data.client_id,
    };
}

export function selectedVesselIsLegacyUnassigned(
    vessels: CrewAssignmentFormOptions['vessels'],
    vesselId: number | null,
): boolean {
    return (
        vesselId !== null &&
        vessels.some(
            (vessel) => vessel.id === vesselId && vessel.client_id == null,
        )
    );
}
