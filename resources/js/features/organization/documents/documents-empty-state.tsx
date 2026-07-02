import { FolderOpen, SearchX, ShieldAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { EmptyState } from '@/components/empty-state';
import type { ExpiryFilter } from '@/features/organization/documents/document-expiry';
import { EXPIRY_FILTER_LABELS } from '@/features/organization/documents/document-expiry';
import { cn } from '@/lib/utils';

type EmptyStateContext =
    | 'index-folders'
    | 'index-search'
    | 'index-compliance'
    | 'employee-files';

function resolveEmptyCopy(
    context: EmptyStateContext,
    expiryFilter: ExpiryFilter,
    hasSearch: boolean,
): { title: string; description: string } {
    if (context === 'index-search') {
        return {
            title: 'No matching documents found',
            description: '',
        };
    }

    if (hasSearch) {
        return {
            title: 'No results for your search.',
            description: `Try a different term or switch to another expiry filter.`,
        };
    }

    if (context === 'index-folders') {
        return {
            title: 'No employee folders yet.',
            description:
                'Upload documents from an employee profile to see folders here.',
        };
    }

    if (context === 'employee-files') {
        if (expiryFilter === 'all') {
            return {
                title: 'No documents in this folder.',
                description:
                    'Upload files from the employee profile to see them here.',
            };
        }

        return {
            title: `No ${EXPIRY_FILTER_LABELS[expiryFilter].toLowerCase()} documents.`,
            description:
                'Try another expiry filter or view all documents in this folder.',
        };
    }

    if (expiryFilter === 'expired') {
        return {
            title: 'No expired documents.',
            description:
                'All expiry-tracked documents are currently within their validity period.',
        };
    }

    if (expiryFilter.startsWith('expiring_')) {
        const days = expiryFilter.replace('expiring_', '');

        return {
            title: `No documents expiring within ${days} days.`,
            description: 'Nothing requires renewal in this window right now.',
        };
    }

    return {
        title: 'No documents match this filter.',
        description: 'No expiry-tracked documents found for this filter.',
    };
}

export function DocumentsEmptyState({
    context,
    expiryFilter,
    hasSearch,
    action,
}: {
    context: EmptyStateContext;
    expiryFilter: ExpiryFilter;
    hasSearch: boolean;
    action?: ReactNode;
}) {
    const copy = resolveEmptyCopy(context, expiryFilter, hasSearch);
    const Icon =
        context === 'index-search' || hasSearch
            ? SearchX
            : context === 'index-folders'
              ? FolderOpen
              : ShieldAlert;
    const showSearchHints = context === 'index-search';

    return (
        <EmptyState
            title={copy.title}
            description={
                showSearchHints ? undefined : copy.description || undefined
            }
            action={action}
            icon={
                <div
                    className={cn(
                        'mx-auto mb-4 flex h-10 w-10 items-center justify-center rounded-full',
                        'border border-border bg-muted/30 text-muted-foreground/60 dark:border-white/10 dark:bg-white/[0.03]',
                    )}
                >
                    <Icon className="h-4 w-4" aria-hidden />
                </div>
            }
        >
            {showSearchHints ? (
                <div className="mx-auto mt-2 max-w-md text-sm text-muted-foreground">
                    <p className="mb-2">Try searching by:</p>
                    <ul className="list-inside list-disc space-y-1 text-left">
                        <li>Employee name</li>
                        <li>Employee ID</li>
                        <li>Document number</li>
                        <li>File name</li>
                    </ul>
                </div>
            ) : null}
        </EmptyState>
    );
}
