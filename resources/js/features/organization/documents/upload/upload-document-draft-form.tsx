import { AlertTriangle, Copy, Sparkles } from 'lucide-react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import { CreatableSelect } from '@/components/ui/creatable-select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { confidenceLabel } from '@/features/organization/documents/lib/document-ai-review';
import type { DocumentAiReviewState } from '@/features/organization/documents/lib/document-ai-review';
import type { DocumentTypeOption } from '@/features/organization/documents/shared/types';
import type {
    UploadDraft,
    UploadDraftFieldErrors,
    UploadDraftMetadata,
} from '@/features/organization/documents/upload/upload-draft';
import { useCreatableMasterData } from '@/hooks/use-creatable-master-data';
import { useMutableSelectOptions } from '@/hooks/use-mutable-select-options';
import { cn } from '@/lib/utils';
import {
    RecordFormField,
    RequiredIndicator,
    recordFieldInputClass,
    recordFieldLabelClass,
} from '@/pages/organization/_components/record-form-field';

export type UploadDocumentDraftFormProps = {
    draft: UploadDraft;
    documentTypes: DocumentTypeOption[];
    onChange: (patch: Partial<UploadDraftMetadata>) => void;
    fieldErrors?: UploadDraftFieldErrors;
    onApplyToAll?: () => void;
    showApplyToAll: boolean;
    showField?: (fieldKey: string) => boolean;
    isFieldRequired?: (fieldKey: string) => boolean;
    isMissingRequired?: (fieldKey: string) => boolean;
    aiAvailable?: boolean;
    aiBusy?: boolean;
    aiReview?: DocumentAiReviewState;
    aiWarnings?: string[];
    onExtractWithAi?: () => void;
    onApplyAiSuggestion?: (
        field: 'document_number' | 'issue_date' | 'expiry_date',
        value: string,
    ) => void;
};

