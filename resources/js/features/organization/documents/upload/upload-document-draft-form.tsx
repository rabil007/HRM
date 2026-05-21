import { Copy } from 'lucide-react';
import type { ReactElement } from 'react';
import { CreatableSelect } from '@/components/ui/creatable-select';
import { useCreatableMasterData } from '@/hooks/use-creatable-master-data';
import { useMutableSelectOptions } from '@/hooks/use-mutable-select-options';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { DocumentTypeOption } from '@/features/organization/documents/shared/types';
import type {
    UploadDraft,
    UploadDraftFieldErrors,
    UploadDraftMetadata,
} from '@/features/organization/documents/upload/upload-draft';

export type UploadDocumentDraftFormProps = {
    draft: UploadDraft;
    documentTypes: DocumentTypeOption[];
    onChange: (patch: Partial<UploadDraftMetadata>) => void;
    fieldErrors?: UploadDraftFieldErrors;
    onApplyToAll?: () => void;
    showApplyToAll: boolean;
};

export function UploadDocumentDraftForm({
    draft,
    documentTypes,
    onChange,
    fieldErrors = {},
    onApplyToAll,
    showApplyToAll,
}: UploadDocumentDraftFormProps): ReactElement {
    const { selectOptions: documentTypeOptions, appendOption: appendDocumentType } =
        useMutableSelectOptions(
            documentTypes.map((type) => ({
                id: type.id,
                title: type.title,
            })),
            'title',
        );
    const { canCreate: canCreateDocumentType, createConfig: documentTypeCreateConfig } =
        useCreatableMasterData('documentType');

    return (
        <div className="space-y-4">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <div className="text-sm font-semibold">Document information</div>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Details for <span className="font-medium text-foreground">{draft.file.name}</span>
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
            </div>

            <div className="space-y-3">
                <div className="space-y-1.5">
                    <Label className="text-xs">
                        Document Type <span className="text-destructive">*</span>
                    </Label>
                    <CreatableSelect
                        value={draft.document_type_id}
                        onValueChange={(value) => onChange({ document_type_id: value })}
                        variant="card"
                        placeholder="Select type…"
                        options={documentTypeOptions}
                        onOptionsChange={(next) => {
                            const added = next.find(
                                (option) =>
                                    !documentTypeOptions.some(
                                        (existing) => existing.value === option.value,
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
                        <p className="text-xs text-destructive">{fieldErrors.document_type_id}</p>
                    ) : null}
                </div>

                <div className="space-y-1.5">
                    <Label className="text-xs">Title</Label>
                    <Input
                        className="h-10 text-sm"
                        placeholder="e.g. Passport Copy"
                        value={draft.title}
                        onChange={(event) => onChange({ title: event.target.value })}
                    />
                    {fieldErrors.title ? (
                        <p className="text-xs text-destructive">{fieldErrors.title}</p>
                    ) : null}
                </div>

                <div className="space-y-1.5">
                    <Label className="text-xs">Document Number</Label>
                    <Input
                        className="h-10 text-sm"
                        placeholder="e.g. A123456"
                        value={draft.document_number}
                        onChange={(event) => onChange({ document_number: event.target.value })}
                    />
                    {fieldErrors.document_number ? (
                        <p className="text-xs text-destructive">{fieldErrors.document_number}</p>
                    ) : null}
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div className="space-y-1.5">
                        <Label className="text-xs">Issue Date</Label>
                        <Input
                            type="date"
                            className="h-10 text-sm"
                            value={draft.issue_date}
                            onChange={(event) => onChange({ issue_date: event.target.value })}
                        />
                        {fieldErrors.issue_date ? (
                            <p className="text-xs text-destructive">{fieldErrors.issue_date}</p>
                        ) : null}
                    </div>
                    <div className="space-y-1.5">
                        <Label className="text-xs">Expiry Date</Label>
                        <Input
                            type="date"
                            className="h-10 text-sm"
                            value={draft.expiry_date}
                            onChange={(event) => onChange({ expiry_date: event.target.value })}
                        />
                        {fieldErrors.expiry_date ? (
                            <p className="text-xs text-destructive">{fieldErrors.expiry_date}</p>
                        ) : null}
                    </div>
                </div>

                <div className="space-y-1.5">
                    <Label className="text-xs">Notes</Label>
                    <textarea
                        rows={4}
                        className="w-full resize-none rounded-md border border-input bg-background px-3 py-2 text-sm outline-none focus:ring-1 focus:ring-primary"
                        placeholder="Optional notes, renewal reminders, or source details…"
                        value={draft.notes}
                        onChange={(event) => onChange({ notes: event.target.value })}
                    />
                    {fieldErrors.notes ? (
                        <p className="text-xs text-destructive">{fieldErrors.notes}</p>
                    ) : null}
                </div>

                {fieldErrors.file ? (
                    <p className="text-xs text-destructive">{fieldErrors.file}</p>
                ) : null}
            </div>
        </div>
    );
}
