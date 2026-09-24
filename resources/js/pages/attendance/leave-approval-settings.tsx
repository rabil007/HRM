import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Info } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { index as leaveApprovalPolicies } from '@/actions/App/Http/Controllers/Attendance/LeaveApprovalPolicyController';
import { update as updateLeaveApprovalSettings } from '@/actions/App/Http/Controllers/Attendance/LeaveApprovalSettingController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';

type EmployeeOption = {
    id: number;
    name: string | null;
    employee_no: string | null;
    status?: string | null;
};

type LeaveApprovalSettings = {
    default_hr_approver_employee_id: number | null;
    fallback_approver_employee_id: number | null;
    default_hr_approver: EmployeeOption | null;
    fallback_approver: EmployeeOption | null;
    email_notifications_enabled: boolean;
    notify_on_submission: boolean;
    notify_on_update: boolean;
    notify_next_approver: boolean;
    notify_on_final_decision: boolean;
    copy_deciding_approver: boolean;
};

type NotificationSwitchKey =
    | 'email_notifications_enabled'
    | 'notify_on_submission'
    | 'notify_on_update'
    | 'notify_next_approver'
    | 'notify_on_final_decision'
    | 'copy_deciding_approver';

function NotificationSwitchRow({
    id,
    label,
    description,
    checked,
    disabled,
    muted,
    error,
    onCheckedChange,
}: {
    id: string;
    label: string;
    description?: string;
    checked: boolean;
    disabled: boolean;
    muted?: boolean;
    error?: string;
    onCheckedChange: (value: boolean) => void;
}) {
    return (
        <div
            className={cn(
                'flex items-start justify-between gap-4 rounded-xl border border-border/60 bg-muted/30 px-4 py-3',
                muted && 'opacity-60',
            )}
        >
            <div className="min-w-0 space-y-1">
                <Label
                    htmlFor={id}
                    className="text-sm font-semibold text-foreground"
                >
                    {label}
                </Label>
                {description ? (
                    <p className="text-xs text-muted-foreground/80">
                        {description}
                    </p>
                ) : null}
                {error ? (
                    <div className="text-xs font-medium text-destructive">
                        {error}
                    </div>
                ) : null}
            </div>
            <Switch
                id={id}
                checked={checked}
                disabled={disabled}
                onCheckedChange={onCheckedChange}
                aria-label={label}
            />
        </div>
    );
}

function ApproverFieldHelp({
    description,
    example,
    resolution,
}: {
    description: string;
    example: string;
    resolution: ReactNode;
}) {
    return (
        <div className="space-y-2">
            <p className="text-xs text-muted-foreground">{description}</p>
            <p className="text-xs text-muted-foreground/80">{example}</p>
            <div className="rounded-lg border border-border/50 bg-muted/20 px-3 py-2 font-mono text-[11px] leading-relaxed text-muted-foreground">
                {resolution}
            </div>
        </div>
    );
}

function employeeOptionLabel(employee: EmployeeOption): string {
    if (employee.employee_no) {
        return `${employee.employee_no} — ${employee.name}`;
    }

    return employee.name ?? `Employee #${employee.id}`;
}

