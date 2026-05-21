import { useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import * as EmployeeDocumentController from '@/actions/App/Http/Controllers/Organization/EmployeeDocumentController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { DocumentProfileItem } from '@/features/organization/documents/shared/types';
import { actions } from '@/lib/design-system';
export function ReplaceDocumentDialog({
    document,
    employeeId,
    onOpenChange,
}: {
    document: DocumentProfileItem | null;
    employeeId: number;
    onOpenChange: (open: boolean) => void;
}): ReactElement {
    const replaceForm = useForm({
        file: null as File | null,
    });

    return (
        <Dialog open={!!document} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Replace Document File</DialogTitle>
                </DialogHeader>
                <div className="space-y-3 py-2">
                    <p className="text-sm text-muted-foreground">
                        The current file will be kept in version history.
                    </p>
                    <input
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png"
                        onChange={(e) => replaceForm.setData('file', e.target.files?.[0] ?? null)}
                        className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                    />
                    {replaceForm.errors.file ? (
                        <p className="text-xs text-destructive">{replaceForm.errors.file}</p>
                    ) : null}
                </div>
                <DialogFooter className="border-t border-border/60 pt-4">
                    <Button
                        variant="outline"
                        size="sm"
                        className={actions.dialogSecondary}
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        size="sm"
                        className={actions.dialogPrimary}
                        disabled={replaceForm.processing}
                        onClick={() => {
                            if (!document) {
                                return;
                            }

                            replaceForm.post(
                                EmployeeDocumentController.replace.url({
                                    employee: employeeId,
                                    document: document.id,
                                }),
                                {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    only: ['documents'],
                                    onSuccess: () => {
                                        onOpenChange(false);
                                        replaceForm.reset();
                                    },
                                },
                            );
                        }}
                    >
                        {replaceForm.processing ? 'Replacing…' : 'Replace'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
