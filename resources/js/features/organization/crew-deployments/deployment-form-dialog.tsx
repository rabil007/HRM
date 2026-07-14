import { useForm } from '@inertiajs/react';
import { Building2, CalendarDays, FileText, User } from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';
import { useEffect } from 'react';
import {
    store as storeDeployment,
    update as updateDeployment,
} from '@/actions/App/Http/Controllers/Organization/CrewDeploymentController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { CreatableSelect } from '@/components/ui/creatable-select';
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
import { DeploymentStatusBadge } from '@/features/organization/crew-deployments/deployment-status-badge';
import type { DeploymentItem } from '@/features/organization/crew-deployments/types';
import { useCreatableMasterData } from '@/hooks/use-creatable-master-data';
import { useMutableSelectOptions } from '@/hooks/use-mutable-select-options';
import { actions } from '@/lib/design-system';
import { cn } from '@/lib/utils';

type Option = { id: number; name: string };
type EmployeeOption = {
    id: number;
    employee_no: string;
    name: string;
    rank_id: number | null;
};

const fieldInputClass =
    'h-10 rounded-xl border-border/60 bg-background text-sm focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5';

// ─── helpers ──────────────────────────────────────────────────────────────────

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

function getInitials(name: string): string {
    return name
        .split(' ')
        .slice(0, 2)
        .map((n) => n[0])
        .join('')
        .toUpperCase();
}

// ─── sub-components ───────────────────────────────────────────────────────────

function SectionHeader({
    icon,
    title,
    description,
}: {
    icon: ReactNode;
    title: string;
    description?: string;
}): ReactElement {
    return (
        <div className="flex items-start gap-3 pb-3">
            <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                {icon}
            </div>
            <div>
                <div className="text-sm font-semibold">{title}</div>
                {description ? (
                    <p className="mt-0.5 text-[11px] leading-relaxed text-muted-foreground">
                        {description}
                    </p>
                ) : null}
            </div>
        </div>
    );
}

