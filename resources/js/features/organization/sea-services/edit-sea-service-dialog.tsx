import { useForm } from '@inertiajs/react';
import { useEffect, useMemo } from 'react';
import type { ReactElement } from 'react';
import * as EmployeeSeaServiceController from '@/actions/App/Http/Controllers/Organization/EmployeeSeaServiceController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
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
import type { RankOption } from '@/features/organization/employees/types';
import type { SeaServiceListItem } from '@/features/organization/sea-services/types';
import { actions } from '@/lib/design-system';
import type {
    ClientOption,
    SeaServiceItem,
    VesselOption,
    VesselTypeOption,
} from '@/pages/organization/employee-page.types';

type EditableSeaService = SeaServiceItem | SeaServiceListItem;

export function EditSeaServiceDialog({
    seaService,
    employeeId,
    onOpenChange,
    vesselTypes,
    vessels,
    ranks,
    clients,
    partialReloadKeys = ['sea_services'],
}: {
    seaService: EditableSeaService | null;
    employeeId: number;
    onOpenChange: (open: boolean) => void;
    vesselTypes: VesselTypeOption[];
    vessels: VesselOption[];
    ranks: RankOption[];
    clients: ClientOption[];
    partialReloadKeys?: string[];
}): ReactElement {
    const editForm = useForm({
        vessel_type_id: '',
        vessel_id: '',
        rank_id: '',
        start_date: '',
        end_date: '',
        client_id: '',
        is_offshore: false,
    });

    useEffect(() => {
        if (!seaService) {
            return;
        }

        editForm.setData({
            vessel_type_id: String(seaService.vessel_type_id ?? ''),
            vessel_id:
                seaService.vessel_id != null
                    ? String(seaService.vessel_id)
                    : '',
            rank_id: String(seaService.rank_id ?? ''),
            start_date: seaService.start_date ?? '',
            end_date: seaService.end_date ?? '',
            client_id:
                seaService.client_id != null
                    ? String(seaService.client_id)
                    : '',
            is_offshore: !!seaService.is_offshore,
        });
        editForm.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset when opening a different record
    }, [seaService?.id]);

    const filteredVessels = useMemo(() => {
        if (editForm.data.vessel_type_id === '') {
            return vessels;
        }

        return vessels.filter(
            (vessel) =>
                String(vessel.vessel_type_id) === editForm.data.vessel_type_id,
        );
    }, [editForm.data.vessel_type_id, vessels]);

    return (
        <Dialog open={!!seaService} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Edit sea service</DialogTitle>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="space-y-2">
                        <Label>Vessel type</Label>
                        <AppSelect
                            value={editForm.data.vessel_type_id}
                            onValueChange={(value) =>
                                editForm.setData({
                                    ...editForm.data,
                                    vessel_type_id: value,
                                    vessel_id: '',
                                })
                            }
                            placeholder="Select vessel type"
                        >
                            {vesselTypes.map((vesselType) => (
                                <AppSelectItem
                                    key={vesselType.id}
                                    value={String(vesselType.id)}
                                >
                                    {vesselType.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {editForm.errors.vessel_type_id ? (
                            <p className="text-sm text-destructive">
                                {editForm.errors.vessel_type_id}
                            </p>
                        ) : null}
                    </div>

                    <div className="space-y-2">
                        <Label>Vessel</Label>
                        <AppSelect
                            value={editForm.data.vessel_id}
                            onValueChange={(value) =>
                                editForm.setData('vessel_id', value)
                            }
                            placeholder="Select vessel"
                        >
                            {filteredVessels.map((vessel) => (
                                <AppSelectItem
                                    key={vessel.id}
                                    value={String(vessel.id)}
                                >
                                    {vessel.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {editForm.errors.vessel_id ? (
                            <p className="text-sm text-destructive">
                                {editForm.errors.vessel_id}
                            </p>
                        ) : null}
                    </div>

                    <div className="space-y-2">
                        <Label>Rank</Label>
                        <AppSelect
                            value={editForm.data.rank_id}
                            onValueChange={(value) =>
                                editForm.setData('rank_id', value)
                            }
                            placeholder="Select rank"
                        >
                            {ranks.map((rank) => (
                                <AppSelectItem
                                    key={rank.id}
                                    value={String(rank.id)}
                                >
                                    {rank.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {editForm.errors.rank_id ? (
                            <p className="text-sm text-destructive">
                                {editForm.errors.rank_id}
                            </p>
                        ) : null}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="edit-start-date">Start date</Label>
                            <Input
                                id="edit-start-date"
                                type="date"
                                value={editForm.data.start_date}
                                onChange={(event) =>
                                    editForm.setData(
                                        'start_date',
                                        event.target.value,
                                    )
                                }
                            />
                            {editForm.errors.start_date ? (
                                <p className="text-sm text-destructive">
                                    {editForm.errors.start_date}
                                </p>
                            ) : null}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="edit-end-date">End date</Label>
                            <Input
                                id="edit-end-date"
                                type="date"
                                value={editForm.data.end_date}
                                onChange={(event) =>
                                    editForm.setData(
                                        'end_date',
                                        event.target.value,
                                    )
                                }
                            />
                            {editForm.errors.end_date ? (
                                <p className="text-sm text-destructive">
                                    {editForm.errors.end_date}
                                </p>
                            ) : null}
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label>Client</Label>
                        <AppSelect
                            value={editForm.data.client_id}
                            onValueChange={(value) =>
                                editForm.setData('client_id', value)
                            }
                            placeholder="Optional client"
                        >
                            <AppSelectItem value="">None</AppSelectItem>
                            {clients.map((client) => (
                                <AppSelectItem
                                    key={client.id}
                                    value={String(client.id)}
                                >
                                    {client.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="edit-is-offshore"
                            checked={editForm.data.is_offshore}
                            onCheckedChange={(checked) =>
                                editForm.setData(
                                    'is_offshore',
                                    checked === true,
                                )
                            }
                        />
                        <Label htmlFor="edit-is-offshore">Offshore</Label>
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        className={actions.dialogSecondary}
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        className={actions.dialogPrimary}
                        disabled={editForm.processing || !seaService}
                        onClick={() => {
                            if (!seaService) {
                                return;
                            }

                            editForm.transform((data) => ({
                                vessel_type_id:
                                    data.vessel_type_id === ''
                                        ? null
                                        : Number.parseInt(
                                              data.vessel_type_id,
                                              10,
                                          ),
                                vessel_id:
                                    data.vessel_id === ''
                                        ? null
                                        : Number.parseInt(data.vessel_id, 10),
                                rank_id:
                                    data.rank_id === ''
                                        ? null
                                        : Number.parseInt(data.rank_id, 10),
                                start_date: data.start_date,
                                end_date: data.end_date,
                                client_id:
                                    data.client_id === ''
                                        ? null
                                        : Number.parseInt(data.client_id, 10),
                                is_offshore: data.is_offshore,
                            }));
                            editForm.put(
                                EmployeeSeaServiceController.update.url({
                                    employee: employeeId,
                                    seaService: seaService.id,
                                }),
                                {
                                    preserveScroll: true,
                                    only: partialReloadKeys,
                                    onSuccess: () => onOpenChange(false),
                                },
                            );
                        }}
                    >
                        Save
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
