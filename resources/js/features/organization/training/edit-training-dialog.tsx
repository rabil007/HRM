import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import type { ReactElement } from 'react';
import * as EmployeeTrainingController from '@/actions/App/Http/Controllers/Organization/EmployeeTrainingController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { AddTrainingDraftForm } from '@/features/organization/training/add-training/add-training-draft-form';
import {
    buildTrainingSubmitPayload,
    trainingMetadataFromItem,
} from '@/features/organization/training/add-training/training-draft';
import { actions } from '@/lib/design-system';
import { EmployeeMissingRequiredFieldsAlert } from '@/pages/organization/_components/employee-missing-required-fields-alert';
import {
    useClearMissingOnFormChange,
    useTemplateRecordFields,
} from '@/pages/organization/_hooks/use-template-record-fields';
import { omitHiddenTemplateRecordFields } from '@/pages/organization/_lib/template-field-visibility';
import { TEMPLATE_RECORD_DEFAULT_REQUIRED } from '@/pages/organization/_lib/template-record-defaults';
import type { CountryOption } from '@/features/organization/employees/types';
import type {
    CourseOption,
    TemplateFieldConfig,
    TrainingItem,
} from '@/pages/organization/employee-page.types';

export function EditTrainingDialog({
    training,
    employeeId,
    onOpenChange,
    courses,
    countries,
    templateFields = null,
    partialReloadKeys = ['trainings'],
}: {
    training: TrainingItem | null;
    employeeId: number;
    onOpenChange: (open: boolean) => void;
    courses: CourseOption[];
    countries: CountryOption[];
    templateFields?: Record<string, TemplateFieldConfig> | null;
    partialReloadKeys?: string[];
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
        defaultRequiredFields:
            TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_trainings,
    });

    const editForm = useForm({
        course_id: '',
        issue_date: '',
        expiry_date: '',
        institute_center: '',
        country_id: '',
    });

    useEffect(() => {
        if (!training) {
            return;
        }

        const metadata = trainingMetadataFromItem(training);

        editForm.setData({
            course_id: metadata.course_id,
            issue_date: metadata.issue_date,
            expiry_date: metadata.expiry_date,
            institute_center: metadata.institute_center,
            country_id: metadata.country_id,
        });
        editForm.clearErrors();
        clearMissingRequired();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset when opening a different training
    }, [training?.id]);

    useClearMissingOnFormChange(editForm.data, syncMissingFromFormData);

    return (
        <Dialog open={!!training} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Edit training</DialogTitle>
                    <p className="text-sm text-muted-foreground">
                        Update training details. Use Replace on the row to change
                        the certificate file.
                    </p>
                </DialogHeader>

                <EmployeeMissingRequiredFieldsAlert
                    missingFields={missingRequiredFieldsList}
                    onFocusField={focusMissingField}
                />

                <div className="py-2">
                    <AddTrainingDraftForm
                        draft={{
                            course_id: editForm.data.course_id,
                            issue_date: editForm.data.issue_date,
                            expiry_date: editForm.data.expiry_date,
                            institute_center: editForm.data.institute_center,
                            country_id: editForm.data.country_id,
                        }}
                        courses={courses}
                        countries={countries}
                        onChange={(patch) => {
                            editForm.setData((current) => ({
                                ...current,
                                ...patch,
                            }));
                        }}
                        fieldErrors={editForm.errors}
                        showApplyToAll={false}
                        showField={showField}
                        isFieldRequired={isFieldRequired}
                        isMissingRequired={isMissingRequired}
                    />
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
                            if (!training) {
                                return;
                            }

                            if (
                                !validateRequired(
                                    editForm.data as Record<string, unknown>,
                                )
                            ) {
                                return;
                            }

                            editForm.clearErrors();
                            editForm.transform((data) =>
                                omitHiddenTemplateRecordFields(
                                    buildTrainingSubmitPayload(
                                        {
                                            course_id: data.course_id,
                                            issue_date: data.issue_date,
                                            expiry_date: data.expiry_date,
                                            institute_center:
                                                data.institute_center,
                                            country_id: data.country_id,
                                        },
                                        {
                                            templateFields,
                                        },
                                    ),
                                    templateFields,
                                ),
                            );

                            editForm.put(
                                EmployeeTrainingController.update.url({
                                    employee: employeeId,
                                    training: training.id,
                                }),
                                {
                                    preserveScroll: true,
                                    only: partialReloadKeys,
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