function FormField({
    label,
    htmlFor,
    error,
    hint,
    required,
    className,
    children,
}: {
    label: string;
    htmlFor?: string;
    error?: string;
    hint?: string;
    required?: boolean;
    className?: string;
    children: ReactNode;
}): ReactElement {
    return (
        <div className={cn('space-y-1.5', className)}>
            <Label
                htmlFor={htmlFor}
                className="flex items-center gap-1 text-xs font-medium"
            >
                {label}
                {required ? <span className="text-destructive">*</span> : null}
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

function DateInput({
    id,
    value,
    onChange,
    error,
    disabled,
}: {
    id: string;
    value: string;
    onChange: (v: string) => void;
    error?: string;
    disabled?: boolean;
}): ReactElement {
    return (
        <div className="space-y-1">
            <div className="flex gap-1.5">
                <Input
                    id={id}
                    type="date"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    disabled={disabled}
                    className={cn(fieldInputClass, 'flex-1')}
                />
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={disabled}
                    onClick={() => onChange(todayIso())}
                    className="h-10 shrink-0 rounded-xl border-border/60 px-2.5 text-[10px] font-bold tracking-wider text-muted-foreground uppercase hover:text-foreground dark:border-white/10"
                >
                    Today
                </Button>
                {value ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={disabled}
                        onClick={() => onChange('')}
                        className="h-10 shrink-0 rounded-xl px-2.5 text-[10px] text-muted-foreground/50 hover:text-muted-foreground"
                    >
                        ✕
                    </Button>
                ) : null}
            </div>
            {error ? <p className="text-xs text-destructive">{error}</p> : null}
        </div>
    );
}

/** A visual step in the deployment timeline */
function TimelineStep({
    icon,
    label,
    accent,
    children,
    isLast,
}: {
    icon: string;
    label: string;
    accent: string;
    children: ReactNode;
    isLast?: boolean;
}): ReactElement {
    return (
        <div className="relative flex gap-4">
            {/* Connector line */}
            {!isLast ? (
                <div className="absolute top-9 left-4 h-full w-px bg-border/60" />
            ) : null}

            {/* Icon */}
            <div
                className={cn(
                    'relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 bg-background text-base',
                    accent,
                )}
            >
                {icon}
            </div>

            {/* Content */}
            <div className="flex-1 pb-6">
                <div className="mt-0.5 mb-2 text-[11px] font-bold tracking-widest text-muted-foreground/70 uppercase">
                    {label}
                </div>
                {children}
            </div>
        </div>
    );
}

// ─── main dialog ──────────────────────────────────────────────────────────────

export function DeploymentFormDialog({
    open,
    onOpenChange,
    editing,
    employees,
    ranks,
    clients,
    companyVisaTypes,
    vessels,
    redirectToShow = false,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    editing: DeploymentItem | null;
    employees: EmployeeOption[];
    ranks: Option[];
    clients: Option[];
    companyVisaTypes: Option[];
    vessels: Option[];
    redirectToShow?: boolean;
}): ReactElement {
    const {
        selectOptions: vesselSelectOptions,
        appendOption: appendVesselOption,
    } = useMutableSelectOptions(vessels);
    const { canCreate: canCreateVessel, createConfig: vesselCreateConfig } =
        useCreatableMasterData('vessel');

    const form = useForm({
        employee_id: '',
        rank_id: '',
        client_id: '',
        company_visa_type_id: '',
        vessel_id: '',
        arrived_date: '',
        join_standby_from: '',
        join_standby_to: '',
        leave_standby_from: '',
        leave_standby_to: '',
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
                vessel_id: editing.vessel_id ? String(editing.vessel_id) : '',
                arrived_date: editing.arrived_date ?? '',
                join_standby_from: editing.join_standby_from ?? '',
                join_standby_to: editing.join_standby_to ?? '',
                leave_standby_from: editing.leave_standby_from ?? '',
                leave_standby_to: editing.leave_standby_to ?? '',
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
        // eslint-disable-next-line react-hooks/exhaustive-deps
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
            vessel_id: data.vessel_id ? Number(data.vessel_id) : null,
            arrived_date: data.arrived_date || null,
            join_standby_from: data.join_standby_from || null,
            join_standby_to: data.join_standby_to || null,
            leave_standby_from: data.leave_standby_from || null,
            leave_standby_to: data.leave_standby_to || null,
            joined_date: data.joined_date || null,
            disembarked_date: data.disembarked_date || null,
            travelled_date: data.travelled_date || null,
            remarks: data.remarks || null,
            require_disembarked_with_joined: Boolean(data.joined_date),
            ...(redirectToShow ? { redirect_to: 'show' as const } : {}),
        }));

        if (editing) {
            form.put(updateDeployment.url({ deployment: editing.id }), options);

            return;
        }

        form.post(storeDeployment.url(), options);
    };

    const err = (key: string): string | undefined =>
        form.errors[key as keyof typeof form.errors];

    const selectedEmployee = employees.find(
        (e) => String(e.id) === form.data.employee_id,
    );
    const joinedDateRequiresDisembarked = Boolean(form.data.joined_date);

    const handleJoinedDateChange = (value: string): void => {
        if (!value) {
            form.setData((data) => ({
                ...data,
                joined_date: '',
                disembarked_date: '',
            }));

            return;
        }

        form.setData('joined_date', value);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[92vh] gap-0 overflow-hidden p-0 sm:max-w-2xl">
                {/* ── Header ───────────────────────────────────────── */}
                <DialogHeader className="border-b border-border/60 px-6 py-4 dark:border-white/10">
                    <div className="flex items-center justify-between gap-4 pr-8">
                        <div className="flex items-center gap-3">
                            {editing ? (
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-bold text-muted-foreground uppercase">
                                    {getInitials(editing.employee_name ?? '?')}
                                </div>
                            ) : null}
                            <div>
                                <DialogTitle className="text-base font-bold">
                                    {editing
                                        ? (editing.employee_name ??
                                          'Edit deployment')
                                        : 'New deployment'}
                                </DialogTitle>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {editing
                                        ? `${editing.employee_no ?? ''}${editing.employee_no ? ' · ' : ''}${editing.vessel_name ?? 'No vessel'}`
                                        : 'Record a new crew assignment'}
                                </p>
                            </div>
                        </div>
                        {editing ? (
                            <DeploymentStatusBadge
                                status={editing.status}
                                label={editing.status_label}
                                hint={editing.status_hint}
                            />
                        ) : null}
                    </div>
                </DialogHeader>

                {/* ── Body ─────────────────────────────────────────── */}
                <div className="max-h-[calc(92vh-140px)] overflow-y-auto">
                    {/* Crew section */}
                    <div className="border-b border-border/40 px-6 py-5">
                        <SectionHeader
                            icon={<User className="h-4 w-4" />}
                            title="Crew"
                        />

                        <div className="space-y-4">
                            {/* Employee */}
                            <FormField
                                label="Employee"
                                error={err('employee_id')}
                                required
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
                                            form.setData(
                                                'rank_id',
                                                String(employee.rank_id),
                                            );
                                        }
                                    }}
                                    placeholder="Search and select employee…"
                                    className={fieldInputClass}
                                >
                                    {employees.map((employee) => (
                                        <AppSelectItem
                                            key={employee.id}
                                            value={String(employee.id)}
                                        >
                                            {employee.employee_no} —{' '}
                                            {employee.name}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                            </FormField>

                            {/* Employee mini-card — shown after selection */}
                            {selectedEmployee ? (
                                <div className="flex items-center gap-3 rounded-xl border border-border/40 bg-muted/30 px-3.5 py-2.5">
                                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-[11px] font-bold text-muted-foreground uppercase">
                                        {getInitials(selectedEmployee.name)}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-sm font-semibold">
                                            {selectedEmployee.name}
                                        </div>
                                        <div className="font-mono text-[10px] text-muted-foreground">
                                            {selectedEmployee.employee_no}
                                        </div>
                                    </div>
                                </div>
                            ) : null}

                            {/* Rank + Vessel */}
                            <div className="grid grid-cols-2 gap-4">
                                <FormField label="Rank" error={err('rank_id')}>
                                    <AppSelect
                                        value={form.data.rank_id}
                                        onValueChange={(value) =>
                                            form.setData('rank_id', value)
                                        }
                                        placeholder="Select rank"
                                        className={fieldInputClass}
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
                                </FormField>

                                <FormField
                                    label="Vessel"
                                    error={err('vessel_id')}
                                >
                                    <CreatableSelect
                                        value={form.data.vessel_id}
                                        onValueChange={(value) =>
                                            form.setData('vessel_id', value)
                                        }
                                        variant="dark"
                                        placeholder="Select vessel"
                                        options={vesselSelectOptions}
                                        onOptionsChange={(next) => {
                                            const added = next.find(
                                                (option) =>
                                                    !vesselSelectOptions.some(
                                                        (existing) =>
                                                            existing.value ===
                                                            option.value,
                                                    ),
                                            );

                                            if (added) {
                                                appendVesselOption({
                                                    id: added.id,
                                                    label: added.label,
                                                });
                                            }
                                        }}
                                        creatable
                                        canCreate={canCreateVessel}
                                        createConfig={vesselCreateConfig}
                                        className={fieldInputClass}
                                    />
                                </FormField>
                            </div>
                        </div>
                    </div>

                    {/* Assignment section */}
                    <div className="border-b border-border/40 px-6 py-5">
                        <SectionHeader
                            icon={<Building2 className="h-4 w-4" />}
                            title="Assignment"
                        />

                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                label="Sponsor"
                                error={err('company_visa_type_id')}
                            >
                                <AppSelect
                                    value={form.data.company_visa_type_id}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'company_visa_type_id',
                                            value,
                                        )
                                    }
                                    placeholder="Select sponsor"
                                    className={fieldInputClass}
                                >
                                    {companyVisaTypes.map((cvt) => (
                                        <AppSelectItem
                                            key={cvt.id}
                                            value={String(cvt.id)}
                                        >
                                            {cvt.name}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                            </FormField>

                            <FormField label="Client" error={err('client_id')}>
                                <AppSelect
                                    value={form.data.client_id}
                                    onValueChange={(value) =>
                                        form.setData('client_id', value)
                                    }
                                    placeholder="Select client"
                                    className={fieldInputClass}
                                >
                                    {clients.map((client) => (
                                        <AppSelectItem
                                            key={client.id}
                                            value={String(client.id)}
                                        >
                                            {client.name}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                            </FormField>
                        </div>
                    </div>

                    {/* Dates section — timeline style */}
                    <div className="border-b border-border/40 px-6 py-5">
                        <SectionHeader
                            icon={<CalendarDays className="h-4 w-4" />}
                            title="Deployment timeline"
                            description="Fill in dates as the crew member progresses through each stage. Leave future stages blank."
                        />

                        <div className="pl-1">
                            {/* 1. Arrived */}
                            <TimelineStep
                                icon="📍"
                                label="Arrived"
                                accent="border-sky-500/40"
                            >
                                <DateInput
                                    id="arrived_date"
                                    value={form.data.arrived_date}
                                    onChange={(v) =>
                                        form.setData('arrived_date', v)
                                    }
                                    error={err('arrived_date')}
                                />
                            </TimelineStep>

                            {/* 2. Join standby */}
                            <TimelineStep
                                icon="⏳"
                                label="Join standby"
                                accent="border-amber-500/40"
                            >
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="space-y-1">
                                        <Label className="text-[10px] font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                            From
                                        </Label>
                                        <DateInput
                                            id="join_standby_from"
                                            value={form.data.join_standby_from}
                                            onChange={(v) =>
                                                form.setData(
                                                    'join_standby_from',
                                                    v,
                                                )
                                            }
                                            error={err('join_standby_from')}
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label className="text-[10px] font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                            To
                                        </Label>
                                        <DateInput
                                            id="join_standby_to"
                                            value={form.data.join_standby_to}
                                            onChange={(v) =>
                                                form.setData(
                                                    'join_standby_to',
                                                    v,
                                                )
                                            }
                                            error={err('join_standby_to')}
                                        />
                                    </div>
                                </div>
                            </TimelineStep>

                            {/* 3. On vessel */}
                            <TimelineStep
                                icon="⚓"
                                label="On vessel"
                                accent="border-emerald-500/40"
                            >
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="space-y-1">
                                        <Label className="flex items-center gap-1 text-[10px] font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                            Joined
                                        </Label>
                                        <DateInput
                                            id="joined_date"
                                            value={form.data.joined_date}
                                            onChange={handleJoinedDateChange}
                                            error={err('joined_date')}
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label className="flex items-center gap-1 text-[10px] font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                            Disembarked
                                            {joinedDateRequiresDisembarked ? (
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            ) : null}
                                        </Label>
                                        <DateInput
                                            id="disembarked_date"
                                            value={form.data.disembarked_date}
                                            onChange={(v) =>
                                                form.setData(
                                                    'disembarked_date',
                                                    v,
                                                )
                                            }
                                            error={err('disembarked_date')}
                                        />
                                    </div>
                                </div>
                            </TimelineStep>

                            {/* 4. Leave standby */}
                            <TimelineStep
                                icon="⏳"
                                label="Leave standby"
                                accent="border-orange-500/40"
                            >
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="space-y-1">
                                        <Label className="text-[10px] font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                            From
                                        </Label>
                                        <DateInput
                                            id="leave_standby_from"
                                            value={form.data.leave_standby_from}
                                            onChange={(v) =>
                                                form.setData(
                                                    'leave_standby_from',
                                                    v,
                                                )
                                            }
                                            error={err('leave_standby_from')}
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label className="text-[10px] font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                            To
                                        </Label>
                                        <DateInput
                                            id="leave_standby_to"
                                            value={form.data.leave_standby_to}
                                            onChange={(v) =>
                                                form.setData(
                                                    'leave_standby_to',
                                                    v,
                                                )
                                            }
                                            error={err('leave_standby_to')}
                                        />
                                    </div>
                                </div>
                            </TimelineStep>

                            {/* 5. Travel */}
                            <TimelineStep
                                icon="✈️"
                                label="Travel"
                                accent="border-violet-500/40"
                                isLast
                            >
                                <DateInput
                                    id="travelled_date"
                                    value={form.data.travelled_date}
                                    onChange={(v) =>
                                        form.setData('travelled_date', v)
                                    }
                                    error={err('travelled_date')}
                                />
                            </TimelineStep>
                        </div>
                    </div>

                    {/* Remarks section */}
                    <div className="px-6 py-5">
                        <SectionHeader
                            icon={<FileText className="h-4 w-4" />}
                            title="Ops notes"
                        />

                        <div className="space-y-1.5">
                            <Textarea
                                value={form.data.remarks}
                                onChange={(event) =>
                                    form.setData('remarks', event.target.value)
                                }
                                placeholder="Standby pool until join, travel booked, visa pending…"
                                rows={3}
                                className="resize-y rounded-xl border-border/60 bg-background px-4 py-3 text-sm focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5"
                            />
                            {err('remarks') ? (
                                <p className="text-xs text-destructive">
                                    {err('remarks')}
                                </p>
                            ) : (
                                <p className="text-[11px] text-muted-foreground">
                                    Standby details, travel plans, or handover
                                    notes.
                                </p>
                            )}
                        </div>
                    </div>
                </div>

                {/* ── Footer ───────────────────────────────────────── */}
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
                        {form.processing
                            ? 'Saving…'
                            : editing
                              ? 'Save changes'
                              : 'Add deployment'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
