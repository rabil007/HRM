import type { VesselPageCan, VesselRow } from '../types';

export type VesselMobileCardModel = {
    title: string;
    subtitle: string | null;
    typeLine: string | null;
    identificationLine: string | null;
    manningLine: string;
    isActive: boolean;
    statusLabel: string;
    attention: string | null;
    showEdit: boolean;
    showDelete: boolean;
};

export function vesselMobileCardModel(
    vessel: VesselRow,
    can: Pick<VesselPageCan, 'update' | 'delete'>,
): VesselMobileCardModel {
    const typeLine =
        (vessel.vessel_type?.name ?? vessel.vessel_type_name)?.trim() || null;
    const identification = [
        vessel.imo_no ? `IMO ${vessel.imo_no}` : null,
        vessel.official_no ? `Official ${vessel.official_no}` : null,
    ]
        .filter((value): value is string => Boolean(value))
        .join(' · ');

    return {
        title: vessel.name,
        subtitle: vessel.call_sign?.trim() || null,
        typeLine,
        identificationLine: identification === '' ? null : identification,
        manningLine:
            vessel.ranks_configured > 0
                ? `${vessel.ranks_configured} ranks · ${vessel.total_required} required`
                : 'No manning configured',
        isActive: vessel.is_active,
        statusLabel: vessel.is_active ? 'Active' : 'Inactive',
        attention:
            vessel.ranks_configured === 0 ? 'Manning not configured' : null,
        showEdit: can.update,
        showDelete: can.delete,
    };
}
