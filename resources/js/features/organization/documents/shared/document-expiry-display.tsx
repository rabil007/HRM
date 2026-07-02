import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { expiryRemainingClass } from './document-expiry';
import { DocumentExpiryBadge } from './document-expiry-badge';
import type { DocumentBrowseItem } from './types';

function formatOptionalDate(value: string | null): string {
    return value ? formatDisplayDate(value) : '—';
}

export function DocumentExpiryDisplay({
    doc,
    showLabel = true,
    className,
}: {
    doc: Pick<
        DocumentBrowseItem,
        'expiry_date' | 'expiry_status' | 'expiry_label'
    >;
    showLabel?: boolean;
    className?: string;
}) {
    return (
        <div className={cn('flex flex-col gap-1', className)}>
            <span>{formatOptionalDate(doc.expiry_date)}</span>
            {showLabel && doc.expiry_date ? (
                <span
                    className={cn(
                        'text-xs',
                        expiryRemainingClass(doc.expiry_status),
                    )}
                >
                    {doc.expiry_label}
                </span>
            ) : null}
        </div>
    );
}

export function DocumentExpiryStatusCell({
    status,
    className,
}: {
    status: string | null | undefined;
    className?: string;
}) {
    return <DocumentExpiryBadge status={status} className={className} />;
}
