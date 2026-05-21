import { useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import * as EmployeeDocumentController from '@/actions/App/Http/Controllers/Organization/EmployeeDocumentController';
import { actions } from '@/lib/design-system';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { DocumentProfileItem } from '@/features/organization/documents/shared/types';
export function EditDocumentDialog({
    document,
    employeeId,
    onOpenChange,
}: {
    document: DocumentProfileItem | null;
    employeeId: number;
    onOpenChange: (open: boolean) => void;
}): ReactElement {
    const editForm = useForm({
        title: document?.title ?? '',
        document_number: document?.document_number ?? '',
        issue_date: document?.issue_date ?? '',
        expiry_date: document?.expiry_date ?? '',
        notes: document?.notes ?? '',
    });

    return (
        <Dialog open={!!document} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Edit Document</DialogTitle>
                    <p className="text-xs text-muted-foreground">Update the document&apos;s title and metadata.</p>
                </DialogHeader>

                <div className="space-y-4 py-1">
                    <div className="flex items-center gap-2">
                        <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                            Document details
                        </span>
                        <div className="h-px flex-1 bg-muted/50" />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label className="text-xs">
                                Title <span className="text-red-400">*</span>
                            </Label>
                            <Input
                                className="h-10 rounded-xl border-border/60 bg-muted/50 text-sm"
                                placeholder="e.g. Passport Copy"
                                value={editForm.data.title}
                                onChange={(e) => editForm.setData('title', e.target.value)}
                            />
                            {editForm.errors.title ? (
                                <p className="text-xs text-destructive">{editForm.errors.title}</p>
                            ) : (
                                <p className="text-[11px] text-muted-foreground">The document&apos;s title</p>
                            )}
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs">Document Number</Label>
                            <Input
                                className="h-10 rounded-xl border-border/60 bg-muted/50 text-sm font-mono"
                                placeholder="e.g. A12345678"
                                value={editForm.data.document_number}
                                onChange={(e) => editForm.setData('document_number', e.target.value)}
                            />
                            {editForm.errors.document_number ? (
                                <p className="text-xs text-destructive">{editForm.errors.document_number}</p>
                            ) : null}
                        </div>
                    </div>

                    <div className="flex items-center gap-2 pt-2">
                        <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                            Validity
                        </span>
                        <div className="h-px flex-1 bg-muted/50" />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label className="text-xs">Issue Date</Label>
                            <Input
                                type="date"
                                className="h-10 rounded-xl border-border/60 bg-muted/50 text-sm"
                                value={editForm.data.issue_date}
                                onChange={(e) => editForm.setData('issue_date', e.target.value)}
                            />
                            {editForm.errors.issue_date ? (
                                <p className="text-xs text-destructive">{editForm.errors.issue_date}</p>
                            ) : null}
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs">Expiry Date</Label>
                            <Input
                                type="date"
                                className="h-10 rounded-xl border-border/60 bg-muted/50 text-sm"
                                value={editForm.data.expiry_date}
                                onChange={(e) => editForm.setData('expiry_date', e.target.value)}
                            />
                            {editForm.errors.expiry_date ? (
                                <p className="text-xs text-destructive">{editForm.errors.expiry_date}</p>
                            ) : null}
                        </div>
                    </div>

                    <div className="space-y-1.5 pt-2">
                        <Label className="text-xs">Notes</Label>
                        <textarea
                            rows={3}
                            className="w-full resize-none rounded-xl border border-border bg-muted/50 px-3 py-2 text-sm text-foreground outline-none focus:ring-1 focus:ring-primary"
                            placeholder="Optional notes, renewal reminders, or source details..."
                            value={editForm.data.notes}
                            onChange={(e) => editForm.setData('notes', e.target.value)}
                        />
                        {editForm.errors.notes ? (
                            <p className="text-xs text-destructive">{editForm.errors.notes}</p>
                        ) : null}
                    </div>
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
                        disabled={editForm.processing}
                        onClick={() => {
                            if (!document) {
                                return;
                            }

                            editForm.put(
                                EmployeeDocumentController.update.url({
                                    employee: employeeId,
                                    document: document.id,
                                }),
                                {
                                    preserveScroll: true,
                                    only: ['documents'],
                                    onSuccess: () => onOpenChange(false),
                                },
                            );
                        }}
                    >
                        {editForm.processing ? 'Saving…' : 'Save Changes'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
