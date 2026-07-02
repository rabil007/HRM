import { FileText, X } from 'lucide-react';
import type { ReactElement } from 'react';
import { Badge } from '@/components/ui/badge';
import type { DocumentTypeOption } from '@/features/organization/documents/shared/types';
import { formatUploadFileSize } from '@/features/organization/documents/upload/upload-draft';
import type { UploadDraft } from '@/features/organization/documents/upload/upload-draft';
import { cn } from '@/lib/utils';

export type UploadDocumentDraftListItemProps = {
    draft: UploadDraft;
    index: number;
    documentTypes: DocumentTypeOption[];
    selected: boolean;
    hasErrors: boolean;
    onSelect: () => void;
    onRemove: () => void;
};

export function UploadDocumentDraftListItem({
    draft,
    index,
    documentTypes,
    selected,
    hasErrors,
    onSelect,
    onRemove,
}: UploadDocumentDraftListItemProps): ReactElement {
    const typeLabel = documentTypes.find(
        (type) => String(type.id) === draft.document_type_id,
    )?.title;
    const needsType = draft.document_type_id === '';

    return (
        <div
            className={cn(
                'flex items-center justify-between gap-3 rounded-xl border px-3 py-2 transition-colors',
                selected
                    ? 'border-primary/40 bg-primary/5 ring-1 ring-primary/20'
                    : 'border-border bg-background hover:border-border/80 hover:bg-muted/30',
                hasErrors && !selected && 'border-destructive/30',
            )}
        >
            <button
                type="button"
                className="flex min-w-0 flex-1 items-center gap-3 text-left"
                onClick={onSelect}
            >
                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <FileText className="h-4 w-4" />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="truncate text-sm font-medium">
                            {draft.file.name}
                        </span>
                        {typeLabel ? (
                            <Badge variant="secondary" className="text-[10px]">
                                {typeLabel}
                            </Badge>
                        ) : needsType ? (
                            <Badge
                                variant="outline"
                                className="border-amber-500/30 text-[10px] text-amber-600 dark:text-amber-400"
                            >
                                Type required
                            </Badge>
                        ) : null}
                        {hasErrors ? (
                            <Badge
                                variant="destructive"
                                className="text-[10px]"
                            >
                                Fix errors
                            </Badge>
                        ) : null}
                    </div>
                    <div className="mt-0.5 text-xs text-muted-foreground">
                        File {index + 1} · {draft.file.type || 'Unknown'} ·{' '}
                        {formatUploadFileSize(draft.file.size)}
                    </div>
                </div>
            </button>
            <button
                type="button"
                className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                onClick={(event) => {
                    event.stopPropagation();
                    onRemove();
                }}
                aria-label={`Remove ${draft.file.name}`}
            >
                <X className="h-4 w-4" />
            </button>
        </div>
    );
}
