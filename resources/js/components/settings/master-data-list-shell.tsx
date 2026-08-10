import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type MasterDataListShellProps = {
    headTitle: string;
    title: string;
    description: string;
    searchPlaceholder: string;
    searchInput: string;
    onSearchChange: (value: string) => void;
    canCreate: boolean;
    createButtonLabel: string;
    onCreate: () => void;
    tableMinWidth?: string;
    isEmpty: boolean;
    emptyLabel?: string;
    emptyState?: ReactNode;
    children: ReactNode;
    sheet?: ReactNode;
    deleteOpen: boolean;
    onDeleteOpenChange: (open: boolean) => void;
    deleteTitle: string;
    deleteDescription: ReactNode;
    deleteConfirmText?: string;
    onConfirmDelete: () => void;
    deleteContentClassName?: string;
    searchInputClassName?: string;
    pagination: {
        currentPage: number;
        lastPage: number;
        from: number | null;
        to: number | null;
        total: number;
        perPage: number;
        onPerPageChange: (perPage: number) => void;
        onPageChange: (page: number) => void;
    };
    paginationLabel?: string;
    headerActions?: ReactNode;
};

export function MasterDataListShell({
    headTitle,
    title,
    description,
    searchPlaceholder,
    searchInput,
    onSearchChange,
    canCreate,
    createButtonLabel,
    onCreate,
    tableMinWidth = 'min-w-[640px]',
    isEmpty,
    emptyLabel,
    emptyState,
    children,
    sheet,
    deleteOpen,
    onDeleteOpenChange,
    deleteTitle,
    deleteDescription,
    deleteConfirmText,
    onConfirmDelete,
    deleteContentClassName,
    searchInputClassName,
    pagination,
    paginationLabel = 'results',
    headerActions,
}: MasterDataListShellProps) {
    return (
        <>
            <Head title={headTitle} />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={title}
                    description={description}
                />

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex-1">
                        <Input
                            value={searchInput}
                            onChange={(event) =>
                                onSearchChange(event.target.value)
                            }
                            placeholder={searchPlaceholder}
                            className={searchInputClassName}
                        />
                    </div>
                    <div className="flex flex-wrap items-center gap-2 sm:justify-end">
                        {headerActions}
                        {canCreate ? (
                            <Button onClick={onCreate}>
                                {createButtonLabel}
                            </Button>
                        ) : null}
                    </div>
                </div>

                <div className="overflow-hidden rounded-xl border border-border/60">
                    <div className="overflow-x-auto">
                        <div className={cn(tableMinWidth)}>
                            {children}

                            {isEmpty && emptyState ? emptyState : null}

                            {isEmpty && emptyLabel && !emptyState ? (
                                <div className="px-4 py-10 text-sm text-muted-foreground">
                                    {emptyLabel}
                                </div>
                            ) : null}
                        </div>
                    </div>
                </div>

                <Pagination {...pagination} label={paginationLabel} />
            </div>

            {sheet}

            <ConfirmDeleteDialog
                open={deleteOpen}
                onOpenChange={onDeleteOpenChange}
                title={deleteTitle}
                description={deleteDescription}
                confirmText={deleteConfirmText}
                onConfirm={onConfirmDelete}
                contentClassName={deleteContentClassName}
            />
        </>
    );
}
