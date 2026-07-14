import { router } from '@inertiajs/react';
import type { ReactElement } from 'react';
import * as EmployeeTrainingController from '@/actions/App/Http/Controllers/Organization/EmployeeTrainingController';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { CountryOption } from '@/features/organization/employees/types';
import { EditTrainingDialog } from '@/features/organization/training/edit-training-dialog';
import { ReplaceTrainingCertificateDialog } from '@/features/organization/training/replace-training-certificate-dialog';
import type {
    CourseOption,
    TemplateFieldConfig,
    TrainingItem,
} from '@/pages/organization/employee-page.types';

type TrainingManagementDialogsProps = {
    employeeId: number;
    courses: CourseOption[];
    countries: CountryOption[];
    editTraining: TrainingItem | null;
    onEditTrainingChange: (training: TrainingItem | null) => void;
    replaceTraining: TrainingItem | null;
    onReplaceTrainingChange: (training: TrainingItem | null) => void;
    deleteTrainingId: number | null;
    onDeleteTrainingIdChange: (id: number | null) => void;
    templateFields?: Record<string, TemplateFieldConfig> | null;
    partialReloadKeys?: string[];
    deleteRedirectUrl?: string;
};

export function TrainingManagementDialogs({
    employeeId,
    courses,
    countries,
    editTraining,
    onEditTrainingChange,
    replaceTraining,
    onReplaceTrainingChange,
    deleteTrainingId,
    onDeleteTrainingIdChange,
    templateFields = null,
    partialReloadKeys = ['trainings'],
    deleteRedirectUrl,
}: TrainingManagementDialogsProps): ReactElement {
    return (
        <>
            <EditTrainingDialog
                key={editTraining?.id ?? 'closed'}
                training={editTraining}
                employeeId={employeeId}
                onOpenChange={(open) => !open && onEditTrainingChange(null)}
                courses={courses}
                countries={countries}
                templateFields={templateFields}
                partialReloadKeys={partialReloadKeys}
            />

            <ReplaceTrainingCertificateDialog
                training={replaceTraining}
                employeeId={employeeId}
                onOpenChange={(open) => !open && onReplaceTrainingChange(null)}
                countries={countries}
                templateFields={templateFields}
                partialReloadKeys={partialReloadKeys}
            />

            <ConfirmDeleteDialog
                open={!!deleteTrainingId}
                onOpenChange={(open) => !open && onDeleteTrainingIdChange(null)}
                title="Remove training record?"
                description="This training record will be permanently removed."
                confirmText="Remove"
                onConfirm={() => {
                    if (!deleteTrainingId) {
                        return;
                    }

                    router.delete(
                        EmployeeTrainingController.destroy.url({
                            employee: employeeId,
                            training: deleteTrainingId,
                        }),
                        {
                            preserveScroll: true,
                            ...(deleteRedirectUrl
                                ? {}
                                : partialReloadKeys.length > 0
                                  ? { only: partialReloadKeys }
                                  : {}),
                            onSuccess: () => {
                                onDeleteTrainingIdChange(null);

                                if (deleteRedirectUrl) {
                                    router.visit(deleteRedirectUrl);
                                }
                            },
                        },
                    );
                }}
            />
        </>
    );
}
