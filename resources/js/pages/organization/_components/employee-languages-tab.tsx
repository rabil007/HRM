import { useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import {
    destroy as destroyLanguage,
    store as storeLanguage,
    update as updateLanguage,
} from '@/actions/App/Http/Controllers/Organization/EmployeeLanguageController';
import { EmployeeRecordRowActions } from '@/components/employee-record-row-actions';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { TabsContent } from '@/components/ui/tabs';
import { EmployeeRecordDeleteDialog } from '@/features/organization/employees/profile/components/employee-record-delete-dialog';
import { resolveEmployeeIdForSave } from '@/features/organization/employees/profile/resolve-employee-id-for-save';
import { actions } from '@/lib/design-system';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { EmployeeMissingRequiredFieldsAlert } from '@/pages/organization/_components/employee-missing-required-fields-alert';
import {
    EmployeeRecordsActionsHeader,
    EmployeeRecordsPanel,
    EmployeeRecordsTable,
    employeeRecordsTableHeadClass,
    employeeRecordsTableRowClass,
    employeeRecordsTableTdClass,
    employeeRecordsTableThClass,
} from '@/pages/organization/_components/employee-records-panel';
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
import { omitHiddenTemplateRecordFields } from '@/pages/organization/_lib/template-field-visibility';
import { TEMPLATE_RECORD_DEFAULT_REQUIRED } from '@/pages/organization/_lib/template-record-defaults';
import type {
    LanguageItem,
    TemplateFieldConfig,
} from '@/pages/organization/employee-page.types';

const LANGUAGES_RELOAD = {
    preserveScroll: true,
    only: ['languages'],
} as const;

const LANGUAGE_BOOLEAN_FIELDS = [
    'is_spoken',
    'is_written',
    'is_understood',
    'is_mother_tongue',
] as const;

export type EmployeeLanguagesTabProps = {
    employeeId: number | null;
    ensureEmployee?: () => Promise<number>;
    languages: LanguageItem[];
    canManage: boolean;
    templateFields?: Record<string, TemplateFieldConfig> | null;
};

export function EmployeeLanguagesTab({
    employeeId,
    ensureEmployee,
    languages,
    canManage,
    templateFields = null,
}: EmployeeLanguagesTabProps): ReactElement {
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
        defaultRequiredFields:
            TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_languages,
        booleanFields: [...LANGUAGE_BOOLEAN_FIELDS],
    });

    const [languageDialogOpen, setLanguageDialogOpen] = useState(false);
    const [editingLanguage, setEditingLanguage] = useState<LanguageItem | null>(
        null,
    );
    const [deleteLanguageId, setDeleteLanguageId] = useState<number | null>(
        null,
    );

    const languageForm = useForm({
        language_name: '',
        is_spoken: false,
        is_written: false,
        is_understood: false,
        is_mother_tongue: false,
    });

    useClearMissingOnFormChange(
        languageForm.data as Record<string, unknown>,
        syncMissingFromFormData,
    );

    const openCreateDialog = () => {
        languageForm.reset();
        languageForm.clearErrors();
        clearMissingRequired();
        languageForm.setData({
            language_name: '',
            is_spoken: false,
            is_written: false,
            is_understood: false,
            is_mother_tongue: false,
        });
        setEditingLanguage(null);
        setLanguageDialogOpen(true);
    };

    const openEditDialog = (row: LanguageItem) => {
        setEditingLanguage(row);
        languageForm.setData({
            language_name: row.language_name,
            is_spoken: row.is_spoken,
            is_written: row.is_written,
            is_understood: row.is_understood,
            is_mother_tongue: row.is_mother_tongue,
        });
        languageForm.clearErrors();
        clearMissingRequired();
        setLanguageDialogOpen(true);
    };

    const submitLanguage = async () => {
        let resolvedEmployeeId: number;

        try {
            resolvedEmployeeId = await resolveEmployeeIdForSave(
                employeeId,
                ensureEmployee,
            );
        } catch {
            return;
        }

        if (!validateRequired(languageForm.data as Record<string, unknown>)) {
            return;
        }

        languageForm.clearErrors();
        languageForm.transform((data) =>
            omitHiddenTemplateRecordFields(
                {
                    language_name: data.language_name.trim(),
                    is_spoken: !!data.is_spoken,
                    is_written: !!data.is_written,
                    is_understood: !!data.is_understood,
                    is_mother_tongue: !!data.is_mother_tongue,
                },
                templateFields,
            ),
        );

        const url = editingLanguage
            ? updateLanguage.url({
                  employee: resolvedEmployeeId,
                  language: editingLanguage.id,
              })
            : storeLanguage.url({
                  employee: resolvedEmployeeId,
              });

        const options = {
            ...LANGUAGES_RELOAD,
            onSuccess: () => {
                setLanguageDialogOpen(false);
                languageForm.reset();
                setEditingLanguage(null);
                clearMissingRequired();
            },
        };

        if (editingLanguage) {
            languageForm.put(url, options);
        } else {
            languageForm.post(url, options);
        }
    };

    const showProficienciesSection =
        showField('is_spoken') ||
        showField('is_written') ||
        showField('is_understood') ||
        showField('is_mother_tongue');

    return (
        <TabsContent value="languages" className="mt-6">
            <EmployeeRecordsPanel
                title="Languages"
                count={languages.length}
                isEmpty={languages.length === 0}
                emptyMessage="No languages recorded."
                actions={
                    canManage ? (
                        <Button
                            size="sm"
                            className="h-8 gap-1.5 text-xs"
                            type="button"
                            onClick={openCreateDialog}
                        >
                            + Add line
                        </Button>
                    ) : undefined
                }
            >
                <EmployeeRecordsTable className="min-w-[720px]">
                    <thead>
                        <tr className={employeeRecordsTableHeadClass()}>
                            {showField('language_name') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Language
                                </th>
                            ) : null}
                            {showField('is_spoken') ? (
                                <th
                                    className={cn(
                                        employeeRecordsTableThClass(),
                                        'text-center',
                                    )}
                                >
                                    Spoken
                                </th>
                            ) : null}
                            {showField('is_written') ? (
                                <th
                                    className={cn(
                                        employeeRecordsTableThClass(),
                                        'text-center',
                                    )}
                                >
                                    Written
                                </th>
                            ) : null}
                            {showField('is_understood') ? (
                                <th
                                    className={cn(
                                        employeeRecordsTableThClass(),
                                        'text-center',
                                    )}
                                >
                                    Understood
                                </th>
                            ) : null}
                            {showField('is_mother_tongue') ? (
                                <th
                                    className={cn(
                                        employeeRecordsTableThClass(),
                                        'text-center',
                                    )}
                                >
                                    Mother tongue
                                </th>
                            ) : null}
                            <th className={employeeRecordsTableThClass()}>
                                Added
                            </th>
                            {canManage ? (
                                <EmployeeRecordsActionsHeader />
                            ) : null}
                        </tr>
                    </thead>
                    <tbody>
                        {languages.map((row) => (
                            <tr
                                key={row.id}
                                className={employeeRecordsTableRowClass()}
                            >
                                {showField('language_name') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'max-w-[220px] truncate font-medium text-foreground',
                                        )}
                                        title={row.language_name}
                                    >
                                        {row.language_name}
                                    </td>
                                ) : null}
                                {showField('is_spoken') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-center text-xs',
                                        )}
                                    >
                                        {row.is_spoken ? (
                                            <span className="text-emerald-600 dark:text-emerald-400">
                                                ✓
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground/50">
                                                —
                                            </span>
                                        )}
                                    </td>
                                ) : null}
                                {showField('is_written') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-center text-xs',
                                        )}
                                    >
                                        {row.is_written ? (
                                            <span className="text-emerald-600 dark:text-emerald-400">
                                                ✓
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground/50">
                                                —
                                            </span>
                                        )}
                                    </td>
                                ) : null}
                                {showField('is_understood') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-center text-xs',
                                        )}
                                    >
                                        {row.is_understood ? (
                                            <span className="text-emerald-600 dark:text-emerald-400">
                                                ✓
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground/50">
                                                —
                                            </span>
                                        )}
                                    </td>
                                ) : null}
                                {showField('is_mother_tongue') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-center text-xs',
                                        )}
                                    >
                                        {row.is_mother_tongue ? (
                                            <span className="text-emerald-600 dark:text-emerald-400">
                                                ✓
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground/50">
                                                —
                                            </span>
                                        )}
                                    </td>
                                ) : null}
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'text-xs whitespace-nowrap text-muted-foreground',
                                    )}
                                >
                                    {formatDisplayDate(row.created_at)}
                                </td>
                                {canManage ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-right',
                                        )}
                                    >
                                        <EmployeeRecordRowActions
                                            onEdit={() => openEditDialog(row)}
                                            onDelete={() =>
                                                setDeleteLanguageId(row.id)
                                            }
                                        />
                                    </td>
                                ) : null}
                            </tr>
                        ))}
                    </tbody>
                </EmployeeRecordsTable>
            </EmployeeRecordsPanel>
            <Dialog
                open={languageDialogOpen}
                onOpenChange={(openDialog) => {
                    setLanguageDialogOpen(openDialog);

                    if (!openDialog) {
                        languageForm.reset();
                        languageForm.clearErrors();
                        setEditingLanguage(null);
                        clearMissingRequired();
                    }
                }}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editingLanguage ? 'Edit language' : 'Add language'}
                        </DialogTitle>
                        <p className="text-xs text-muted-foreground">
                            Specify the language and the employee's proficiency.
                        </p>
                    </DialogHeader>

                    <EmployeeMissingRequiredFieldsAlert
                        missingFields={missingRequiredFieldsList}
                        onFocusField={focusMissingField}
                    />

                    <div className="space-y-4 py-1">
                        {showField('language_name') ? (
                            <>
                                <div className="flex items-center gap-2">
                                    <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                                        Language details
                                    </span>
                                    <div className="h-px flex-1 bg-muted/50" />
                                </div>
                                <RecordFormField
                                    field="language_name"
                                    highlightMissing={isMissingRequired(
                                        'language_name',
                                    )}
                                >
                                    <Label
                                        className={recordFieldLabelClass(
                                            isMissingRequired('language_name'),
                                        )}
                                    >
                                        Language
                                        <RequiredIndicator
                                            show={isFieldRequired(
                                                'language_name',
                                            )}
                                        />
                                    </Label>
                                    <Input
                                        className={recordFieldInputClass(
                                            isMissingRequired('language_name'),
                                        )}
                                        value={languageForm.data.language_name}
                                        onChange={(e) =>
                                            languageForm.setData(
                                                'language_name',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="e.g. English, Arabic, Spanish"
                                    />
                                    {languageForm.errors.language_name ? (
                                        <p className="text-xs text-destructive">
                                            {languageForm.errors.language_name}
                                        </p>
                                    ) : (
                                        <p className="text-[11px] text-muted-foreground">
                                            The name of the language
                                            {isFieldRequired('language_name')
                                                ? ''
                                                : ' (optional)'}
                                        </p>
                                    )}
                                </RecordFormField>
                            </>
                        ) : null}

                        {showProficienciesSection ? (
                            <>
                                <div className="flex items-center gap-2 pt-2">
                                    <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                                        Proficiencies
                                    </span>
                                    <div className="h-px flex-1 bg-muted/50" />
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {showField('is_spoken') ? (
                                        <RecordFormField
                                            field="is_spoken"
                                            highlightMissing={isMissingRequired(
                                                'is_spoken',
                                            )}
                                        >
                                            <div className="rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                                                <label className="flex items-center gap-3 text-sm text-foreground">
                                                    <Checkbox
                                                        checked={
                                                            languageForm.data
                                                                .is_spoken
                                                        }
                                                        onCheckedChange={(v) =>
                                                            languageForm.setData(
                                                                'is_spoken',
                                                                v === true,
                                                            )
                                                        }
                                                    />
                                                    <div>
                                                        <div className="font-medium">
                                                            Spoken
                                                            <RequiredIndicator
                                                                show={isFieldRequired(
                                                                    'is_spoken',
                                                                )}
                                                            />
                                                        </div>
                                                        <div className="mt-0.5 text-[11px] text-muted-foreground">
                                                            Can converse in this
                                                            language
                                                        </div>
                                                    </div>
                                                </label>
                                            </div>
                                        </RecordFormField>
                                    ) : null}
                                    {showField('is_written') ? (
                                        <RecordFormField
                                            field="is_written"
                                            highlightMissing={isMissingRequired(
                                                'is_written',
                                            )}
                                        >
                                            <div className="rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                                                <label className="flex items-center gap-3 text-sm text-foreground">
                                                    <Checkbox
                                                        checked={
                                                            languageForm.data
                                                                .is_written
                                                        }
                                                        onCheckedChange={(v) =>
                                                            languageForm.setData(
                                                                'is_written',
                                                                v === true,
                                                            )
                                                        }
                                                    />
                                                    <div>
                                                        <div className="font-medium">
                                                            Written
                                                            <RequiredIndicator
                                                                show={isFieldRequired(
                                                                    'is_written',
                                                                )}
                                                            />
                                                        </div>
                                                        <div className="mt-0.5 text-[11px] text-muted-foreground">
                                                            Can write in this
                                                            language
                                                        </div>
                                                    </div>
                                                </label>
                                            </div>
                                        </RecordFormField>
                                    ) : null}
                                    {showField('is_understood') ? (
                                        <RecordFormField
                                            field="is_understood"
                                            highlightMissing={isMissingRequired(
                                                'is_understood',
                                            )}
                                        >
                                            <div className="rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                                                <label className="flex items-center gap-3 text-sm text-foreground">
                                                    <Checkbox
                                                        checked={
                                                            languageForm.data
                                                                .is_understood
                                                        }
                                                        onCheckedChange={(v) =>
                                                            languageForm.setData(
                                                                'is_understood',
                                                                v === true,
                                                            )
                                                        }
                                                    />
                                                    <div>
                                                        <div className="font-medium">
                                                            Understood
                                                            <RequiredIndicator
                                                                show={isFieldRequired(
                                                                    'is_understood',
                                                                )}
                                                            />
                                                        </div>
                                                        <div className="mt-0.5 text-[11px] text-muted-foreground">
                                                            Can understand this
                                                            language
                                                        </div>
                                                    </div>
                                                </label>
                                            </div>
                                        </RecordFormField>
                                    ) : null}
                                    {showField('is_mother_tongue') ? (
                                        <RecordFormField
                                            field="is_mother_tongue"
                                            highlightMissing={isMissingRequired(
                                                'is_mother_tongue',
                                            )}
                                        >
                                            <div className="rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                                                <label className="flex items-center gap-3 text-sm text-foreground">
                                                    <Checkbox
                                                        checked={
                                                            languageForm.data
                                                                .is_mother_tongue
                                                        }
                                                        onCheckedChange={(v) =>
                                                            languageForm.setData(
                                                                'is_mother_tongue',
                                                                v === true,
                                                            )
                                                        }
                                                    />
                                                    <div>
                                                        <div className="font-medium">
                                                            Mother tongue
                                                            <RequiredIndicator
                                                                show={isFieldRequired(
                                                                    'is_mother_tongue',
                                                                )}
                                                            />
                                                        </div>
                                                        <div className="mt-0.5 text-[11px] text-muted-foreground">
                                                            Native language
                                                        </div>
                                                    </div>
                                                </label>
                                            </div>
                                        </RecordFormField>
                                    ) : null}
                                </div>
                            </>
                        ) : null}
                    </div>
                    <DialogFooter className="border-t border-border/60 pt-4">
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            className={actions.dialogSecondary}
                            onClick={() => setLanguageDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            className={actions.dialogPrimary}
                            disabled={languageForm.processing}
                            onClick={submitLanguage}
                        >
                            {languageForm.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <EmployeeRecordDeleteDialog
                open={!!deleteLanguageId}
                onOpenChange={(openDialog) => {
                    if (!openDialog) {
                        setDeleteLanguageId(null);
                    }
                }}
                title="Remove language?"
                description="This entry will be permanently removed."
                destroyUrl={
                    deleteLanguageId && employeeId
                        ? destroyLanguage.url({
                              employee: employeeId,
                              language: deleteLanguageId,
                          })
                        : null
                }
                reloadOptions={LANGUAGES_RELOAD}
            />
        </TabsContent>
    );
}
