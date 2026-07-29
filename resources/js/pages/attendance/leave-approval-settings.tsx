import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { FormEvent } from 'react';
import { index as leaveApprovalPolicies } from '@/actions/App/Http/Controllers/Attendance/LeaveApprovalPolicyController';
import { update as updateLeaveApprovalSettings } from '@/actions/App/Http/Controllers/Attendance/LeaveApprovalSettingController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';

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
};

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
    });

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
                description="Configure company-wide HR and fallback approvers."
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

            <Card className="mx-auto max-w-2xl glass-card border-border bg-card dark:border-white/5 dark:bg-white/5">
                <CardHeader>
                    <CardTitle className="text-xl font-bold tracking-tight">
                        Approver defaults
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="space-y-2">
                            <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                Default HR approver
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
                                        {employee.employee_no
                                            ? `${employee.employee_no} — ${employee.name}`
                                            : (employee.name ??
                                              `Employee #${employee.id}`)}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
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
                                Fallback approver
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
                                        {employee.employee_no
                                            ? `${employee.employee_no} — ${employee.name}`
                                            : (employee.name ??
                                              `Employee #${employee.id}`)}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
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
                </CardContent>
            </Card>
        </Main>
    );
}
