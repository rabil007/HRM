import type { DocumentTypeOption } from '@/features/organization/documents/shared/types';
import type {
    UploadDraft,
    UploadDraftMetadata,
} from '@/features/organization/documents/upload/upload-draft';

export const DOCUMENT_AI_SUPPORTED_TYPE_LABELS = [
    'Passport',
    'Emirates ID',
    'Labour Card',
    'Seaman Book / CDC',
    'Driving License',
    'Visit Visa',
    'Residence Visa',
    'CICPA',
    'HSE Passport',
    'Insurance',
] as const;

export const DOCUMENT_AI_DETECTED_TYPES = [
    'passport',
    'emirates_id',
    'labour_card',
    'seafarer_document',
    'driving_license',
    'uae_visa',
    'cicpa',
    'hse_passport',
    'insurance_document',
    'unknown',
] as const;

export type DocumentAiDetectedType =
    (typeof DOCUMENT_AI_DETECTED_TYPES)[number];

export type DocumentAiFieldName =
    | 'document_number'
    | 'issue_date'
    | 'expiry_date'
    | 'document_type_id';

export type DocumentAiClassification = {
    documentType: DocumentAiDetectedType | null;
    documentSubtype: string | null;
    detectedLabel: string | null;
};

export type DocumentAiReviewState = {
    status: 'idle' | 'extracting' | 'ready' | 'failed';
    contextKey: string | null;
    detectedDocumentType: DocumentAiDetectedType | null;
    detectedDocumentSubtype: string | null;
    detectedLabel: string | null;
    overallConfidence: number | null;
    fields: Partial<
        Record<
            Exclude<DocumentAiFieldName, 'document_type_id'>,
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
    detectedDocumentSubtype: null,
    detectedLabel: null,
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

export function isDocumentAiDetectedType(
    value: string,
): value is DocumentAiDetectedType {
    return (DOCUMENT_AI_DETECTED_TYPES as readonly string[]).includes(value);
}

export function reviewStateFromExtractionResult(result: {
    document_type?: string;
    document_subtype?: string | null;
    detected_label?: string | null;
    confidence?: number;
    fields?: DocumentAiReviewState['fields'];
    warnings?: string[];
}): Omit<DocumentAiReviewState, 'status' | 'contextKey'> {
    const documentType: DocumentAiDetectedType = isDocumentAiDetectedType(
        result.document_type ?? '',
    )
        ? (result.document_type as DocumentAiDetectedType)
        : 'unknown';

    return {
        detectedDocumentType: documentType,
        detectedDocumentSubtype: result.document_subtype ?? null,
        detectedLabel: result.detected_label ?? null,
        overallConfidence:
            typeof result.confidence === 'number' ? result.confidence : null,
        fields: result.fields ?? {},
        warnings: result.warnings ?? [],
    };
}

export function detectedTypeDisplayLabel(
    review: Pick<
        DocumentAiReviewState,
        'detectedDocumentType' | 'detectedDocumentSubtype' | 'detectedLabel'
    >,
): string | null {
    if (review.detectedLabel?.trim()) {
        return review.detectedLabel.trim();
    }

    if (
        !review.detectedDocumentType ||
        review.detectedDocumentType === 'unknown'
    ) {
        return 'Unknown';
    }

    return fallbackLabelForClassification(
        review.detectedDocumentType,
        review.detectedDocumentSubtype,
    );
}

function fallbackLabelForClassification(
    documentType: DocumentAiDetectedType,
    documentSubtype: string | null,
): string {
    const labels: Record<DocumentAiDetectedType, string> = {
        passport: 'Passport',
        emirates_id: 'Emirates ID',
        labour_card: 'Labour Card',
        seafarer_document: 'Seafarer document',
        driving_license: 'Driving License',
        uae_visa: 'UAE Visa',
        cicpa: 'CICPA',
        hse_passport: 'HSE Passport',
        insurance_document: 'Insurance',
        unknown: 'Unknown',
    };

    const subtypeLabels: Record<string, string> = {
        ordinary: 'Ordinary Passport',
        diplomatic: 'Diplomatic Passport',
        service: 'Service Passport',
        official: 'Official Passport',
        emergency: 'Emergency Passport',
        seaman_book: 'Seaman Book',
        cdc: 'Continuous Discharge Certificate',
        discharge_book: 'Discharge Book',
        seafarer_identity_document: "Seafarer's Identity Document",
        visit: 'UAE Visit Visa',
        residence: 'UAE Residence Visa',
        employment: 'UAE Employment Visa',
        tourist: 'UAE Tourist Visa',
        transit: 'UAE Transit Visa',
        card: 'Insurance Card',
        policy: 'Insurance Policy',
        certificate: 'Insurance Certificate',
    };

    if (documentSubtype && subtypeLabels[documentSubtype]) {
        return subtypeLabels[documentSubtype];
    }

    return labels[documentType];
}

export function applyAiFieldsWithoutOverwrite(
    draft: UploadDraft,
    fields: DocumentAiReviewState['fields'],
    options?: {
        classification?: DocumentAiClassification | null;
        documentTypes?: DocumentTypeOption[];
    },
): UploadDraft {
    const next = { ...draft, ai_filled_fields: [...draft.ai_filled_fields] };

    for (const key of [
        'document_number',
        'issue_date',
        'expiry_date',
    ] as const) {
        const value = fields[key]?.value;

        if (value && !draft[key]) {
            next[key] = value;
            next.ai_filled_fields = [
                ...new Set([...next.ai_filled_fields, key]),
            ];
        }
    }

    if (!draft.document_type_id.trim()) {
        const resolvedTypeId = resolveDocumentTypeIdFromAiClassification(
            options?.classification ?? null,
            options?.documentTypes ?? [],
        );

        if (resolvedTypeId) {
            next.document_type_id = resolvedTypeId;
            next.ai_filled_fields = [
                ...new Set([...next.ai_filled_fields, 'document_type_id']),
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

/**
 * Clears only still-AI-owned metadata. Manually edited values keep their
 * content because Phase 3 already drops the AI marker on user edit.
 */
export function clearAiOwnedDraftMetadata(draft: UploadDraft): UploadDraft {
    if (draft.ai_filled_fields.length === 0) {
        return draft;
    }

    const next: UploadDraft = {
        ...draft,
        ai_filled_fields: [],
    };

    for (const field of draft.ai_filled_fields) {
        if (
            field === 'document_number' ||
            field === 'issue_date' ||
            field === 'expiry_date' ||
            field === 'document_type_id'
        ) {
            next[field] = '';
        }
    }

    return next;
}

export function clearAiOwnedDraftsMetadata(
    drafts: UploadDraft[],
): UploadDraft[] {
    return drafts.map(clearAiOwnedDraftMetadata);
}

/**
 * True when draft count leaves bulk mode for a single file.
 * Growing from 1→2+ must keep existing AI-owned values on the first file.
 */
export function shouldClearAiOwnedOnDraftCountChange(
    previousCount: number,
    currentCount: number,
): boolean {
    return previousCount > 1 && currentCount === 1;
}

function normalizeDocumentTypeTitle(title: string): string {
    return title
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
}

type ClassificationKey = string;

function classificationKey(
    documentType: DocumentAiDetectedType,
    documentSubtype: string | null,
): ClassificationKey {
    return documentSubtype
        ? `${documentType}:${documentSubtype}`
        : documentType;
}

const CANONICAL_COMPANY_TYPE_TITLES: Partial<
    Record<ClassificationKey, string>
> = {
    passport: 'Passport',
    'passport:ordinary': 'Passport',
    'passport:diplomatic': 'Diplomatic Passport',
    'passport:service': 'Service Passport',
    'passport:official': 'Official Passport',
    'passport:emergency': 'Emergency Passport',
    emirates_id: 'Emirates ID',
    labour_card: 'Labour Card',
    driving_license: 'Driving License',
    'uae_visa:visit': 'Visit Visa',
    'uae_visa:residence': 'Residence Visa',
    'seafarer_document:seaman_book': 'Seaman Book',
    'seafarer_document:cdc': 'CDC',
    cicpa: 'CICPA',
    hse_passport: 'HSE Passport',
    'insurance_document:card': 'Insurance Card',
    'insurance_document:policy': 'Insurance Policy',
    'insurance_document:certificate': 'Insurance Certificate',
};

const COMPANY_TITLE_ALIASES: Partial<Record<ClassificationKey, string[]>> = {
    'seafarer_document:seaman_book': ["Seaman's Book", 'Seamans Book'],
    'seafarer_document:cdc': [
        'Continuous Discharge Certificate',
        'Continuous Discharge Book',
    ],
    'uae_visa:residence': ['UAE Residence Visa', 'Residence Visa'],
    'uae_visa:visit': ['UAE Visit Visa', 'Visit Visa'],
};

function canonicalTitlesForClassification(
    documentType: DocumentAiDetectedType,
    documentSubtype: string | null,
): string[] {
    const keys = [
        classificationKey(documentType, documentSubtype),
        classificationKey(documentType, null),
    ];

    const titles = new Set<string>();

    for (const key of keys) {
        const canonical = CANONICAL_COMPANY_TYPE_TITLES[key];

        if (canonical) {
            titles.add(canonical);
        }

        for (const alias of COMPANY_TITLE_ALIASES[key] ?? []) {
            titles.add(alias);
        }
    }

    return [...titles];
}

function companyTypeClassificationKey(title: string): ClassificationKey | null {
    const normalized = normalizeDocumentTypeTitle(title);

    for (const [key, canonical] of Object.entries(
        CANONICAL_COMPANY_TYPE_TITLES,
    )) {
        if (
            canonical !== undefined &&
            normalizeDocumentTypeTitle(canonical) === normalized
        ) {
            return key;
        }
    }

    for (const [key, aliases] of Object.entries(COMPANY_TITLE_ALIASES)) {
        for (const alias of aliases ?? []) {
            if (normalizeDocumentTypeTitle(alias) === normalized) {
                return key;
            }
        }
    }

    return null;
}

/**
 * Map normalized AI category/subtype to a company Document Type when the match is deterministic.
 * Never guesses when multiple company types could match.
 */
export function resolveDocumentTypeIdFromAiClassification(
    classification: DocumentAiClassification | null,
    types: DocumentTypeOption[],
): string | null {
    if (
        !classification?.documentType ||
        classification.documentType === 'unknown'
    ) {
        return null;
    }

    const canonicalTitles = canonicalTitlesForClassification(
        classification.documentType,
        classification.documentSubtype,
    );

    if (canonicalTitles.length === 0) {
        return null;
    }

    const normalizedCanonicals = new Set(
        canonicalTitles.map((title) => normalizeDocumentTypeTitle(title)),
    );

    const matches = types.filter((type) =>
        normalizedCanonicals.has(normalizeDocumentTypeTitle(type.title)),
    );

    return matches.length === 1 ? String(matches[0].id) : null;
}

/** @deprecated Use resolveDocumentTypeIdFromAiClassification */
export function resolveDocumentTypeIdFromDetectedCategory(
    detected: DocumentAiDetectedType | null,
    types: DocumentTypeOption[],
): string | null {
    return resolveDocumentTypeIdFromAiClassification(
        detected
            ? {
                  documentType: detected,
                  documentSubtype: null,
                  detectedLabel: null,
              }
            : null,
        types,
    );
}

export function documentTypeMismatch(
    review: Pick<
        DocumentAiReviewState,
        'detectedDocumentType' | 'detectedDocumentSubtype' | 'detectedLabel'
    >,
    selectedId: string,
    types: DocumentTypeOption[],
): string | null {
    if (
        !review.detectedDocumentType ||
        review.detectedDocumentType === 'unknown' ||
        !selectedId
    ) {
        return null;
    }

    const selected = types.find((type) => String(type.id) === selectedId);

    if (!selected) {
        return null;
    }

    const resolvedId = resolveDocumentTypeIdFromAiClassification(
        {
            documentType: review.detectedDocumentType,
            documentSubtype: review.detectedDocumentSubtype,
            detectedLabel: review.detectedLabel,
        },
        types,
    );

    if (resolvedId === selectedId) {
        return null;
    }

    const selectedKey = companyTypeClassificationKey(selected.title);
    const detectedKey = classificationKey(
        review.detectedDocumentType,
        review.detectedDocumentSubtype,
    );

    if (
        selectedKey &&
        detectedKey &&
        selectedKey !== detectedKey &&
        !detectedKey.startsWith(`${review.detectedDocumentType}:`) &&
        !selectedKey.startsWith(`${review.detectedDocumentType}:`)
    ) {
        const detectedLabel =
            detectedTypeDisplayLabel(review) ?? review.detectedDocumentType;

        return `AI detected this file as ${detectedLabel}, but ${selected.title} is currently selected. Please confirm the document type before saving.`;
    }

    if (resolvedId && resolvedId !== selectedId) {
        const detectedLabel =
            detectedTypeDisplayLabel(review) ?? review.detectedDocumentType;

        return `AI detected this file as ${detectedLabel}, but ${selected.title} is currently selected. Please confirm the document type before saving.`;
    }

    return null;
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
