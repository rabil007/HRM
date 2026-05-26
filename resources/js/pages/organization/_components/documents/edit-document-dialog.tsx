import { useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useEffect } from 'react';
import * as EmployeeDocumentController from '@/actions/App/Http/Controllers/Organization/EmployeeDocumentController';
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
import { actions } from '@/lib/design-system';
import { cn } from '@/lib/utils';
import { EmployeeMissingRequiredFieldsAlert } from '@/pages/organization/_components/employee-missing-required-fields-alert';
import {
    RecordFormField,
    RequiredIndicator,
    recordFieldInputClass,
    recordFieldLabelClass,
} from '@/pages/organization/_components/record-form-field';
import {
    useClearMissingOnFormChange,
    useTemplateRecordFields,
} from '@/pages/organization/_hooks/use-template-record-fields';
import { TEMPLATE_RECORD_DEFAULT_REQUIRED } from '@/pages/organization/_lib/template-record-defaults';
import type { TemplateFieldConfig } from '@/pages/organization/employee-page.types';

export function EditDocumentDialog({
    document,
    employeeId,
    onOpenChange,
    templateFields = null,
}: {
    document: DocumentProfileItem | null;
    employeeId: number;
    onOpenChange: (open: boolean) => void;
    templateFields?: Record<string, TemplateFieldConfig> | null;
}): ReactElement {
    const {
        showField,
        isFieldRequired,
        isMissingRequired,
        missingRequiredFieldsList,
        clearMissingRequired,
        focusMissingField,
        validateRequired,
        syncMissingFromFormData,
    } = useTemplateRecordFields(templateFields, {
        defaultRequiredFields: TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_documents,
    });

    const editForm = useForm({
        title: document?.title ?? '',
        document_number: document?.document_number ?? '',
        issue_date: document?.issue_date ?? '',
        expiry_date: document?.expiry_date ?? '',
        notes: document?.notes ?? '',
    });

    useEffect(() => {
        if (!document) {
            return;
        }

        editForm.setData({
            title: document.title ?? '',
            document_number: document.document_number ?? '',
            issue_date: document.issue_date ?? '',
            expiry_date: document.expiry_date ?? '',
            notes: document.notes ?? '',
        });
        editForm.clearErrors();
        clearMissingRequired();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset when opening a different document
    }, [document?.id]);

    useClearMissingOnFormChange(
        editForm.data as Record<string, unknown>,
        syncMissingFromFormData,
    );

    return (
        <Dialog
            open={!!document}
            onOpenChange={(open) => {
                if (!open) {
                    clearMissingRequired();
                }

                onOpenChange(open);
            }}
        >
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Edit Document</DialogTitle>
                    <p className="text-xs text-muted-foreground">
                        Update the document&apos;s title and metadata.
                    </p>
                </DialogHeader>

                <EmployeeMissingRequiredFieldsAlert
                    missingFields={missingRequiredFieldsList}
                    onFocusField={focusMissingField}
                />

                <div className="space-y-4 py-1">
                    <div className="flex items-center gap-2">
                        <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                            Document details
                        </span>
                        <div className="h-px flex-1 bg-muted/50" />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {showField('title') ? (
                            <RecordFormField
                                field="title"
                                highlightMissing={isMissingRequired('title')}
                                className="sm:col-span-2"
                            >
                                <div className="space-y-1.5">
                                    <Label
                                        className={recordFieldLabelClass(isMissingRequired('title'))}
                                    >
                                        Title
                                        <RequiredIndicator show={isFieldRequired('title')} />
                                    </Label>
                                    <Input
                                        className={cn(
                                            recordFieldInputClass(isMissingRequired('title')),
                                            'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
                                        )}
                                        placeholder="e.g. Passport Copy"
                                        value={editForm.data.title}
                                        onChange={(e) => editForm.setData('title', e.target.value)}
                                    />
                                    {editForm.errors.title ? (
                                        <p className="text-xs text-destructive">
                                            {editForm.errors.title}
                                        </p>
                                    ) : (
                                        <p className="text-[11px] text-muted-foreground">
                                            The document&apos;s title
                                        </p>
                                    )}
                                </div>
                            </RecordFormField>
                        ) : null}
                        {showField('document_number') ? (
                            <RecordFormField
                                field="document_number"
                                highlightMissing={isMissingRequired('document_number')}
                                className="sm:col-span-2"
                            >
                                <div className="space-y-1.5">
                                    <Label
                                        className={recordFieldLabelClass(
                                            isMissingRequired('document_number'),
                                        )}
                                    >
                                        Document Number
                                        <RequiredIndicator
                                            show={isFieldRequired('document_number')}
                                        />
                                    </Label>
                                    <Input
                                        className={cn(
                                            recordFieldInputClass(
                                                isMissingRequired('document_number'),
                                            ),
                                            'h-10 rounded-xl border-border/60 bg-muted/50 font-mono text-sm',
                                        )}
                                        placeholder="e.g. A12345678"
                                        value={editForm.data.document_number}
                                        onChange={(e) =>
                                            editForm.setData('document_number', e.target.value)
                                        }
                                    />
                                    {editForm.errors.document_number ? (
                                        <p className="text-xs text-destructive">
                                            {editForm.errors.document_number}
                                        </p>
                                    ) : null}
                                </div>
                            </RecordFormField>
                        ) : null}
                    </div>

                    {showField('issue_date') || showField('expiry_date') ? (
                        <>
                            <div className="flex items-center gap-2 pt-2">
                                <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                                    Validity
                                </span>
                                <div className="h-px flex-1 bg-muted/50" />
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {showField('issue_date') ? (
                                    <RecordFormField
                                        field="issue_date"
                                        highlightMissing={isMissingRequired('issue_date')}
                                    >
                                        <div className="space-y-1.5">
                                            <Label
                                                className={recordFieldLabelClass(
                                                    isMissingRequired('issue_date'),
                                                )}
                                            >
                                                Issue Date
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
                                                    'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
                                                )}
                                                value={editForm.data.issue_date}
                                                onChange={(e) =>
                                                    editForm.setData('issue_date', e.target.value)
                                                }
                                            />
                                            {editForm.errors.issue_date ? (
                                                <p className="text-xs text-destructive">
                                                    {editForm.errors.issue_date}
                                                </p>
                                            ) : null}
                                        </div>
                                    </RecordFormField>
                                ) : null}
                                {showField('expiry_date') ? (
                                    <RecordFormField
                                        field="expiry_date"
                                        highlightMissing={isMissingRequired('expiry_date')}
                                    >
                                        <div className="space-y-1.5">
                                            <Label
                                                className={recordFieldLabelClass(
                                                    isMissingRequired('expiry_date'),
                                                )}
                                            >
                                                Expiry Date
                                                <RequiredIndicator
                                                    show={isFieldRequired('expiry_date')}
                                                />
                                            </Label>
                                            <Input
                                                type="date"
                                                className={cn(
                                                    recordFieldInputClass(
                                                        isMissingRequired('expiry_date'),
                                                    ),
                                                    'h-10 rounded-xl border-border/60 bg-muted/50 text-sm',
                                                )}
                                                value={editForm.data.expiry_date}
                                                onChange={(e) =>
                                                    editForm.setData('expiry_date', e.target.value)
                                                }
                                            />
                                            {editForm.errors.expiry_date ? (
                                                <p className="text-xs text-destructive">
                                                    {editForm.errors.expiry_date}
                                                </p>
                                            ) : null}
                                        </div>
                                    </RecordFormField>
                                ) : null}
                            </div>
                        </>
                    ) : null}

                    {showField('notes') ? (
                        <RecordFormField
                            field="notes"
                            highlightMissing={isMissingRequired('notes')}
                            className="pt-2"
                        >
                            <div className="space-y-1.5">
                                <Label
                                    className={recordFieldLabelClass(isMissingRequired('notes'))}
                                >
                                    Notes
                                    <RequiredIndicator show={isFieldRequired('notes')} />
                                </Label>
                                <textarea
                                    rows={3}
                                    className={cn(
                                        recordFieldInputClass(isMissingRequired('notes')),
                                        'w-full resize-none rounded-xl border border-border bg-muted/50 px-3 py-2 text-sm text-foreground outline-none focus:ring-1 focus:ring-primary',
                                    )}
                                    placeholder="Optional notes, renewal reminders, or source details..."
                                    value={editForm.data.notes}
                                    onChange={(e) => editForm.setData('notes', e.target.value)}
                                />
                                {editForm.errors.notes ? (
                                    <p className="text-xs text-destructive">{editForm.errors.notes}</p>
                                ) : null}
                            </div>
                        </RecordFormField>
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
                        disabled={editForm.processing}
                        onClick={() => {
                            if (!document) {
                                return;
                            }

                            if (
                                !validateRequired(editForm.data as Record<string, unknown>)
                            ) {
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
                                    onSuccess: () => {
                                        clearMissingRequired();
                                        onOpenChange(false);
                                    },
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