export function UploadDocumentDraftForm({
    draft,
    documentTypes,
    onChange,
    fieldErrors = {},
    onApplyToAll,
    showApplyToAll,
    showField = () => true,
    isFieldRequired = () => false,
    isMissingRequired = () => false,
    aiAvailable = false,
    aiBusy = false,
    aiReview,
    aiWarnings = [],
    onExtractWithAi,
    onApplyAiSuggestion,
}: UploadDocumentDraftFormProps): ReactElement {
    const {
        selectOptions: documentTypeOptions,
        appendOption: appendDocumentType,
    } = useMutableSelectOptions(
        documentTypes.map((type) => ({
            id: type.id,
            title: type.title,
        })),
        'title',
    );
    const {
        canCreate: canCreateDocumentType,
        createConfig: documentTypeCreateConfig,
    } = useCreatableMasterData('documentType');

    return (
        <div className="space-y-4">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <div className="text-sm font-semibold">
                        Document information
                    </div>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Details for{' '}
                        <span className="font-medium text-foreground">
                            {draft.file.name}
                        </span>
                        . Each file has its own metadata.
                    </p>
                </div>
                {showApplyToAll && onApplyToAll ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 shrink-0 gap-1.5 text-xs"
                        onClick={onApplyToAll}
                    >
                        <Copy className="h-3.5 w-3.5" />
                        Apply to all
                    </Button>
                ) : null}
                {aiAvailable && onExtractWithAi ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 shrink-0 gap-1.5 text-xs"
                        disabled={aiBusy}
                        onClick={onExtractWithAi}
                    >
                        <Sparkles className="h-3.5 w-3.5" />
                        {aiBusy ? 'Extracting…' : 'Extract with AI'}
                    </Button>
                ) : null}
            </div>
            {aiReview && aiReview.status !== 'idle' ? (
                <div className="space-y-2 rounded-xl border border-primary/20 bg-primary/5 p-3 text-xs">
                    <div className="flex items-center justify-between gap-2">
                        <span className="font-semibold">
                            AI extraction review
                        </span>
                        <span className="text-muted-foreground">
                            {aiReview.status === 'extracting'
                                ? 'Extracting…'
                                : aiReview.status === 'failed'
                                  ? 'Failed'
                                  : 'Needs review'}
                        </span>
                    </div>
                    {aiReview.status === 'ready' ? (
                        <div className="space-y-1 text-muted-foreground">
                            <p>
                                Detected type:{' '}
                                <span className="font-medium text-foreground">
                                    {aiReview.detectedDocumentType?.replaceAll(
                                        '_',
                                        ' ',
                                    )}
                                </span>
                            </p>
                            {confidenceLabel(aiReview.overallConfidence) ? (
                                <p>
                                    Overall confidence:{' '}
                                    {confidenceLabel(
                                        aiReview.overallConfidence,
                                    )}
                                </p>
                            ) : null}
                            {(
                                [
                                    'document_number',
                                    'issue_date',
                                    'expiry_date',
                                ] as const
                            ).map((field) => {
                                const suggestion = aiReview.fields[field];

                                if (
                                    !suggestion?.value ||
                                    draft.ai_filled_fields.includes(field)
                                ) {
                                    return null;
                                }

                                return (
                                    <div
                                        key={field}
                                        className="flex items-center justify-between gap-2 rounded-md bg-background/70 px-2 py-1.5"
                                    >
                                        <span>
                                            {field.replaceAll('_', ' ')}:{' '}
                                            <span className="font-medium text-foreground">
                                                {suggestion.value}
                                            </span>{' '}
                                            ·{' '}
                                            {confidenceLabel(
                                                suggestion.confidence,
                                            )}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="h-7 text-xs"
                                            onClick={() =>
                                                onApplyAiSuggestion?.(
                                                    field,
                                                    suggestion.value!,
                                                )
                                            }
                                        >
                                            Use suggestion
                                        </Button>
                                    </div>
                                );
                            })}
                        </div>
                    ) : null}
                    {aiWarnings.map((warning) => (
                        <div
                            key={warning}
                            className="flex gap-1.5 text-amber-700 dark:text-amber-300"
                        >
                            <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                            <span>{warning}</span>
                        </div>
                    ))}
                </div>
            ) : null}

            <div className="space-y-3">
                {showField('document_type_id') ? (
                    <RecordFormField
                        field="document_type_id"
                        highlightMissing={isMissingRequired('document_type_id')}
                    >
                        <div className="space-y-1.5">
                            <Label
                                className={recordFieldLabelClass(
                                    isMissingRequired('document_type_id'),
                                )}
                            >
                                Document Type
                                <RequiredIndicator
                                    show={isFieldRequired('document_type_id')}
                                />
                            </Label>
                            <CreatableSelect
                                value={draft.document_type_id}
                                onValueChange={(value) =>
                                    onChange({ document_type_id: value })
                                }
                                variant="card"
                                placeholder="Select type…"
                                options={documentTypeOptions}
                                onOptionsChange={(next) => {
                                    const added = next.find(
                                        (option) =>
                                            !documentTypeOptions.some(
                                                (existing) =>
                                                    existing.value ===
                                                    option.value,
                                            ),
                                    );

                                    if (added) {
                                        appendDocumentType({
                                            id: added.id,
                                            label: added.label,
                                        });
                                    }
                                }}
                                creatable
                                canCreate={canCreateDocumentType}
                                createConfig={documentTypeCreateConfig}
                            />
                            {fieldErrors.document_type_id ? (
                                <p className="text-xs text-destructive">
                                    {fieldErrors.document_type_id}
                                </p>
                            ) : null}
                        </div>
                    </RecordFormField>
                ) : null}

                {showField('title') ? (
                    <RecordFormField
                        field="title"
                        highlightMissing={isMissingRequired('title')}
                    >
                        <div className="space-y-1.5">
                            <Label
                                className={recordFieldLabelClass(
                                    isMissingRequired('title'),
                                )}
                            >
                                Title
                                <RequiredIndicator
                                    show={isFieldRequired('title')}
                                />
                            </Label>
                            <Input
                                className={cn(
                                    recordFieldInputClass(
                                        isMissingRequired('title'),
                                    ),
                                    'h-10 text-sm',
                                )}
                                placeholder="e.g. Passport Copy"
                                value={draft.title}
                                onChange={(event) =>
                                    onChange({ title: event.target.value })
                                }
                            />
                            {fieldErrors.title ? (
                                <p className="text-xs text-destructive">
                                    {fieldErrors.title}
                                </p>
                            ) : null}
                        </div>
                    </RecordFormField>
                ) : null}

                {showField('document_number') ? (
                    <RecordFormField
                        field="document_number"
                        highlightMissing={isMissingRequired('document_number')}
                    >
                        <div className="space-y-1.5">
                            <Label
                                className={recordFieldLabelClass(
                                    isMissingRequired('document_number'),
                                )}
                            >
                                Document Number{' '}
                                {draft.ai_filled_fields.includes(
                                    'document_number',
                                ) ? (
                                    <span className="ml-1 rounded bg-primary/10 px-1.5 py-0.5 text-[10px] text-primary">
                                        AI
                                    </span>
                                ) : null}
                                <RequiredIndicator
                                    show={isFieldRequired('document_number')}
                                />
                            </Label>
                            <Input
                                className={cn(
                                    recordFieldInputClass(
                                        isMissingRequired('document_number'),
                                    ),
                                    'h-10 text-sm',
                                )}
                                placeholder="e.g. A123456"
                                value={draft.document_number}
                                onChange={(event) =>
                                    onChange({
                                        document_number: event.target.value,
                                    })
                                }
                            />
                            {fieldErrors.document_number ? (
                                <p className="text-xs text-destructive">
                                    {fieldErrors.document_number}
                                </p>
                            ) : null}
                        </div>
                    </RecordFormField>
                ) : null}

                {showField('issue_date') || showField('expiry_date') ? (
                    <div className="grid grid-cols-2 gap-3">
                        {showField('issue_date') ? (
                            <RecordFormField
                                field="issue_date"
                                highlightMissing={isMissingRequired(
                                    'issue_date',
                                )}
                            >
                                <div className="space-y-1.5">
                                    <Label
                                        className={recordFieldLabelClass(
                                            isMissingRequired('issue_date'),
                                        )}
                                    >
                                        Issue Date{' '}
                                        {draft.ai_filled_fields.includes(
                                            'issue_date',
                                        ) ? (
                                            <span className="ml-1 rounded bg-primary/10 px-1.5 py-0.5 text-[10px] text-primary">
                                                AI
                                            </span>
                                        ) : null}
                                        <RequiredIndicator
                                            show={isFieldRequired('issue_date')}
                                        />
                                    </Label>
                                    <Input
                                        type="date"
                                        className={cn(
                                            recordFieldInputClass(
                                                isMissingRequired('issue_date'),
                                            ),
                                            'h-10 text-sm',
                                        )}
                                        value={draft.issue_date}
                                        onChange={(event) =>
                                            onChange({
                                                issue_date: event.target.value,
                                            })
                                        }
                                    />
                                    {fieldErrors.issue_date ? (
                                        <p className="text-xs text-destructive">
                                            {fieldErrors.issue_date}
                                        </p>
                                    ) : null}
                                </div>
                            </RecordFormField>
                        ) : null}
                        {showField('expiry_date') ? (
                            <RecordFormField
                                field="expiry_date"
                                highlightMissing={isMissingRequired(
                                    'expiry_date',
                                )}
                            >
                                <div className="space-y-1.5">
                                    <Label
                                        className={recordFieldLabelClass(
                                            isMissingRequired('expiry_date'),
                                        )}
                                    >
                                        Expiry Date{' '}
                                        {draft.ai_filled_fields.includes(
                                            'expiry_date',
                                        ) ? (
                                            <span className="ml-1 rounded bg-primary/10 px-1.5 py-0.5 text-[10px] text-primary">
                                                AI
                                            </span>
                                        ) : null}
                                        <RequiredIndicator
                                            show={isFieldRequired(
                                                'expiry_date',
                                            )}
                                        />
                                    </Label>
                                    <Input
                                        type="date"
                                        className={cn(
                                            recordFieldInputClass(
                                                isMissingRequired(
                                                    'expiry_date',
                                                ),
                                            ),
                                            'h-10 text-sm',
                                        )}
                                        value={draft.expiry_date}
                                        onChange={(event) =>
                                            onChange({
                                                expiry_date: event.target.value,
                                            })
                                        }
                                    />
                                    {fieldErrors.expiry_date ? (
                                        <p className="text-xs text-destructive">
                                            {fieldErrors.expiry_date}
                                        </p>
                                    ) : null}
                                </div>
                            </RecordFormField>
                        ) : null}
                    </div>
                ) : null}

                {showField('notes') ? (
                    <RecordFormField
                        field="notes"
                        highlightMissing={isMissingRequired('notes')}
                    >
                        <div className="space-y-1.5">
                            <Label
                                className={recordFieldLabelClass(
                                    isMissingRequired('notes'),
                                )}
                            >
                                Notes
                                <RequiredIndicator
                                    show={isFieldRequired('notes')}
                                />
                            </Label>
                            <textarea
                                rows={4}
                                className={cn(
                                    recordFieldInputClass(
                                        isMissingRequired('notes'),
                                    ),
                                    'w-full resize-none rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus:ring-1 focus:ring-primary',
                                )}
                                placeholder="Optional notes, renewal reminders, or source details…"
                                value={draft.notes}
                                onChange={(event) =>
                                    onChange({ notes: event.target.value })
                                }
                            />
                            {fieldErrors.notes ? (
                                <p className="text-xs text-destructive">
                                    {fieldErrors.notes}
                                </p>
                            ) : null}
                        </div>
                    </RecordFormField>
                ) : null}

                {fieldErrors.file ? (
                    <p className="text-xs text-destructive">
                        {fieldErrors.file}
                    </p>
                ) : null}
            </div>
        </div>
    );
}
