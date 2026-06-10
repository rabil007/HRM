import { useForm } from '@inertiajs/react';
import type { ReactElement, ReactNode } from 'react';
import { useEffect } from 'react';
import {
    store as storeDeployment,
    update as updateDeployment,
} from '@/actions/App/Http/Controllers/Organization/CrewDeploymentController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { DeploymentStatusBadge } from '@/features/organization/crew-deployments/deployment-status-badge';
import type { DeploymentItem } from '@/features/organization/crew-deployments/types';
import { actions, typography } from '@/lib/design-system';
import { cn } from '@/lib/utils';

type Option = { id: number; name: string };
type EmployeeOption = { id: number; employee_no: string; name: string; rank_id: number | null };

const fieldInputClass =
    'h-10 rounded-xl border-border/60 bg-muted/50 text-sm focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5';

const DATE_FIELDS = [
    { field: 'hire_date', label: 'Hire date' },
    { field: 'arrived_date', label: 'Arrived' },
    { field: 'standby_from', label: 'Standby from' },
    { field: 'standby_to', label: 'Standby to' },
    { field: 'joined_date', label: 'Joined' },
    { field: 'disembarked_date', label: 'Disembarked' },
    { field: 'travelled_date', label: 'Travelled' },
] as const;

type DateField = (typeof DATE_FIELDS)[number]['field'];

function FormSection({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}): ReactElement {
    return (
        <section className="space-y-3">
            <div className="space-y-1">
                <div className="flex items-center gap-2">
                    <span className={typography.sectionLabel}>{title}</span>
                    <div className="h-px flex-1 bg-border/60 dark:bg-white/10" />
                </div>
                {description ? (
                    <p className="text-[11px] text-muted-foreground">{description}</p>
                ) : null}
            </div>
            {children}
        </section>
    );
}

