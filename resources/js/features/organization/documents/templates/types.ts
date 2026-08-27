export type CustomTemplate = {
    id: number;
    name: string;
    description: string | null;
    document_type_id: number | null;
    document_type_title: string | null;
    content: string;
    status: 'draft' | 'active' | 'inactive';
    status_label: string;
    created_by: number | null;
    created_by_name: string | null;
    updated_by: number | null;
    updated_by_name: string | null;
    created_at: string | null;
    updated_at: string | null;
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
