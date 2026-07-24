import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { Button } from '@/components/ui/button';
import { DocumentsBulkToolbar } from '@/features/organization/documents/shared/bulk-toolbar';

export function OrganizationRecordBulkActions({
    selectedIds,
    itemLabel,
    deleteUrl,
    canDelete,
    onClear,
    reloadOnly,
}: {
    selectedIds: number[];
    itemLabel: string;
    deleteUrl: string;
    canDelete: boolean;
    onClear: () => void;
    reloadOnly: string[];
}) {
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);

    return (
        <>
            <DocumentsBulkToolbar
                count={selectedIds.length}
                itemLabel={itemLabel}
                onClear={onClear}
                actions={
                    canDelete ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="destructive"
                            className="h-8 gap-1.5 text-xs"
                            disabled={isDeleting}
                            onClick={() => setDeleteOpen(true)}
                        >
                            <Trash2 className="h-3.5 w-3.5" />
                            Delete
                        </Button>
                    ) : null
                }
            />

            <ConfirmDeleteDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                title={`Delete selected ${itemLabel}?`}
                description={`This will delete ${selectedIds.length} selected ${itemLabel}. This action cannot be undone.`}
                confirmText={isDeleting ? 'Deleting...' : 'Delete selected'}
                onConfirm={() => {
                    if (selectedIds.length === 0 || isDeleting) {
                        return;
                    }

                    setIsDeleting(true);
                    router.delete(deleteUrl, {
                        data: { ids: selectedIds },
                        preserveScroll: true,
                        only: reloadOnly,
                        onSuccess: () => {
                            onClear();
                            setDeleteOpen(false);
                        },
                        onFinish: () => setIsDeleting(false),
                    });
                }}
            />
        </>
    );
}
