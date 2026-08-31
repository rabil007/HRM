export type OverviewAttentionItem = {
    key: string;
    label: string;
    count: number;
    action: string;
    destination: 'library' | 'requests';
    query: Record<string, string>;
};

export type OverviewComplianceType = {
    document_type_id: number;
    title: string;
    missing: number;
    expiring: number;
    expired: number;
};

export type OverviewSections = {
    overview: boolean;
    library: boolean;
    generate: boolean;
    requests: boolean;
    templates: boolean;
    configuration: boolean;
    activity: boolean;
};
