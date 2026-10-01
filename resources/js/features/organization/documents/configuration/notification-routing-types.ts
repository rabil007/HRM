export type NotificationRoutingRecipient = {
    id: number;
    kind: 'user' | 'email';
    user_id: number | null;
    name: string | null;
    email: string | null;
    label: string;
    eligible: boolean;
};

export type NotificationRoutingRule = {
    id: number;
    name: string;
    enabled: boolean;
    all_document_types: boolean;
    document_types: Array<{ id: number; title: string; is_active: boolean }>;
    document_types_summary: string;
    to: NotificationRoutingRecipient[];
    cc: NotificationRoutingRecipient[];
    to_summary: string;
    cc_summary: string;
    status_label: string;
};

export type NotificationRoutingDocumentTypeOption = {
    id: number;
    title: string;
    is_active: boolean;
};

export type NotificationRoutingCompanyUser = {
    id: number;
    name: string;
    email: string;
    eligible: boolean;
};

export type NotificationRoutingFormData = {
    name: string;
    enabled: boolean;
    all_document_types: boolean;
    document_type_ids: number[];
    to_user_ids: number[];
    to_emails: string[];
    to_orphaned_recipient_ids: number[];
    cc_user_ids: number[];
    cc_emails: string[];
    cc_orphaned_recipient_ids: number[];
};

export type DocumentTypeExpiryNotificationRuleSummary = {
    id: number;
    name: string;
    enabled: boolean;
    to_summary: string;
    cc_summary: string;
};

export const emptyNotificationRoutingFormData =
    (): NotificationRoutingFormData => ({
        name: '',
        enabled: true,
        all_document_types: false,
        document_type_ids: [],
        to_user_ids: [],
        to_emails: [],
        to_orphaned_recipient_ids: [],
        cc_user_ids: [],
        cc_emails: [],
        cc_orphaned_recipient_ids: [],
    });

export function ruleToFormData(
    rule: NotificationRoutingRule | null,
    highlightDocumentTypeId?: number | null,
): NotificationRoutingFormData {
    if (!rule) {
        return {
            ...emptyNotificationRoutingFormData(),
            document_type_ids:
                highlightDocumentTypeId && highlightDocumentTypeId > 0
                    ? [highlightDocumentTypeId]
                    : [],
        };
    }

    return {
        name: rule.name,
        enabled: rule.enabled,
        all_document_types: rule.all_document_types,
        document_type_ids: rule.document_types.map((type) => type.id),
        to_user_ids: rule.to
            .filter(
                (recipient) => recipient.kind === 'user' && recipient.user_id,
            )
            .map((recipient) => recipient.user_id as number),
        to_emails: rule.to
            .filter(
                (recipient) => recipient.kind === 'email' && recipient.email,
            )
            .map((recipient) => recipient.email as string),
        to_orphaned_recipient_ids: rule.to
            .filter(
                (recipient) =>
                    recipient.kind === 'user' && recipient.user_id === null,
            )
            .map((recipient) => recipient.id),
        cc_user_ids: rule.cc
            .filter(
                (recipient) => recipient.kind === 'user' && recipient.user_id,
            )
            .map((recipient) => recipient.user_id as number),
        cc_emails: rule.cc
            .filter(
                (recipient) => recipient.kind === 'email' && recipient.email,
            )
            .map((recipient) => recipient.email as string),
        cc_orphaned_recipient_ids: rule.cc
            .filter(
                (recipient) =>
                    recipient.kind === 'user' && recipient.user_id === null,
            )
            .map((recipient) => recipient.id),
    };
}