function FormField({
    label,
    htmlFor,
    error,
    hint,
    className,
    children,
}: {
    label: string;
    htmlFor?: string;
    error?: string;
    hint?: string;
    className?: string;
    children: ReactNode;
}): ReactElement {
    return (
        <div className={cn('space-y-1.5', className)}>
            <Label htmlFor={htmlFor} className="text-xs font-medium text-foreground/90">
                {label}
            </Label>
            {children}
            {error ? (
                <p className="text-xs text-destructive">{error}</p>
            ) : hint ? (
                <p className="text-[11px] text-muted-foreground">{hint}</p>
            ) : null}
        </div>
    );
}

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
            form.clearErrors();

            return;
        }

        form.reset();
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset when dialog opens for create
    }, [editing, open]);

    const submit = (): void => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        form.transform((data) => ({
            employee_id: Number(data.employee_id),
            rank_id: data.rank_id ? Number(data.rank_id) : null,
            client_id: data.client_id ? Number(data.client_id) : null,
            company_visa_type_id: data.company_visa_type_id
                ? Number(data.company_visa_type_id)
                : null,
            vessel_name: data.vessel_name || null,
            hire_date: data.hire_date || null,
            arrived_date: data.arrived_date || null,
            standby_from: data.standby_from || null,
            standby_to: data.standby_to || null,
            joined_date: data.joined_date || null,
            disembarked_date: data.disembarked_date || null,
            travelled_date: data.travelled_date || null,
            remarks: data.remarks || null,
        }));

        if (editing) {
            form.put(updateDeployment.url({ deployment: editing.id }), options);

            return;
        }

        form.post(storeDeployment.url(), options);
    };

    const fieldError = (key: string): string | undefined =>
        form.errors[key as keyof typeof form.errors];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] gap-0 overflow-hidden p-0 sm:max-w-3xl">
                <DialogHeader className="border-b border-border/60 px-6 py-5 dark:border-white/10">
                    <div className="flex flex-wrap items-start justify-between gap-3 pr-8">
                        <div className="space-y-1">
                            <DialogTitle className="text-lg">
                                {editing ? 'Edit deployment' : 'Add deployment'}
                            </DialogTitle>
                            <DialogDescription>
                                {editing
                                    ? 'Update assignment dates, vessel, sponsor, and ops notes.'
                                    : 'Record a crew assignment with tour dates and standby windows.'}
                            </DialogDescription>
                        </div>
                        {editing ? (
                            <DeploymentStatusBadge
                                status={editing.status}
                                label={editing.status_label}
                            />
                        ) : null}
                    </div>
                </DialogHeader>

                <div className="max-h-[min(60vh,640px)] space-y-6 overflow-y-auto px-6 py-5">
                    <FormSection title="Crew">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField
                                label="Employee"
                                error={fieldError('employee_id')}
                                className="sm:col-span-2"
                            >
                                <AppSelect
                                    value={form.data.employee_id}
                                    disabled={!!editing}
                                    onValueChange={(value) => {
                                        form.setData('employee_id', value);
                                        const employee = employees.find(
                                            (item) => String(item.id) === value,
                                        );
                                        if (employee?.rank_id) {
                                            form.setData('rank_id', String(employee.rank_id));
                                        }
                                    }}
                                    placeholder="Select employee"
                                    className={fieldInputClass}
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
                            </FormField>

                            <FormField label="Rank" error={fieldError('rank_id')}>
                                <AppSelect
                                    value={form.data.rank_id}
                                    onValueChange={(value) => form.setData('rank_id', value)}
                                    placeholder="Select rank"
                                    className={fieldInputClass}
                                >
                                    {ranks.map((rank) => (
                                        <AppSelectItem key={rank.id} value={String(rank.id)}>
                                            {rank.name}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                            </FormField>

                            <FormField label="Vessel" error={fieldError('vessel_name')}>
                                <Input
                                    value={form.data.vessel_name}
                                    onChange={(event) =>
                                        form.setData('vessel_name', event.target.value)
                                    }
                                    placeholder="e.g. Safeen OSV Pearl"
                                    className={fieldInputClass}
                                />
                            </FormField>
                        </div>
                    </FormSection>

                    <FormSection title="Assignment">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField label="Sponsor" error={fieldError('company_visa_type_id')}>
                                <AppSelect
                                    value={form.data.company_visa_type_id}
                                    onValueChange={(value) =>
                                        form.setData('company_visa_type_id', value)
                                    }
                                    placeholder="Select sponsor"
                                    className={fieldInputClass}
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
                            </FormField>

                            <FormField label="Client" error={fieldError('client_id')}>
                                <AppSelect
                                    value={form.data.client_id}
                                    onValueChange={(value) => form.setData('client_id', value)}
                                    placeholder="Select client"
                                    className={fieldInputClass}
                                >
                                    {clients.map((client) => (
                                        <AppSelectItem key={client.id} value={String(client.id)}>
                                            {client.name}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                            </FormField>
                        </div>
                    </FormSection>

                    <FormSection
                        title="Dates"
                        description="Hire and arrival dates feed standby and on-vessel status on the board."
                    >
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {DATE_FIELDS.map(({ field, label }) => (
                                <FormField
                                    key={field}
                                    label={label}
                                    htmlFor={field}
                                    error={fieldError(field)}
                                >
                                    <Input
                                        id={field}
                                        type="date"
                                        value={form.data[field as DateField]}
                                        onChange={(event) =>
                                            form.setData(field as DateField, event.target.value)
                                        }
                                        className={fieldInputClass}
                                    />
                                </FormField>
                            ))}
                        </div>
                    </FormSection>

                    <FormSection title="Remarks">
                        <FormField
                            label="Ops notes"
                            error={fieldError('remarks')}
                            hint="Standby details, travel plans, or handover notes."
                        >
                            <Textarea
                                value={form.data.remarks}
                                onChange={(event) =>
                                    form.setData('remarks', event.target.value)
                                }
                                placeholder="Standby pool until join, travel booked, etc."
                                rows={4}
                                className="min-h-[100px] resize-y rounded-xl border-border/60 bg-muted/50 px-4 py-3 text-sm focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5"
                            />
                        </FormField>
                    </FormSection>
                </div>

                <DialogFooter className="border-t border-border/60 px-6 py-4 dark:border-white/10">
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
                        disabled={!form.data.employee_id || form.processing}
                        onClick={submit}
                    >
                        {form.processing ? 'Saving…' : editing ? 'Save changes' : 'Add deployment'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
