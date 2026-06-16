import { Link  } from '@inertiajs/react';
import type {InertiaFormProps} from '@inertiajs/react';
import { AlertCircle, FileText, Trash2, Upload } from 'lucide-react';
import { useId } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { formatUploadFileSize } from '@/features/organization/documents/upload/upload-draft';
import type { LeaveRequest, LeaveRequestEmployeeOption, LeaveRequestFormData, LeaveRequestTypeOption } from '../types';

const inputClass = 'rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all';

export function LeaveRequestFormSheet({
    open,
    onOpenChange,
    leaveRequest,
    employees,
    leaveTypes,
    canApprove,
    linkedEmployeeId,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    leaveRequest: LeaveRequest | null;
    employees: LeaveRequestEmployeeOption[];
    leaveTypes: LeaveRequestTypeOption[];
    canApprove: boolean;
    linkedEmployeeId: number | null;
    form: InertiaFormProps<LeaveRequestFormData>;
    onSubmit: () => void;
}) {
    const employeeLocked = !canApprove && linkedEmployeeId !== null;
    const employeeUnavailable = !canApprove && linkedEmployeeId === null;
    const attachmentId = useId();
    const existingAttachment = leaveRequest?.attachments[0] ?? null;
    const pendingAttachment = form.data.attachment;
    const showExistingAttachment = existingAttachment && !form.data.remove_attachment && !pendingAttachment;
    const displayName = pendingAttachment?.name ?? (showExistingAttachment ? existingAttachment.name : null);

    const clearAttachment = () => {
        if (pendingAttachment) {
            form.setData((data) => ({
                ...data,
                attachment: null,
                remove_attachment: false,
            }));

            return;
        }

        form.setData((data) => ({
            ...data,
            attachment: null,
            remove_attachment: true,
        }));
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="w-full sm:max-w-md p-0 flex flex-col glass-card rounded-none">
                <SheetHeader className="p-8 pb-6 border-b border-border/60">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {leaveRequest ? 'Edit Leave Request' : 'New Leave Request'}
                    </SheetTitle>
                    <SheetDescription className="text-sm text-muted-foreground/80 mt-1">
                        {leaveRequest ? 'Update leave request details.' : 'Submit a new leave request for an employee.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 overflow-y-auto p-8 space-y-8">
                    <div className="space-y-5">
                        <div className="space-y-2">
                            <Label htmlFor="employee_id" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Employee <span className="text-destructive">*</span>
                            </Label>
                            <AppSelect
                                value={String(form.data.employee_id ?? '')}
                                onValueChange={(v) => form.setData('employee_id', v ? Number(v) : '')}
                                variant="card"
                                placeholder={employeeUnavailable ? 'No employee profile linked' : 'Select employee'}
                                disabled={employeeLocked || employeeUnavailable}
                            >
                                {employees.map((employee) => (
                                    <AppSelectItem key={employee.id} value={String(employee.id)}>
                                        {employee.employee_no ? `${employee.employee_no} — ${employee.name}` : employee.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {employeeUnavailable ? (
                                <div className="flex gap-2 rounded-xl border border-amber-500/25 bg-amber-500/10 px-3 py-2.5 text-xs text-amber-800 dark:text-amber-200">
                                    <AlertCircle className="mt-0.5 size-4 shrink-0" />
                                    <div className="space-y-1">
                                        <p className="font-semibold">Your user account is not linked to an employee profile.</p>
                                        <p className="text-amber-900/80 dark:text-amber-100/80">
                                            Ask an administrator to link your user to an employee record before submitting leave requests.
                                        </p>
                                        <Link href="/organization/users" className="inline-flex font-semibold text-primary hover:underline">
                                            Go to users
                                        </Link>
                                    </div>
                                </div>
                            ) : null}
                            {form.errors.employee_id ? <div className="text-xs font-medium text-destructive">{form.errors.employee_id}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="leave_type_id" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Leave type <span className="text-destructive">*</span>
                            </Label>
                            <AppSelect
                                value={String(form.data.leave_type_id ?? '')}
                                onValueChange={(v) => form.setData('leave_type_id', v ? Number(v) : '')}
                                variant="card"
                                placeholder="Select leave type"
                            >
                                {leaveTypes.map((leaveType) => (
                                    <AppSelectItem key={leaveType.id} value={String(leaveType.id)}>
                                        {leaveType.code} — {leaveType.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {form.errors.leave_type_id ? <div className="text-xs font-medium text-destructive">{form.errors.leave_type_id}</div> : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="start_date" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Start date <span className="text-destructive">*</span>
                                </Label>
                                <Input
                                    id="start_date"
                                    type="date"
                                    className={inputClass}
                                    value={form.data.start_date}
                                    onChange={(e) => form.setData('start_date', e.target.value)}
                                />
                                {form.errors.start_date ? <div className="text-xs font-medium text-destructive">{form.errors.start_date}</div> : null}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="end_date" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    End date <span className="text-destructive">*</span>
                                </Label>
                                <Input
                                    id="end_date"
                                    type="date"
                                    className={inputClass}
                                    value={form.data.end_date}
                                    onChange={(e) => form.setData('end_date', e.target.value)}
                                />
                                {form.errors.end_date ? <div className="text-xs font-medium text-destructive">{form.errors.end_date}</div> : null}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="reason" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Reason (optional)
                            </Label>
                            <Textarea
                                id="reason"
                                value={form.data.reason}
                                onChange={(e) => form.setData('reason', e.target.value)}
                                className="min-h-24 rounded-xl border-border bg-card"
                                placeholder="Reason for leave..."
                            />
                            {form.errors.reason ? <div className="text-xs font-medium text-destructive">{form.errors.reason}</div> : null}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor={attachmentId} className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Attachment (optional)
                            </Label>
                            <Input
                                id={attachmentId}
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,application/pdf,image/jpeg,image/png"
                                className="sr-only"
                                onChange={(e) => {
                                    const file = e.currentTarget.files?.[0] ?? null;

                                    if (file) {
                                        form.setData((data) => ({
                                            ...data,
                                            attachment: file,
                                            remove_attachment: false,
                                        }));
                                    }

                                    e.currentTarget.value = '';
                                }}
                            />
                            <div className="rounded-2xl border border-border/80 bg-card/50 p-4">
                                <div className="flex gap-4">
                                    <div className="relative flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-border/80 bg-muted/30">
                                        <FileText className="size-9 text-muted-foreground/50" />
                                    </div>

                                    <div className="flex min-w-0 flex-1 flex-col justify-center gap-3">
                                        {displayName ? (
                                            <div className="min-w-0">
                                                {showExistingAttachment ? (
                                                    <a
                                                        href={existingAttachment.url}
                                                        className="block truncate text-sm font-medium text-primary hover:underline"
                                                    >
                                                        {displayName}
                                                    </a>
                                                ) : (
                                                    <p className="truncate text-sm font-medium">{displayName}</p>
                                                )}
                                                {pendingAttachment ? (
                                                    <p className="text-xs text-muted-foreground">
                                                        {formatUploadFileSize(pendingAttachment.size)}
                                                    </p>
                                                ) : null}
                                            </div>
                                        ) : (
                                            <p className="text-xs leading-relaxed text-muted-foreground">
                                                Supported formats: PDF, JPG, PNG, DOC, DOCX. Max 10 MB.
                                            </p>
                                        )}

                                        <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                                            <Button
                                                asChild
                                                type="button"
                                                variant="secondary"
                                                size="sm"
                                                className="h-9 rounded-xl px-3"
                                            >
                                                <label htmlFor={attachmentId} className="cursor-pointer">
                                                    <Upload className="size-3.5" />
                                                    {displayName ? 'Replace file' : 'Upload file'}
                                                </label>
                                            </Button>
                                            {displayName ? (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    className="h-9 rounded-xl px-3"
                                                    onClick={clearAttachment}
                                                >
                                                    <Trash2 className="size-3.5" />
                                                    Remove
                                                </Button>
                                            ) : null}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            {form.errors.attachment ? <div className="text-xs font-medium text-destructive">{form.errors.attachment}</div> : null}
                        </div>
                    </div>
                </div>

                <div className="p-6 border-t border-border/60 bg-background/40 flex gap-3">
                    <Button
                        type="button"
                        variant="ghost"
                        className="rounded-xl h-11 px-6 text-muted-foreground flex-1"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        className="rounded-xl h-11 px-6 flex-1 font-semibold"
                        type="button"
                        onClick={onSubmit}
                        disabled={form.processing || employeeUnavailable}
                    >
                        {leaveRequest ? 'Save' : 'Create'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
