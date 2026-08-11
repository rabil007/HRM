export type VesselTypeOption = {
    id: number;
    name: string;
};

export type VesselManningLine = {
    id: number;
    rank_id: number;
    rank_name: string;
    required_count: number;
};

export type VesselRow = {
    id: number;
    name: string;
    vessel_type_id: number;
    vessel_type: { id: number; name: string } | null;
    vessel_type_name: string | null;
    grt: string | number | null;
    bhp: number | null;
    official_no: string | null;
    call_sign: string | null;
    imo_no: string | null;
    certificate_original_filename: string | null;
    certificate_url: string | null;
    is_active: boolean;
    manning: VesselManningLine[];
    total_required: number;
    ranks_configured: number;
};

export type VesselDetails = VesselRow & {
    created_at: string | null;
    updated_at: string | null;
};

export type VesselSummary = {
    manning_ranks: number;
    total_required: number;
    sea_services: number;
    active_crew: number;
};

export type VesselPageCan = {
    create: boolean;
    update: boolean;
    delete: boolean;
    view_manning: boolean;
};

export type VesselFormData = {
    name: string;
    vessel_type_id: number | '';
    grt: string;
    bhp: string;
    official_no: string;
    call_sign: string;
    imo_no: string;
    certificate: File | null;
    is_active: boolean;
};
