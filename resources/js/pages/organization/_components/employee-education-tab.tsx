import { useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import {
    destroy,
    store,
    update,
} from '@/actions/App/Http/Controllers/Organization/EmployeeEducationQualificationController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { EmployeeRecordRowActions } from '@/components/employee-record-row-actions';
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
import { TabsContent } from '@/components/ui/tabs';
import { EmployeeRecordDeleteDialog } from '@/features/organization/employees/profile/components/employee-record-delete-dialog';
import { resolveEmployeeIdForSave } from '@/features/organization/employees/profile/resolve-employee-id-for-save';
import type { CountryOption } from '@/features/organization/employees/types';
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
    EducationQualificationItem,
    TemplateFieldConfig,
} from '@/pages/organization/employee-page.types';

const EDUCATION_RELOAD = {
    preserveScroll: true,
    only: ['education_qualifications'],
} as const;

export type EmployeeEducationTabProps = {
    employeeId: number | null;
    education_qualifications: EducationQualificationItem[];
    countries: CountryOption[];
    canManage: boolean;
    ensureEmployee?: () => Promise<number>;
    templateFields?: Record<string, TemplateFieldConfig> | null;
};

export function EmployeeEducationTab({
    employeeId,
    education_qualifications,
    countries,
    canManage,
    ensureEmployee,
    templateFields = null,
}: EmployeeEducationTabProps): ReactElement {
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
            TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_education_qualifications,
    });

    const [educationDialogOpen, setEducationDialogOpen] = useState(false);
    const [editingEducation, setEditingEducation] =
        useState<EducationQualificationItem | null>(null);
    const [deleteEducationId, setDeleteEducationId] = useState<number | null>(
        null,
    );

    const educationForm = useForm({
        certificate: '',
        issue_date: '',
        university: '',
        country_id: '',
    });

    useClearMissingOnFormChange(
        educationForm.data as Record<string, unknown>,
        syncMissingFromFormData,
    );

    const openCreateDialog = () => {
        educationForm.reset();
        educationForm.clearErrors();
        clearMissingRequired();
        setEditingEducation(null);
        setEducationDialogOpen(true);
    };

    const openEditDialog = (row: EducationQualificationItem) => {
        setEditingEducation(row);
        educationForm.setData({
            certificate: row.certificate,
            issue_date: row.issue_date ?? '',
            university: row.university ?? '',
            country_id: row.country_id ? String(row.country_id) : '',
        });
        educationForm.clearErrors();
        clearMissingRequired();
        setEducationDialogOpen(true);
    };

    const submitEducation = async () => {
        let resolvedEmployeeId: number;

        try {
            resolvedEmployeeId = await resolveEmployeeIdForSave(
                employeeId,
                ensureEmployee,
            );
        } catch {
            return;
        }

        if (!validateRequired(educationForm.data as Record<string, unknown>)) {
            return;
        }

        educationForm.clearErrors();
        educationForm.transform((data) =>
            omitHiddenTemplateRecordFields(
                {
                    certificate: data.certificate.trim(),
                    issue_date: data.issue_date === '' ? null : data.issue_date,
                    university:
                        data.university.trim() === ''
                            ? null
                            : data.university.trim(),
                    country_id:
                        data.country_id === '' ? null : Number(data.country_id),
                },
                templateFields,
            ),
        );

        const url = editingEducation
            ? update.url({
                  employee: resolvedEmployeeId,
                  qualification: editingEducation.id,
              })
            : store.url({ employee: resolvedEmployeeId });

        const options = {
            ...EDUCATION_RELOAD,
            onSuccess: () => {
                setEducationDialogOpen(false);
                educationForm.reset();
                setEditingEducation(null);
                clearMissingRequired();
            },
        };

        if (editingEducation) {
            educationForm.put(url, options);
        } else {
            educationForm.post(url, options);
        }
    };

    const showQualificationSection =
        showField('certificate') ||
        showField('university') ||
        showField('country_id') ||
        showField('issue_date');

    return (
        <TabsContent value="education" className="mt-6">
            <EmployeeRecordsPanel
                title="Education qualifications"
                count={education_qualifications.length}
                isEmpty={education_qualifications.length === 0}
                emptyMessage="No qualifications recorded."
                actions={
                    canManage ? (
                        <Button
                            size="sm"
                            className="h-8 gap-1.5 text-xs"
                            type="button"
                            onClick={openCreateDialog}
                        >
                            + Add qualification
                        </Button>
                    ) : undefined
                }
            >
                <EmployeeRecordsTable className="min-w-[680px]">
                    <thead>
                        <tr className={employeeRecordsTableHeadClass()}>
                            {showField('certificate') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Certificate
                                </th>
                            ) : null}
                            {showField('issue_date') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Issue date
                                </th>
                            ) : null}
                            {showField('university') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    University
                                </th>
                            ) : null}
                            {showField('country_id') ? (
                                <th className={employeeRecordsTableThClass()}>
                                    Country
                                </th>
                            ) : null}
                            {canManage ? (
                                <EmployeeRecordsActionsHeader />
                            ) : null}
                        </tr>
                    </thead>
                    <tbody>
                        {education_qualifications.map((row) => (
                            <tr
                                key={row.id}
                                className={employeeRecordsTableRowClass()}
                            >
                                {showField('certificate') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'font-medium text-foreground',
                                        )}
                                    >
                                        {row.certificate}
                                    </td>
                                ) : null}
                                {showField('issue_date') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'font-mono text-xs text-muted-foreground',
                                        )}
                                    >
                                        {formatDisplayDate(row.issue_date)}
                                    </td>
                                ) : null}
                                {showField('university') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-muted-foreground',
                                        )}
                                    >
                                        {row.university ?? '—'}
                                    </td>
                                ) : null}
                                {showField('country_id') ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-xs text-muted-foreground',
                                        )}
                                    >
                                        {row.country_name ?? '—'}
                                    </td>
                                ) : null}
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
                                                setDeleteEducationId(row.id)
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
                open={educationDialogOpen}
                onOpenChange={(open) => {
                    setEducationDialogOpen(open);

                    if (!open) {
                        educationForm.reset();
                        educationForm.clearErrors();
                        setEditingEducation(null);
                        clearMissingRequired();
                    }
                }}
            >
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>
                            {editingEducation
                                ? 'Edit qualification'
                                : 'Add qualification'}
                        </DialogTitle>
                        <p className="text-xs text-muted-foreground">
                            Enter the details of the educational qualification.
                        </p>
                    </DialogHeader>

                    <EmployeeMissingRequiredFieldsAlert
                        missingFields={missingRequiredFieldsList}
                        onFocusField={focusMissingField}
                    />

                    {showQualificationSection ? (
                        <div className="space-y-4 py-1">
                            <div className="flex items-center gap-2">
                                <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                                    Qualification details
                                </span>
                                <div className="h-px flex-1 bg-muted/50" />
                            </div>
                            {showField('certificate') ? (
                                <RecordFormField
                                    field="certificate"
                                    highlightMissing={isMissingRequired(
                                        'certificate',
                                    )}
                                >
                                    <Label
                                        className={recordFieldLabelClass(
                                            isMissingRequired('certificate'),
                                        )}
                                    >
                                        Certificate / Degree
                                        <RequiredIndicator
                                            show={isFieldRequired(
                                                'certificate',
                                            )}
                                        />
                                    </Label>
                                    <Input
                                        className={recordFieldInputClass(
                                            isMissingRequired('certificate'),
                                        )}
                                        placeholder="e.g. Bachelor of Science in Marine Engineering"
                                        value={educationForm.data.certificate}
                                        onChange={(e) =>
                                            educationForm.setData(
                                                'certificate',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {educationForm.errors.certificate ? (
                                        <p className="text-xs text-destructive">
                                            {educationForm.errors.certificate}
                                        </p>
                                    ) : (
                                        <p className="text-[11px] text-muted-foreground">
                                            The title of the obtained
                                            qualification
                                            {isFieldRequired('certificate')
                                                ? ''
                                                : ' (optional)'}
                                        </p>
                                    )}
                                </RecordFormField>
                            ) : null}
                            <div className="grid gap-4 sm:grid-cols-2">
                                {showField('university') ? (
                                    <RecordFormField
                                        field="university"
                                        highlightMissing={isMissingRequired(
                                            'university',
                                        )}
                                    >
                                        <Label
                                            className={recordFieldLabelClass(
                                                isMissingRequired('university'),
                                            )}
                                        >
                                            University / Institution
                                            <RequiredIndicator
                                                show={isFieldRequired(
                                                    'university',
                                                )}
                                            />
                                        </Label>
                                        <Input
                                            className={recordFieldInputClass(
                                                isMissingRequired('university'),
                                            )}
                                            placeholder="e.g. Maritime Academy"
                                            value={
                                                educationForm.data.university
                                            }
                                            onChange={(e) =>
                                                educationForm.setData(
                                                    'university',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        {educationForm.errors.university ? (
                                            <p className="text-xs text-destructive">
                                                {
                                                    educationForm.errors
                                                        .university
                                                }
                                            </p>
                                        ) : (
                                            <p className="text-[11px] text-muted-foreground">
                                                The awarding institution
                                                {isFieldRequired('university')
                                                    ? ''
                                                    : ' (optional)'}
                                            </p>
                                        )}
                                    </RecordFormField>
                                ) : null}
                                {showField('country_id') ? (
                                    <RecordFormField
                                        field="country_id"
                                        highlightMissing={isMissingRequired(
                                            'country_id',
                                        )}
                                    >
                                        <Label
                                            className={recordFieldLabelClass(
                                                isMissingRequired('country_id'),
                                            )}
                                        >
                                            Country
                                            <RequiredIndicator
                                                show={isFieldRequired(
                                                    'country_id',
                                                )}
                                            />
                                        </Label>
                                        <AppSelect
                                            value={
                                                educationForm.data.country_id
                                            }
                                            onValueChange={(v) =>
                                                educationForm.setData(
                                                    'country_id',
                                                    v,
                                                )
                                            }
                                            variant="dark"
                                            placeholder="— Select a country —"
                                        >
                                            <AppSelectItem value="">
                                                — Select a country —
                                            </AppSelectItem>
                                            {countries.map((c) => (
                                                <AppSelectItem
                                                    key={c.id}
                                                    value={String(c.id)}
                                                >
                                                    {c.name}
                                                </AppSelectItem>
                                            ))}
                                        </AppSelect>
                                        {educationForm.errors.country_id ? (
                                            <p className="text-xs text-destructive">
                                                {
                                                    educationForm.errors
                                                        .country_id
                                                }
                                            </p>
                                        ) : (
                                            <p className="text-[11px] text-muted-foreground">
                                                Country of the institution
                                                {isFieldRequired('country_id')
                                                    ? ''
                                                    : ' (optional)'}
                                            </p>
                                        )}
                                    </RecordFormField>
                                ) : null}
                                {showField('issue_date') ? (
                                    <RecordFormField
                                        field="issue_date"
                                        highlightMissing={isMissingRequired(
                                            'issue_date',
                                        )}
                                    >
                                        <Label
                                            className={recordFieldLabelClass(
                                                isMissingRequired('issue_date'),
                                            )}
                                        >
                                            Issue date
                                            <RequiredIndicator
                                                show={isFieldRequired(
                                                    'issue_date',
                                                )}
                                            />
                                        </Label>
                                        <Input
                                            type="date"
                                            className={recordFieldInputClass(
                                                isMissingRequired('issue_date'),
                                            )}
                                            value={
                                                educationForm.data.issue_date
                                            }
                                            onChange={(e) =>
                                                educationForm.setData(
                                                    'issue_date',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        {educationForm.errors.issue_date ? (
                                            <p className="text-xs text-destructive">
                                                {
                                                    educationForm.errors
                                                        .issue_date
                                                }
                                            </p>
                                        ) : (
                                            <p className="text-[11px] text-muted-foreground">
                                                When the certificate was issued
                                                {isFieldRequired('issue_date')
                                                    ? ''
                                                    : ' (optional)'}
                                            </p>
                                        )}
                                    </RecordFormField>
                                ) : null}
                            </div>
                        </div>
                    ) : null}
                    <DialogFooter className="border-t border-border/60 pt-4">
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            className={actions.dialogSecondary}
                            onClick={() => setEducationDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            className={actions.dialogPrimary}
                            disabled={educationForm.processing}
                            onClick={submitEducation}
                        >
                            {educationForm.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <EmployeeRecordDeleteDialog
                open={!!deleteEducationId}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleteEducationId(null);
                    }
                }}
                title="Remove qualification?"
                description="This education record will be permanently removed."
                destroyUrl={
                    deleteEducationId && employeeId
                        ? destroy.url({
                              employee: employeeId,
                              qualification: deleteEducationId,
                          })
                        : null
                }
                reloadOptions={EDUCATION_RELOAD}
            />
        </TabsContent>
    );
}
