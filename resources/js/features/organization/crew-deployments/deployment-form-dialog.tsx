import { router, useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useEffect } from 'react';
import {
    store as storeDeployment,
    update as updateDeployment,
} from '@/actions/App/Http/Controllers/Organization/CrewDeploymentController';
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
import { Textarea } from '@/components/ui/textarea';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { actions } from '@/lib/design-system';
import type { DeploymentItem } from '@/features/organization/crew-deployments/types';

type Option = { id: number; name: string };
type EmployeeOption = { id: number; employee_no: string; name: string; rank_id: number | null };

export function DeploymentFormDialog({
    open,
    onOpenChange,
    editing,
    employees,
    ranks,
    clients,
    companyVisaTypes,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    editing: DeploymentItem | null;
    employees: EmployeeOption[];
    ranks: Option[];
    clients: Option[];
    companyVisaTypes: Option[];
}): ReactElement {
    const form = useForm({
        employee_id: '',
        rank_id: '',
        client_id: '',
        company_visa_type_id: '',
        vessel_name: '',
        hire_date: '',
        arrived_date: '',
        standby_from: '',
        standby_to: '',
        joined_date: '',
        disembarked_date: '',
        travelled_date: '',
        remarks: '',
    });

    useEffect(() => {
        if (!open) {
            return;
        }

        if (editing) {
            form.setData({
                employee_id: String(editing.employee_id),
                rank_id: editing.rank_id ? String(editing.rank_id) : '',
                client_id: editing.client_id ? String(editing.client_id) : '',
                company_visa_type_id: editing.company_visa_type_id
                    ? String(editing.company_visa_type_id)
                    : '',
                vessel_name: editing.vessel_name ?? '',
                hire_date: editing.hire_date ?? '',
                arrived_date: editing.arrived_date ?? '',
                standby_from: editing.standby_from ?? '',
                standby_to: editing.standby_to ?? '',
                joined_date: editing.joined_date ?? '',
                disembarked_date: editing.disembarked_date ?? '',
                travelled_date: editing.travelled_date ?? '',
                remarks: editing.remarks ?? '',
            });

            return;
        }

        form.reset();
    }, [editing, open]);

    const submit = (): void => {
        const payload = {
            employee_id: Number(form.data.employee_id),
            rank_id: form.data.rank_id ? Number(form.data.rank_id) : null,
            client_id: form.data.client_id ? Number(form.data.client_id) : null,
            company_visa_type_id: form.data.company_visa_type_id
                ? Number(form.data.company_visa_type_id)
                : null,
            vessel_name: form.data.vessel_name || null,
            hire_date: form.data.hire_date || null,
            arrived_date: form.data.arrived_date || null,
            standby_from: form.data.standby_from || null,
            standby_to: form.data.standby_to || null,
            joined_date: form.data.joined_date || null,
            disembarked_date: form.data.disembarked_date || null,
            travelled_date: form.data.travelled_date || null,
            remarks: form.data.remarks || null,
        };

        if (editing) {
            router.put(updateDeployment.url({ deployment: editing.id }), payload, {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            });

            return;
        }

        router.post(storeDeployment.url(), payload, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {editing ? 'Edit deployment' : 'Add deployment'}
                    </DialogTitle>
                </DialogHeader>

                <div className="grid gap-4 py-2 sm:grid-cols-2">
                    <div className="space-y-2 sm:col-span-2">
                        <Label>Employee</Label>
                        <AppSelect
                            value={form.data.employee_id}
                            onValueChange={(value) => {
                                form.setData('employee_id', value);
                                const employee = employees.find(
                                    (item) => String(item.id) === value,
                                );
                                if (employee?.rank_id && !form.data.rank_id) {
                                    form.setData('rank_id', String(employee.rank_id));
                                }
                            }}
                            placeholder="Select employee"
                        >
                            {employees.map((employee) => (
                                <AppSelectItem
                                    key={employee.id}
                                    value={String(employee.id)}
                                >
                                    {employee.employee_no} — {employee.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    <div className="space-y-2">
                        <Label>Rank</Label>
                        <AppSelect
                            value={form.data.rank_id}
                            onValueChange={(value) => form.setData('rank_id', value)}
                            placeholder="Select rank"
                        >
                            {ranks.map((rank) => (
                                <AppSelectItem key={rank.id} value={String(rank.id)}>
                                    {rank.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    <div className="space-y-2">
                        <Label>Vessel</Label>
                        <Input
                            value={form.data.vessel_name}
                            onChange={(event) =>
                                form.setData('vessel_name', event.target.value)
                            }
                        />
                    </div>

                    <div className="space-y-2">
                        <Label>Company visa type</Label>
                        <AppSelect
                            value={form.data.company_visa_type_id}
                            onValueChange={(value) =>
                                form.setData('company_visa_type_id', value)
                            }
                            placeholder="Select company visa type"
                        >
                            {companyVisaTypes.map((companyVisaType) => (
                                <AppSelectItem
                                    key={companyVisaType.id}
                                    value={String(companyVisaType.id)}
                                >
                                    {companyVisaType.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    <div className="space-y-2">
                        <Label>Client</Label>
                        <AppSelect
                            value={form.data.client_id}
                            onValueChange={(value) => form.setData('client_id', value)}
                            placeholder="Select client"
                        >
                            {clients.map((client) => (
                                <AppSelectItem key={client.id} value={String(client.id)}>
                                    {client.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    {(
                        [
                            ['hire_date', 'Date of hire'],
                            ['arrived_date', 'Date arrived'],
                            ['standby_from', 'Standby from'],
                            ['standby_to', 'Standby to'],
                            ['joined_date', 'Date joined'],
                            ['disembarked_date', 'Date disembarked'],
                            ['travelled_date', 'Date travelled'],
                        ] as const
                    ).map(([field, label]) => (
                        <div key={field} className="space-y-2">
                            <Label>{label}</Label>
                            <Input
                                type="date"
                                value={form.data[field]}
                                onChange={(event) =>
                                    form.setData(field, event.target.value)
                                }
                            />
                        </div>
                    ))}

                    <div className="space-y-2 sm:col-span-2">
                        <Label>Remarks</Label>
                        <Textarea
                            value={form.data.remarks}
                            onChange={(event) =>
                                form.setData('remarks', event.target.value)
                            }
                            placeholder="Ops notes, standby details, etc."
                            rows={3}
                            className="min-h-[88px] resize-y rounded-xl border-input bg-background/50 px-4 py-3 focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5"
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        className={actions.dialogSecondary}
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        className={actions.dialogPrimary}
                        disabled={!form.data.employee_id || form.processing}
                        onClick={submit}
                    >
                        {editing ? 'Save' : 'Add'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
