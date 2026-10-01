export type CrewPositionOption = {
    id: number;
    name: string;
    max_tour_of_duty_days?: number | null;
};

/** @deprecated Use CrewPositionOption */
export type PositionOption = CrewPositionOption;

export type VesselTypeOption = {
    id: number;
    name: string;
};

export type VesselManningLine = {
    id: number;
    position_id: number;
    position_name: string;
    required_count: number;
};

export type VesselManningItem = {
    id: number;
    name: string;
    vessel_type_id: number;
    vessel_type_name: string | null;
    is_active: boolean;
    manning: VesselManningLine[];
    total_required: number;
    positions_configured: number;
};

export type VesselManningShowItem = VesselManningItem & {
    grt: string | null;
    bhp: number | null;
};

export type VesselManningRequirementRow = {
    position_id: string;
    required_count: string;
};

export type VesselManningFormData = {
    requirements: VesselManningRequirementRow[];
};

export type VesselManningPagePermissions = {
    create: boolean;
    update: boolean;
    delete: boolean;
};

export function vesselManningHasWriteActions(
    can: VesselManningPagePermissions,
): boolean {
    return can.create || can.update || can.delete;
}
