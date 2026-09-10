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

export type AnnouncementWhatsAppTemplateOption = {
    id: number;
    label: string;
    slug: string;
    meta_name: string;
    meta_language: string;
    payload_profile: string;
    purpose: string | null;
    body_preview: string;
    header_type: string;
    is_default: boolean;
    is_legacy: boolean;
};

export type AnnouncementFormOptions = {
    company_name: string;
    categories: { value: string; label: string }[];
    priorities: { value: string; label: string }[];
    branches: { id: number; name: string }[];
    departments: { id: number; name: string; parent_id?: number | null }[];
    positions: { id: number; name: string }[];
    employees: { id: number; name: string; employee_no: string | null }[];
    whatsapp_templates: AnnouncementWhatsAppTemplateOption[];
    ai_assist_available: boolean;
    test_destinations?: AnnouncementTestDestinations | null;
};

export type AnnouncementTestDestinations = {
    email: {
        available: boolean;
        masked: string | null;
    };
    whatsapp: {
        available: boolean;
        masked: string | null;
    };
};

export type AnnouncementTestChannelResult = {
    attempted: boolean;
    success: boolean;
    message: string;
};

export type AnnouncementTestSendResponse = {
    ok: boolean;
    message: string;
    destinations: AnnouncementTestDestinations;
    email: AnnouncementTestChannelResult | null;
    whatsapp: AnnouncementTestChannelResult | null;
};

export type AnnouncementFormData = {
    title: string;
    body_html: string;
    category: string;
    priority: string;
    channels: string[];
    whatsapp_link: string;
    whatsapp_message: string;
    whatsapp_template_id: number | null;
    audiences: { type: string; id: number | null }[];
    expires_at: string;
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
    whatsapp_link: string;
    whatsapp_message: string;
    whatsapp_template_id: number | null;
    expires_at: string | null;
    scheduled_at: string | null;
    audiences: { type: string; id: number | null }[];
    attachments: {
        id: number;
        original_name: string;
        mime_type: string;
        size_bytes: number;
    }[];
};

export type AnnouncementChannelPreviews = {
    channels: string[];
    in_app: {
        title: string;
        body_html: string;
        priority_label: string;
        category_label: string;
    } | null;
    email: {
        subject: string;
        html: string;
    } | null;
    whatsapp: {
        template_id: number | null;
        template_label: string | null;
        template_name: string;
        template_language: string;
        payload_profile: string | null;
        header_type: string;
        header_text: string | null;
        body_text: string;
        resolved_message: string | null;
        view_link: string | null;
        available?: boolean;
        message?: string | null;
    } | null;
};

export type AnnouncementShow = AnnouncementListItem & {
    body_html: string;
    expires_at: string | null;
    whatsapp_link: string | null;
    whatsapp_message?: string | null;
    whatsapp_template_id?: number | null;
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
    };
    channel_previews: AnnouncementChannelPreviews;
    recipients: {
        id: number;
        employee_name: string;
        department: string | null;
        in_app: string | null;
        email: string | null;
        whatsapp: string | null;
        read_at: string | null;
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

export type AnnouncementAiAssistAction =
    | 'generate'
    | 'improve'
    | 'make_professional'
    | 'make_friendly'
    | 'shorten'
    | 'fix_grammar'
    | 'create_whatsapp_version'
    | 'suggest_template';

export type AnnouncementAiAssistResult = {
    title: string;
    main_body: string;
    whatsapp_message: string;
    template_purpose: string | null;
    suggested_template: {
        id: number;
        label: string;
        purpose: string;
    } | null;
};

export type AnnouncementAiAssistResponse = {
    ok: boolean;
    result: AnnouncementAiAssistResult;
};

export type AnnouncementChannelPreviewResponse = {
    ok: boolean;
    channel_previews: AnnouncementChannelPreviews;
};
