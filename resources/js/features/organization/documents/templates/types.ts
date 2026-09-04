import type { LayoutValidationRun } from './lib/layout-validation';

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
    has_signature_placement?: boolean;
    signature_placement_config?: SignaturePlacementConfig | null;
    document_workflow_mode?: TemplateAutomationMode;
    document_signing_mode?: TemplateAutomationMode;
    document_workflow_preset_id?: number | null;
    document_signing_preset_id?: number | null;
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

export type PlacementFontFamily = 'sans' | 'serif';

export type PlacementVerticalAlign = 'top' | 'middle' | 'baseline';

// Base shape shared by all PDF placement items
type BasePdfPlacement = {
    id: string;
    page: number;
    x: number; // normalized 0.0 - 1.0
    y: number; // normalized 0.0 - 1.0
    width: number; // normalized 0.0 - 1.0
    height: number; // normalized 0.0 - 1.0
    font_size?: number;
    font_weight?: 'normal' | 'bold';
    font_family?: PlacementFontFamily;
    font_color?: string;
    text_align?: 'left' | 'center' | 'right';
    vertical_align?: PlacementVerticalAlign;
};

export type PdfFieldPlacement = BasePdfPlacement & {
    type: 'field';
    field: string;
};

export type PdfTextPlacement = BasePdfPlacement & {
    type: 'text';
    text_content: string;
};

// Discriminated union — no field:'' on text placements
export type PdfPlacementItem = PdfFieldPlacement | PdfTextPlacement;

export type PlacementConfig = {
    schema_version: 1 | 2;
    placements: PdfPlacementItem[];
};

/**
 * Normalize a raw persisted placement (which may lack `type` in schema v1)
 * to the discriminated union. Runs only on load — never writes to the DB.
 */
export function normalizeVerticalAlign(
    raw: unknown,
    placementType: 'field' | 'text' = 'field',
): PlacementVerticalAlign {
    if (raw === 'top' || raw === 'middle' || raw === 'baseline') {
        return raw;
    }

    return placementType === 'text' ? 'top' : 'middle';
}

export function normalizeFontColor(raw: unknown): string {
    if (typeof raw === 'string' && /^#[0-9a-fA-F]{6}$/.test(raw)) {
        return raw.toLowerCase();
    }

    return '#000000';
}

export function normalizePlacementItem(
    raw: Record<string, unknown>,
): PdfPlacementItem {
    const base: BasePdfPlacement = {
        id: raw.id as string,
        page: raw.page as number,
        x: raw.x as number,
        y: raw.y as number,
        width: raw.width as number,
        height: raw.height as number,
        font_size: raw.font_size as number | undefined,
        font_weight: raw.font_weight as 'normal' | 'bold' | undefined,
        font_family: (raw.font_family === 'serif' ? 'serif' : 'sans') as
            | 'sans'
            | 'serif',
        font_color: normalizeFontColor(raw.font_color),
        text_align: raw.text_align as 'left' | 'center' | 'right' | undefined,
        vertical_align: normalizeVerticalAlign(
            raw.vertical_align,
            raw.type === 'text' ? 'text' : 'field',
        ),
    };

    if (raw.type === 'text') {
        return {
            ...base,
            type: 'text',
            text_content: (raw.text_content as string) ?? '',
        };
    }

    // Schema v1 (no type) and explicit type:'field' both normalize to field
    return { ...base, type: 'field', field: (raw.field as string) ?? '' };
}

export function normalizePlacementConfig(
    config: PlacementConfig | null | undefined,
): PlacementConfig {
    if (!config) {
        return { schema_version: 2, placements: [] };
    }

    return {
        schema_version: config.schema_version,
        placements: (config.placements ?? []).map((p) =>
            normalizePlacementItem(p as unknown as Record<string, unknown>),
        ),
    };
}

export type SignaturePlacementItem = {
    id: string;
    type: 'signature';
    role: 'subject' | 'manager' | 'company_signatory';
    slot_key?: string;
    page: number;
    x: number;
    y: number;
    width: number;
    height: number;
    required: boolean;
    text_align?: 'left' | 'center' | 'right';
    vertical_align?: PlacementVerticalAlign;
};

export type SignaturePlacementConfig = {
    schema_version: number;
    placements: SignaturePlacementItem[];
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

export type TemplateAutomationMode = 'none' | 'preset' | null;

export type DesignerWorkflowPreset = {
    id: number;
    name: string;
    status: string;
    is_active: boolean;
    stages: Array<{ sequence: number; action_label: string }>;
};

export type DesignerSigningPresetStep = {
    sequence: number;
    recipient_role: 'subject' | 'manager' | 'company_signatory';
    display_label: string;
    slot_key: string;
};

export type DesignerSigningPreset = {
    id: number;
    name: string;
    status: string;
    is_active: boolean;
    steps: DesignerSigningPresetStep[];
};

export type TemplateReadinessIssue = {
    code: string;
    section: 'design' | 'workflow' | 'signing' | 'version';
    severity: 'error' | 'warning' | 'info';
    blocking: boolean;
    message: string;
    meta: Record<string, unknown>;
};

export type TemplateReadiness = {
    ready: boolean;
    blocking_count: number;
    warning_count: number;
    historical: boolean;
    sections: {
        design: TemplateReadinessIssue[];
        workflow: TemplateReadinessIssue[];
        signing: TemplateReadinessIssue[];
        version: TemplateReadinessIssue[];
    };
    issues: TemplateReadinessIssue[];
};

export type AutomationPresetOption = {
    id: number;
    name: string;
};

export type TemplatesPermissions = {
    view_templates: boolean;
    create_templates: boolean;
    update_templates: boolean;
    delete_templates: boolean;
    document_types: boolean;
    generate: boolean;
};

// Lightweight version list item — no heavy config arrays
export type TemplateVersionListItem = {
    id: number;
    version: number;
    status: 'draft' | 'published' | 'archived';
    status_label: string;
    source_pdf_original_name: string | null;
    source_pdf_page_count: number | null;
    placement_count: number;
    has_signature_placement: boolean;
    document_workflow_mode: TemplateAutomationMode;
    document_signing_mode: TemplateAutomationMode;
    document_workflow_preset_id: number | null;
    document_signing_preset_id: number | null;
    published_at: string | null;
    created_at: string | null;
    updated_at: string | null;
};

// Change summary returned by showVersion endpoint
export type VersionChangeSummary = {
    compared_to_version: number;
    pdf_metadata_changed: boolean;
    fields_added: number;
    fields_removed: number;
    fields_moved: number;
    fields_changed: number;
    static_text_added: number;
    static_text_removed: number;
    static_text_moved: number;
    static_text_updated: number;
    signatures_added: string[];
    signatures_removed: string[];
    signatures_moved: string[];
    workflow_preset_changed: boolean;
    signing_preset_changed: boolean;
};

export type VersionDetailResponse = {
    version: TemplateVersionSummary;
    change_summary: VersionChangeSummary | null;
    readiness?: TemplateReadiness;
    layout_validation_run?: LayoutValidationRun | null;
};
