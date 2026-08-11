import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from '@/lib/toast';
import {
    createTemplateFieldVisibility,
    getTemplateRequiredFieldKeys,
    isEmptyTemplateFieldValue,
    isTemplateFieldRequired,
} from '@/pages/organization/_lib/template-field-visibility';
import type { TemplateFieldConfig } from '@/pages/organization/employee-page.types';

type UseTemplateRecordFieldsOptions = {
    defaultRequiredFields?: string[];
    booleanFields?: string[];
};

export function useTemplateRecordFields(
    templateFields: Record<string, TemplateFieldConfig> | null | undefined,
    options: UseTemplateRecordFieldsOptions = {},
) {
    const { defaultRequiredFields = [], booleanFields = [] } = options;

    const showField = useMemo(
        () => createTemplateFieldVisibility(templateFields),
        [templateFields],
    );

    const requiredFields = useMemo(
        () =>
            getTemplateRequiredFieldKeys(templateFields, defaultRequiredFields),
        [defaultRequiredFields, templateFields],
    );

    const isFieldRequired = useCallback(
        (fieldKey: string) =>
            isTemplateFieldRequired(
                templateFields,
                fieldKey,
                defaultRequiredFields,
            ),
        [defaultRequiredFields, templateFields],
    );

    const [missingRequiredFields, setMissingRequiredFields] = useState<
        Set<string>
    >(() => new Set());

    const isMissingRequired = useCallback(
        (field: string) => missingRequiredFields.has(field),
        [missingRequiredFields],
    );

    const missingRequiredFieldsList = useMemo(
        () => Array.from(missingRequiredFields),
        [missingRequiredFields],
    );

    const clearMissingRequired = useCallback(() => {
        setMissingRequiredFields(new Set());
    }, []);

    const focusMissingField = useCallback((field: string) => {
        requestAnimationFrame(() => {
            document
                .querySelector(`[data-record-field="${field}"]`)
                ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }, []);

    const isFieldValueEmpty = useCallback(
        (fieldKey: string, value: unknown): boolean => {
            if (booleanFields.includes(fieldKey)) {
                return false;
            }

            return isEmptyTemplateFieldValue(value);
        },
        [booleanFields],
    );

    const validateRequired = useCallback(
        (formData: Record<string, unknown>): boolean => {
            const missing: string[] = [];
            const fieldChecks: Array<{
                field: string;
                shown: boolean;
                empty: boolean;
                valueType: string;
                valuePreview: string;
            }> = [];

            for (const field of requiredFields) {
                const shown = showField(field);
                const empty = isFieldValueEmpty(field, formData[field]);
                const raw = formData[field];
                fieldChecks.push({
                    field,
                    shown,
                    empty,
                    valueType: raw === null ? 'null' : typeof raw,
                    valuePreview:
                        raw === null || raw === undefined
                            ? String(raw)
                            : String(raw).slice(0, 80),
                });

                if (!shown) {
                    continue;
                }

                if (empty) {
                    missing.push(field);
                }
            }

            // #region agent log
            fetch(
                'http://127.0.0.1:7482/ingest/d3b1b2aa-09dd-440b-8cc6-35eab404e1c8',
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Debug-Session-Id': 'c41b01',
                    },
                    body: JSON.stringify({
                        sessionId: 'c41b01',
                        runId: 'pre-fix',
                        hypothesisId: 'A,B,C,D',
                        location:
                            'use-template-record-fields.ts:validateRequired',
                        message: 'validateRequired result',
                        data: {
                            requiredFields: Array.from(requiredFields),
                            formDataKeys: Object.keys(formData),
                            fieldChecks,
                            missing,
                            passed: missing.length === 0,
                            defaultRequiredFields,
                            hasTemplateFields: Boolean(templateFields),
                            templateFieldKeys: templateFields
                                ? Object.keys(templateFields)
                                : [],
                        },
                        timestamp: Date.now(),
                    }),
                },
            ).catch(() => {});
            // #endregion

            if (missing.length > 0) {
                setMissingRequiredFields(new Set(missing));
                toast.error(
                    'Please fill the highlighted required fields before saving.',
                );
                focusMissingField(missing[0]);

                return false;
            }

            setMissingRequiredFields(new Set());

            return true;
        },
        [
            defaultRequiredFields,
            focusMissingField,
            isFieldValueEmpty,
            requiredFields,
            showField,
            templateFields,
        ],
    );

    const syncMissingFromFormData = useCallback(
        (formData: Record<string, unknown>) => {
            if (missingRequiredFields.size === 0) {
                return;
            }

            setMissingRequiredFields((current) => {
                const next = new Set(current);
                let changed = false;

                for (const field of current) {
                    if (!isFieldValueEmpty(field, formData[field])) {
                        next.delete(field);
                        changed = true;
                    }
                }

                return changed ? next : current;
            });
        },
        [isFieldValueEmpty, missingRequiredFields.size],
    );

    return {
        showField,
        isFieldRequired,
        isMissingRequired,
        missingRequiredFieldsList,
        clearMissingRequired,
        focusMissingField,
        validateRequired,
        syncMissingFromFormData,
    };
}

export function useClearMissingOnFormChange(
    formData: Record<string, unknown>,
    syncMissingFromFormData: (formData: Record<string, unknown>) => void,
): void {
    useEffect(() => {
        syncMissingFromFormData(formData);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- clear highlights when field values change
    }, [formData]);
}
