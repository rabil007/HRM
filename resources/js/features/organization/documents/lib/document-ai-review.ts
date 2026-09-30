import type { DocumentTypeOption } from '@/features/organization/documents/shared/types';
import type {
    UploadDraft,
    UploadDraftMetadata,
} from '@/features/organization/documents/upload/upload-draft';

export type DocumentAiFieldName =
    | 'document_number'
    | 'issue_date'
    | 'expiry_date';
export type DocumentAiDetectedType =
    | 'passport'
    | 'emirates_id'
    | 'uae_visa'
    | 'unknown';
export type DocumentAiReviewState = {
    status: 'idle' | 'extracting' | 'ready' | 'failed';
    contextKey: string | null;
    detectedDocumentType: DocumentAiDetectedType | null;
    overallConfidence: number | null;
    fields: Partial<
        Record<
            DocumentAiFieldName,
            {
                value: string | null;
                confidence: number | null;
                accepted?: boolean;
            }
        >
    >;
    warnings: string[];
};

export const idleDocumentAiReview = (): DocumentAiReviewState => ({
    status: 'idle',
    contextKey: null,
    detectedDocumentType: null,
    overallConfidence: null,
    fields: {},
    warnings: [],
});

export function documentAiContextKey(
    employeeId: number | null,
    draft: UploadDraft | null,
): string | null {
    return employeeId && draft
        ? `${employeeId}:${draft.id}:${draft.file.name}:${draft.file.size}:${draft.file.lastModified}`
        : null;
}

export function confidenceLabel(
    value: number | null,
): 'High' | 'Medium' | 'Low' | null {
    if (value === null) {
        return null;
    }

    if (value >= 0.85) {
        return 'High';
    }

    if (value >= 0.6) {
        return 'Medium';
    }

    return 'Low';
}

export function applyAiFieldsWithoutOverwrite(
    draft: UploadDraft,
    fields: DocumentAiReviewState['fields'],
): UploadDraft {
    const next = { ...draft, ai_filled_fields: [...draft.ai_filled_fields] };

    for (const key of [
        'document_number',
        'issue_date',
        'expiry_date',
    ] as DocumentAiFieldName[]) {
        const value = fields[key]?.value;

        if (value && !draft[key]) {
            next[key] = value;
            next.ai_filled_fields = [
                ...new Set([...next.ai_filled_fields, key]),
            ];
        }
    }

    return next;
}

export function applyManualDraftPatch(
    draft: UploadDraft,
    patch: Partial<UploadDraftMetadata>,
): UploadDraft {
    const edited = Object.keys(patch);

    return {
        ...draft,
        ...patch,
        ai_filled_fields: draft.ai_filled_fields.filter(
            (field) => !edited.includes(field),
        ),
    };
}

const TYPE_LABELS: Record<DocumentAiDetectedType, string> = {
    passport: 'Passport',
    emirates_id: 'Emirates ID',
    uae_visa: 'UAE Visa',
    unknown: 'Unknown',
};

function categoryForTitle(title: string): DocumentAiDetectedType | null {
    const value = title
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();

    if (value.includes('passport')) {
        return 'passport';
    }

    if (value.includes('emirates id') || value === 'eid') {
        return 'emirates_id';
    }

    if (
        value.includes('uae visa') ||
        value.includes('residence visa') ||
        value === 'visa'
    ) {
        return 'uae_visa';
    }

    return null;
}

export function documentTypeMismatch(
    detected: DocumentAiDetectedType | null,
    selectedId: string,
    types: DocumentTypeOption[],
): string | null {
    if (!detected || detected === 'unknown' || !selectedId) {
        return null;
    }

    const selected = types.find((type) => String(type.id) === selectedId);

    if (!selected) {
        return null;
    }

    const selectedCategory = categoryForTitle(selected.title);

    if (!selectedCategory || selectedCategory === detected) {
        return null;
    }

    return `AI detected this file as ${TYPE_LABELS[detected]}, but ${selected.title} is currently selected. Please confirm the document type before saving.`;
}

export function uniqueReviewWarnings(
    review: DocumentAiReviewState,
    mismatch: string | null,
): string[] {
    const confidenceWarnings = Object.entries(review.fields).flatMap(
        ([field, detail]) =>
            detail?.value &&
            detail.confidence !== null &&
            detail.confidence < 0.6
                ? [
                      `Low confidence ${field.replaceAll('_', ' ')} — please verify.`,
                  ]
                : [],
    );

    return [
        ...new Set([
            ...(mismatch ? [mismatch] : []),
            ...review.warnings,
            ...confidenceWarnings,
        ]),
    ];
}
