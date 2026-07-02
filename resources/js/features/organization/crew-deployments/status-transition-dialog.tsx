import { useForm } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useEffect } from 'react';
import type { ReactElement } from 'react';
import { update as updateDeployment } from '@/actions/App/Http/Controllers/Organization/CrewDeploymentController';
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
import { DeploymentStatusBadge } from '@/features/organization/crew-deployments/deployment-status-badge';
import type { DeploymentItem } from '@/features/organization/crew-deployments/types';
import { actions } from '@/lib/design-system';

type Option = { id: number; name: string };

/** Date fields shown in the dialog per target status */
const STATUS_DATE_FIELDS: Record<string, { key: string; label: string }[]> = {
    arrived: [{ key: 'arrived_date', label: 'Arrived date' }],
    join_standby: [
        { key: 'join_standby_from', label: 'Join standby from' },
        { key: 'join_standby_to', label: 'Join standby to' },
    ],
    on_vessel: [{ key: 'joined_date', label: 'Joined date' }],
    disembarked: [{ key: 'disembarked_date', label: 'Disembarked date' }],
    leave_standby: [
        { key: 'leave_standby_from', label: 'Leave standby from' },
        { key: 'leave_standby_to', label: 'Leave standby to' },
    ],
    travel: [{ key: 'travelled_date', label: 'Travelled date' }],
    in_home: [],
    unknown: [],
};

const STATUS_LABELS: Record<string, string> = {
    unknown: 'Needs update',
    arrived: 'Arrived',
    join_standby: 'Join standby',
    on_vessel: 'On vessel',
    disembarked: 'Disembarked',
    leave_standby: 'Leave standby',
    travel: 'Travel',
    in_home: 'In home',
};

type FormData = {
    arrived_date: string;
    join_standby_from: string;
    join_standby_to: string;
    joined_date: string;
    disembarked_date: string;
    leave_standby_from: string;
    leave_standby_to: string;
    travelled_date: string;
    vessel_id: string;
};

const fieldInputClass =
    'h-10 rounded-xl border-border/60 bg-muted/50 text-sm focus-visible:ring-primary/40 dark:border-white/10 dark:bg-white/5';

type Props = {
    open: boolean;
    deployment: DeploymentItem | null;
    targetStatus: string | null;
    vessels: Option[];
    onOpenChange: (open: boolean) => void;
};

