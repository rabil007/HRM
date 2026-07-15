import { router } from '@inertiajs/react';
import type { ReactElement } from 'react';
import * as EmployeeSeaServiceController from '@/actions/App/Http/Controllers/Organization/EmployeeSeaServiceController';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import type { RankOption } from '@/features/organization/employees/types';
import { EditSeaServiceDialog } from '@/features/organization/sea-services/edit-sea-service-dialog';
import type { SeaServiceListItem } from '@/features/organization/sea-services/types';
import type {
    ClientOption,
    SeaServiceItem,
    VesselOption,
    VesselTypeOption,
} from '@/pages/organization/employee-page.types';

type EditableSeaService = SeaServiceItem | SeaServiceListItem;

type SeaServiceManagementDialogsProps = {
    employeeId: number;
    vesselTypes: VesselTypeOption[];
    vessels: VesselOption[];
    ranks: RankOption[];
    clients: ClientOption[];
    editSeaService: EditableSeaService | null;
    onEditSeaServiceChange: (seaService: EditableSeaService | null) => void;
    deleteSeaServiceId: number | null;
    onDeleteSeaServiceIdChange: (id: number | null) => void;
    partialReloadKeys?: string[];
    deleteRedirectUrl?: string;
};

export function SeaServiceManagementDialogs({
    employeeId,
    vesselTypes,
    vessels,
    ranks,
    clients,
    editSeaService,
    onEditSeaServiceChange,
    deleteSeaServiceId,
    onDeleteSeaServiceIdChange,
    partialReloadKeys = ['sea_services'],
    deleteRedirectUrl,
}: SeaServiceManagementDialogsProps): ReactElement {
    return (
        <>
            <EditSeaServiceDialog
                key={editSeaService?.id ?? 'closed'}
                seaService={editSeaService}
                employeeId={employeeId}
                onOpenChange={(open) => !open && onEditSeaServiceChange(null)}
                vesselTypes={vesselTypes}
                vessels={vessels}
                ranks={ranks}
                clients={clients}
                partialReloadKeys={partialReloadKeys}
            />

            <ConfirmDeleteDialog
                open={!!deleteSeaServiceId}
                onOpenChange={(open) =>
                    !open && onDeleteSeaServiceIdChange(null)
                }
                title="Remove sea service record?"
                description="This sea service record will be permanently removed."
                confirmText="Remove"
                onConfirm={() => {
                    if (!deleteSeaServiceId) {
                        return;
                    }

                    router.delete(
                        EmployeeSeaServiceController.destroy.url({
                            employee: employeeId,
                            seaService: deleteSeaServiceId,
                        }),
                        {
                            preserveScroll: true,
                            ...(deleteRedirectUrl
                                ? {}
                                : partialReloadKeys.length > 0
                                  ? { only: partialReloadKeys }
                                  : {}),
                            onSuccess: () => {
                                onDeleteSeaServiceIdChange(null);

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