export default function LeaveApprovalSettings({
    settings,
    employees,
    warnings,
    can,
}: {
    settings: LeaveApprovalSettings;
    employees: EmployeeOption[];
    warnings: {
        default_hr_approver: string[];
        fallback_approver: string[];
    };
    can: { update: boolean };
}) {
    const form = useForm({
        default_hr_approver_employee_id:
            settings.default_hr_approver_employee_id ?? ('' as number | ''),
        fallback_approver_employee_id:
            settings.fallback_approver_employee_id ?? ('' as number | ''),
        email_notifications_enabled: settings.email_notifications_enabled,
        notify_on_submission: settings.notify_on_submission,
        notify_on_update: settings.notify_on_update,
        notify_next_approver: settings.notify_next_approver,
        notify_on_final_decision: settings.notify_on_final_decision,
        copy_deciding_approver: settings.copy_deciding_approver,
    });

    const emailsEnabled = form.data.email_notifications_enabled;
    const eventSwitchesDisabled = !can.update || !emailsEnabled;

    const setNotification = (key: NotificationSwitchKey, value: boolean) => {
        form.setData(key, value);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();

        form.transform((data) => ({
            default_hr_approver_employee_id:
                data.default_hr_approver_employee_id === ''
                    ? null
                    : data.default_hr_approver_employee_id,
            fallback_approver_employee_id:
                data.fallback_approver_employee_id === ''
                    ? null
                    : data.fallback_approver_employee_id,
            email_notifications_enabled: data.email_notifications_enabled,
            notify_on_submission: data.notify_on_submission,
            notify_on_update: data.notify_on_update,
            notify_next_approver: data.notify_next_approver,
            notify_on_final_decision: data.notify_on_final_decision,
            copy_deciding_approver: data.copy_deciding_approver,
        }));

        form.put(updateLeaveApprovalSettings.url(), {
            preserveScroll: true,
            onFinish: () => form.transform((data) => data),
        });
    };

    return (
        <Main>
            <Head title="Leave approval settings" />

            <PageHeader
                title="Leave approval settings"
                description="Configure company-wide approver defaults and leave-request email notifications."
                right={
                    <Button
                        variant="secondary"
                        className="h-12 rounded-xl glass-card px-5 hover:bg-accent"
                        asChild
                    >
                        <Link href={leaveApprovalPolicies.url()}>
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back to policies
                        </Link>
                    </Button>
                }
            />

            <form onSubmit={submit} className="mx-auto max-w-2xl space-y-6">
                <Card className="glass-card border-border bg-card dark:border-white/5 dark:bg-white/5">
                    <CardHeader className="space-y-2">
                        <CardTitle className="text-xl font-bold tracking-tight">
                            Approver defaults
                        </CardTitle>
                        <CardDescription className="space-y-1.5 text-sm text-muted-foreground">
                            <span className="block">
                                These settings support your Leave Approval
                                Policies. The policy defines which approval
                                steps are required; these defaults are used only
                                when certain steps need a person to be resolved
                                automatically.
                            </span>
                            <span className="block text-xs text-muted-foreground/80">
                                Changing these settings does not add or remove
                                approval steps from a policy.
                            </span>
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <Alert className="border-border/60 bg-muted/20 dark:border-white/10 dark:bg-white/5">
                            <Info className="size-4 text-muted-foreground" />
                            <AlertTitle className="text-sm font-semibold">
                                How this works
                            </AlertTitle>
                            <AlertDescription className="space-y-3 text-xs text-muted-foreground">
                                <p>
                                    Leave Approval Policy defines the workflow.
                                    Approver defaults help resolve people used
                                    by that workflow.
                                </p>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="rounded-lg border border-border/50 bg-background/60 px-3 py-2 font-mono leading-relaxed dark:bg-black/20">
                                        <p className="mb-1 font-sans text-[10px] font-semibold tracking-wide text-foreground uppercase">
                                            Policy
                                        </p>
                                        <p>1. Department Manager</p>
                                        <p>2. HR Approver</p>
                                        <p>3. Maher — Notify only</p>
                                    </div>
                                    <div className="rounded-lg border border-border/50 bg-background/60 px-3 py-2 font-mono leading-relaxed dark:bg-black/20">
                                        <p className="mb-1 font-sans text-[10px] font-semibold tracking-wide text-foreground uppercase">
                                            Approver defaults
                                        </p>
                                        <p>Default HR Approver: Rima</p>
                                        <p>Department Manager Fallback: Sara</p>
                                    </div>
                                </div>
                                <p>
                                    If the employee&apos;s department manager is
                                    Adam: Adam → Rima, and Maher receives an FYI
                                    notification. Sara is not used because the
                                    department manager resolved successfully.
                                </p>
                            </AlertDescription>
                        </Alert>

                        <div className="space-y-2">
                            <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                Default HR Approver
                            </Label>
                            <AppSelect
                                value={String(
                                    form.data.default_hr_approver_employee_id ??
                                        '',
                                )}
                                onValueChange={(v) =>
                                    form.setData(
                                        'default_hr_approver_employee_id',
                                        v ? Number(v) : '',
                                    )
                                }
                                variant="card"
                                placeholder="Select employee"
                                disabled={!can.update}
                            >
                                <AppSelectItem value="">None</AppSelectItem>
                                {employees.map((employee) => (
                                    <AppSelectItem
                                        key={employee.id}
                                        value={String(employee.id)}
                                    >
                                        {employeeOptionLabel(employee)}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            <ApproverFieldHelp
                                description="Used when a Leave Approval Policy contains an HR Approver step but that policy step does not have a specific employee selected. This is a reusable company-level default — it does not automatically add an HR approval step to every policy."
                                example='Example: If the policy contains "HR Approver → Required" with no employee selected, this employee becomes the HR approver.'
                                resolution={
                                    <>
                                        <p>Policy: HR Approver</p>
                                        <p>Specific employee selected?</p>
                                        <p>Yes → Use that employee</p>
                                        <p>No → Use Default HR Approver</p>
                                    </>
                                }
                            />
                            {form.errors.default_hr_approver_employee_id ? (
                                <div className="text-xs font-medium text-destructive">
                                    {
                                        form.errors
                                            .default_hr_approver_employee_id
                                    }
                                </div>
                            ) : null}
                            {warnings.default_hr_approver.length > 0 ? (
                                <ul className="space-y-1 rounded-xl border border-amber-500/20 bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-200">
                                    {warnings.default_hr_approver.map(
                                        (warning) => (
                                            <li key={warning}>{warning}</li>
                                        ),
                                    )}
                                </ul>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                Department Manager Fallback
                            </Label>
                            <AppSelect
                                value={String(
                                    form.data.fallback_approver_employee_id ??
                                        '',
                                )}
                                onValueChange={(v) =>
                                    form.setData(
                                        'fallback_approver_employee_id',
                                        v ? Number(v) : '',
                                    )
                                }
                                variant="card"
                                placeholder="Select employee"
                                disabled={!can.update}
                            >
                                <AppSelectItem value="">None</AppSelectItem>
                                {employees.map((employee) => (
                                    <AppSelectItem
                                        key={employee.id}
                                        value={String(employee.id)}
                                    >
                                        {employeeOptionLabel(employee)}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            <ApproverFieldHelp
                                description="Used when a Leave Approval Policy requires a Department Manager, but the system cannot find a valid actionable manager from the employee's department management chain. This is not a universal fallback for Parent Manager, Specific Employee, or other step types."
                                example="Example: If an employee's department has no valid manager, this employee can be used instead so the approval workflow does not get stuck."
                                resolution={
                                    <>
                                        <p>
                                            Department Manager missing →
                                            Department Manager Fallback
                                        </p>
                                        <p>
                                            HR Approver not selected → Default
                                            HR Approver
                                        </p>
                                        <p>
                                            Parent Manager / Specific Employee →
                                            no company fallback
                                        </p>
                                    </>
                                }
                            />
                            {form.errors.fallback_approver_employee_id ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.fallback_approver_employee_id}
                                </div>
                            ) : null}
                            {warnings.fallback_approver.length > 0 ? (
                                <ul className="space-y-1 rounded-xl border border-amber-500/20 bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-200">
                                    {warnings.fallback_approver.map(
                                        (warning) => (
                                            <li key={warning}>{warning}</li>
                                        ),
                                    )}
                                </ul>
                            ) : null}
                        </div>
                    </CardContent>
                </Card>

                <Card className="glass-card border-border bg-card dark:border-white/5 dark:bg-white/5">
                    <CardHeader className="space-y-2">
                        <CardTitle className="text-xl font-bold tracking-tight">
                            Email notifications
                        </CardTitle>
                        <p className="text-sm text-muted-foreground">
                            These company switches control whether leave-request
                            emails are sent. Email Templates still control
                            message content and must also be enabled.
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <NotificationSwitchRow
                            id="email_notifications_enabled"
                            label="Enable leave-request email notifications"
                            description="Master switch for all leave-request emails. Turning this off does not change approval workflow."
                            checked={form.data.email_notifications_enabled}
                            disabled={!can.update}
                            error={form.errors.email_notifications_enabled}
                            onCheckedChange={(value) =>
                                setNotification(
                                    'email_notifications_enabled',
                                    value,
                                )
                            }
                        />

                        <NotificationSwitchRow
                            id="notify_on_submission"
                            label="Notify current approver when a request is submitted"
                            checked={form.data.notify_on_submission}
                            disabled={eventSwitchesDisabled}
                            muted={!emailsEnabled}
                            error={form.errors.notify_on_submission}
                            onCheckedChange={(value) =>
                                setNotification('notify_on_submission', value)
                            }
                        />

                        <NotificationSwitchRow
                            id="notify_on_update"
                            label="Notify current approver when a request is updated"
                            checked={form.data.notify_on_update}
                            disabled={eventSwitchesDisabled}
                            muted={!emailsEnabled}
                            error={form.errors.notify_on_update}
                            onCheckedChange={(value) =>
                                setNotification('notify_on_update', value)
                            }
                        />

                        <NotificationSwitchRow
                            id="notify_next_approver"
                            label="Notify the next approver when their approval becomes required"
                            checked={form.data.notify_next_approver}
                            disabled={eventSwitchesDisabled}
                            muted={!emailsEnabled}
                            error={form.errors.notify_next_approver}
                            onCheckedChange={(value) =>
                                setNotification('notify_next_approver', value)
                            }
                        />

                        <NotificationSwitchRow
                            id="notify_on_final_decision"
                            label="Notify employee when the request is approved or rejected"
                            checked={form.data.notify_on_final_decision}
                            disabled={eventSwitchesDisabled}
                            muted={!emailsEnabled}
                            error={form.errors.notify_on_final_decision}
                            onCheckedChange={(value) =>
                                setNotification(
                                    'notify_on_final_decision',
                                    value,
                                )
                            }
                        />

                        <NotificationSwitchRow
                            id="copy_deciding_approver"
                            label="Send final-decision copy to the deciding approver"
                            description="Only applies when final-decision notifications are enabled."
                            checked={form.data.copy_deciding_approver}
                            disabled={eventSwitchesDisabled}
                            muted={!emailsEnabled}
                            error={form.errors.copy_deciding_approver}
                            onCheckedChange={(value) =>
                                setNotification('copy_deciding_approver', value)
                            }
                        />
                    </CardContent>
                </Card>

                {can.update ? (
                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            className="h-11 rounded-xl px-6 font-semibold"
                            disabled={form.processing}
                        >
                            Save settings
                        </Button>
                    </div>
                ) : null}
            </form>
        </Main>
    );
}
