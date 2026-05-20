import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import type { DocumentBrowseItem } from './types';

export function DocumentUploadedDisplay({
    doc,
    className,
}: {
    doc: Pick<DocumentBrowseItem, 'uploaded_by' | 'uploaded_at'>;
    className?: string;
}) {
    const uploadedBy = doc.uploaded_by?.trim() || null;
    const uploadedAt = doc.uploaded_at ? formatDisplayDate(doc.uploaded_at) : null;

    if (!uploadedBy && (!uploadedAt || uploadedAt === '—')) {
        return <span className={cn('text-muted-foreground', className)}>—</span>;
    }

    return (
        <div className={cn('flex flex-col gap-0.5', className)}>
            <span className="truncate text-sm text-foreground">{uploadedBy ?? '—'}</span>
            {uploadedAt && uploadedAt !== '—' ? (
                <span className="text-xs text-muted-foreground tabular-nums">{uploadedAt}</span>
            ) : null}
        </div>
    );
}