export function StatusTransitionDialog({
    open,
    deployment,
    targetStatus,
    vessels,
    onOpenChange,
}: Props): ReactElement {
    const form = useForm<FormData>({
        arrived_date: '',
        join_standby_from: '',
        join_standby_to: '',
        joined_date: '',
        disembarked_date: '',
        leave_standby_from: '',
        leave_standby_to: '',
        travelled_date: '',
        vessel_id: '',
    });

    useEffect(() => {
        if (!open || !deployment) {
            return;
        }

        form.setData({
            arrived_date: deployment.arrived_date ?? '',
            join_standby_from: deployment.join_standby_from ?? '',
            join_standby_to: deployment.join_standby_to ?? '',
            joined_date: deployment.joined_date ?? '',
            disembarked_date: deployment.disembarked_date ?? '',
            leave_standby_from: deployment.leave_standby_from ?? '',
            leave_standby_to: deployment.leave_standby_to ?? '',
            travelled_date: deployment.travelled_date ?? '',
            vessel_id: deployment.vessel_id ? String(deployment.vessel_id) : '',
        });
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [deployment, open]);

    if (!deployment || !targetStatus) {
        return <></>;
    }

    const dateFields = STATUS_DATE_FIELDS[targetStatus] ?? [];
    const showVesselField = targetStatus === 'on_vessel';
    const hasFields = dateFields.length > 0 || showVesselField;

    const submit = (): void => {
        form.transform((data) => ({
            employee_id: deployment.employee_id,
            rank_id: deployment.rank_id ?? null,
            client_id: deployment.client_id ?? null,
            company_visa_type_id: deployment.company_visa_type_id ?? null,
            vessel_id: data.vessel_id
                ? Number(data.vessel_id)
                : (deployment.vessel_id ?? null),
            arrived_date: data.arrived_date || null,
            join_standby_from: data.join_standby_from || null,
            join_standby_to: data.join_standby_to || null,
            leave_standby_from: data.leave_standby_from || null,
            leave_standby_to: data.leave_standby_to || null,
            joined_date: data.joined_date || null,
            disembarked_date: data.disembarked_date || null,
            travelled_date: data.travelled_date || null,
            remarks: deployment.remarks ?? null,
        }));

        form.put(updateDeployment.url({ deployment: deployment.id }), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="gap-0 overflow-hidden p-0 sm:max-w-md">
                <DialogHeader className="border-b border-border/60 px-6 py-5 dark:border-white/10">
                    <DialogTitle className="text-base">
                        Move deployment
                    </DialogTitle>
                    <DialogDescription asChild>
                        <div className="flex items-center gap-2 pt-2">
                            <DeploymentStatusBadge
                                status={deployment.status}
                                label={deployment.status_label}
                            />
                            <ArrowRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                            <DeploymentStatusBadge
                                status={targetStatus}
                                label={
                                    STATUS_LABELS[targetStatus] ?? targetStatus
                                }
                            />
                        </div>
                    </DialogDescription>
                    <p className="mt-2 text-xs text-muted-foreground">
                        Moving{' '}
                        <span className="font-semibold text-foreground">
                            {deployment.employee_name ?? 'Unknown'}
                        </span>{' '}
                        to{' '}
                        <span className="font-semibold text-foreground">
                            {STATUS_LABELS[targetStatus] ?? targetStatus}
                        </span>
                        {hasFields
                            ? '. Fill in the date(s) for this stage.'
                            : '.'}
                    </p>
                </DialogHeader>

                {hasFields ? (
                    <div className="space-y-4 px-6 py-5">
                        {showVesselField ? (
                            <div className="space-y-1.5">
                                <Label className="text-xs font-medium">
                                    Vessel
                                </Label>
                                <AppSelect
                                    value={form.data.vessel_id}
                                    onValueChange={(value) =>
                                        form.setData('vessel_id', value)
                                    }
                                    placeholder="Select vessel"
                                    className={fieldInputClass}
                                >
                                    {vessels.map((vessel) => (
                                        <AppSelectItem
                                            key={vessel.id}
                                            value={String(vessel.id)}
                                        >
                                            {vessel.name}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                                {form.errors.vessel_id ? (
                                    <p className="text-xs text-destructive">
                                        {form.errors.vessel_id}
                                    </p>
                                ) : null}
                            </div>
                        ) : null}

                        {dateFields.map(({ key, label }) => (
                            <div key={key} className="space-y-1.5">
                                <Label
                                    htmlFor={key}
                                    className="text-xs font-medium"
                                >
                                    {label}
                                </Label>
                                <Input
                                    id={key}
                                    type="date"
                                    value={form.data[key as keyof FormData]}
                                    onChange={(e) =>
                                        form.setData(
                                            key as keyof FormData,
                                            e.target.value,
                                        )
                                    }
                                    className={fieldInputClass}
                                />
                                {form.errors[
                                    key as keyof typeof form.errors
                                ] ? (
                                    <p className="text-xs text-destructive">
                                        {
                                            form.errors[
                                                key as keyof typeof form.errors
                                            ]
                                        }
                                    </p>
                                ) : null}
                            </div>
                        ))}
                    </div>
                ) : (
                    <div className="px-6 py-5">
                        <p className="text-sm text-muted-foreground">
                            No additional information needed. Click confirm to
                            move.
                        </p>
                    </div>
                )}

                <DialogFooter className="border-t border-border/60 px-6 py-4 dark:border-white/10">
                    <Button
                        type="button"
                        variant="outline"
                        className={actions.dialogSecondary}
                        onClick={() => onOpenChange(false)}
                        disabled={form.processing}
                    >
                        Cancel — keep in {deployment.status_label}
                    </Button>
                    <Button
                        type="button"
                        className={actions.dialogPrimary}
                        disabled={form.processing}
                        onClick={submit}
                    >
                        {form.processing ? 'Saving…' : 'Confirm move'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
