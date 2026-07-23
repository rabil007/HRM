export type AnnouncementListItem = {
    id: number;
    title: string;
    category: string;
    category_label: string;
    priority: string;
    priority_label: string;
    status: string;
    status_label: string;
    channels: string[];
    audience_summary: string;
    scheduled_at: string | null;
    published_at: string | null;
    created_by: string | null;
    created_at: string | null;
};

export type AnnouncementCan = {
    view: boolean;
    create: boolean;
    update: boolean;
    publish: boolean;
    cancel: boolean;
    retry: boolean;
    download_attachments: boolean;
};

export type AnnouncementFormOptions = {
    categories: { value: string; label: string }[];
    priorities: { value: string; label: string }[];
    branches: { id: number; name: string }[];
    departments: { id: number; name: string; parent_id?: number | null }[];
    positions: { id: number; name: string }[];
    employees: { id: number; name: string; employee_no: string | null }[];
};

export type AnnouncementFormData = {
    title: string;
    body_html: string;
    category: string;
    priority: string;
    channels: string[];
    audiences: { type: string; id: number | null }[];
    expires_at: string;
    requires_acknowledgement: boolean;
    publish_mode: 'draft' | 'schedule' | 'send_now';
    scheduled_at: string;
};

export type AnnouncementFormPayload = {
    id: number;
    title: string;
    body_html: string;
    category: string;
    priority: string;
    status: string;
    channels: string[];
    expires_at: string | null;
    scheduled_at: string | null;
    requires_acknowledgement: boolean;
    audiences: { type: string; id: number | null }[];
    attachments: {
        id: number;
        original_name: string;
        mime_type: string;
        size_bytes: number;
    }[];
};

export type AnnouncementShow = AnnouncementListItem & {
    body_html: string;
    expires_at: string | null;
    requires_acknowledgement: boolean;
    published_by: string | null;
    audiences: { type: string; id: number | null }[];
    attachments: {
        id: number;
        original_name: string;
        mime_type: string;
        size_bytes: number;
    }[];
    delivery_summary: {
        total_recipients: number;
        in_app_sent: number;
        email_sent: number;
        whatsapp_sent: number;
        failed: number;
        skipped: number;
        acknowledged: number;
    };
    recipients: {
        id: number;
        employee_name: string;
        department: string | null;
        in_app: string | null;
        email: string | null;
        whatsapp: string | null;
        read_at: string | null;
        acknowledged_at: string | null;
    }[];
};

export type RecipientPreview = {
    selected_employees: number;
    in_app_available: number;
    email_available: number;
    whatsapp_available: number;
    missing_email: number;
    missing_phone: number;
};
