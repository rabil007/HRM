export type TemplateVersionSummary = {
    id: number;
    version: number;
    status: 'draft' | 'published' | 'archived';
    status_label: string;
    content: string | null;
    source_pdf_original_name: string | null;
    source_pdf_size_bytes: number | null;
    source_pdf_page_count: number | null;
    placement_count: number;
    has_placements: boolean;
    placement_config?: PlacementConfig | null;
    published_at: string | null;
    created_at: string | null;
    updated_at: string | null;
};

export type CustomTemplate = {
    id: number;
    name: string;
    description: string | null;
    document_type_id: number | null;
    document_type_title: string | null;
    template_format: 'content' | 'pdf_overlay';
    template_format_label: string;
    content: string;
    status: 'draft' | 'active' | 'inactive';
    status_label: string;
    published_version_id: number | null;
    published_version: TemplateVersionSummary | null;
    draft_version: TemplateVersionSummary | null;
    created_by: number | null;
    created_by_name: string | null;
    updated_by: number | null;
    updated_by_name: string | null;
    created_at: string | null;
    updated_at: string | null;
};

export type PdfPlacementItem = {
    id: string;
    field: string;
    page: number;
    x: number; // normalized 0.0 - 1.0
    y: number; // normalized 0.0 - 1.0
    width: number; // normalized 0.0 - 1.0
    height: number; // normalized 0.0 - 1.0
    font_size?: number;
    font_weight?: 'normal' | 'bold';
    text_align?: 'left' | 'center' | 'right';
};

export type PlacementConfig = {
    schema_version: number;
    placements: PdfPlacementItem[];
};

export type MergeField = {
    key: string;
    label: string;
    category: string;
    sample: string;
};

export type DocumentTypeOption = {
    id: number;
    title: string;
};

export type SystemTemplate = {
    key: string;
    label: string;
    supports_esignature: boolean;
};

export type TemplatesPermissions = {
    view_templates: boolean;
    create_templates: boolean;
    update_templates: boolean;
    delete_templates: boolean;
    document_types: boolean;
    signature_placement: boolean;
};
