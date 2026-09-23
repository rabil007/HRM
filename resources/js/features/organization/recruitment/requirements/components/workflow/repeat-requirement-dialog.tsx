import { useForm } from '@inertiajs/react';
import { Copy, Loader2 } from 'lucide-react';
import { useEffect } from 'react';
import RequirementRepeatController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementRepeatController';
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
import { toast } from '@/lib/toast';
import type {
    RequirementDetail,
    RequirementIndexRow,
    UserOption,
} from '@/types/recruitment';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    requirement: RequirementIndexRow | RequirementDetail | null;
    recruiters: UserOption[];
    onSuccess?: () => void;
};

export function RepeatRequirementDialog({
    open,
    onOpenChange,
    requirement,
    recruiters,
    onSuccess,
}: Props) {
    const today = new Date().toISOString().split('T')[0];

    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm({
            request_received_date: today,
            required_by_date: '',
            client_reference_number: '',
            assigned_to: '',
            priority: 'normal',
            reason: '',
        });

    useEffect(() => {
        if (open && requirement) {
            clearErrors();
            setData({
                request_received_date: today,
                required_by_date: '',
                client_reference_number:
                    requirement.client_reference_number || '',
                assigned_to: requirement.assigned_to
                    ? String(requirement.assigned_to)
                    : '',
                priority: requirement.priority || 'normal',
                reason: '',
            });
        }
    }, [open, requirement, today, clearErrors, setData]);

    if (!requirement) {
        return null;
    }

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(RequirementRepeatController.url(requirement.id), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Repeat requirement created as Draft.');
                onOpenChange(false);
                reset();
                onSuccess?.();
            },
            onError: () => {
                toast.error(
                    'Failed to create repeated requirement. Please check input fields.',
                );
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg p-6">
                <form onSubmit={handleSubmit}>
                    <DialogHeader className="space-y-2">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                <Copy className="h-5 w-5" />
                            </div>
                            <div>
                                <DialogTitle className="text-lg font-bold">
                                    Repeat Requirement
                                </DialogTitle>
                                <DialogDescription className="text-xs text-muted-foreground">
                                    Clone positions and client details from{' '}
                                    {requirement.requirement_number} into a new
                                    requisition.
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    <div className="my-5 space-y-4">
                        <div className="space-y-1 rounded-lg border border-border/70 bg-muted/30 p-3 text-xs">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    Client:
                                </span>
                                <span className="font-semibold text-foreground">
                                    {requirement.client_name}
                                </span>
                            </div>
                            {requirement.project_title && (
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Project:
                                    </span>
                                    <span className="font-semibold text-foreground">
                                        {requirement.project_title}
                                    </span>
                                </div>
                            )}
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    Positions:
                                </span>
                                <span className="font-semibold text-foreground">
                                    {requirement.positions_count} position
                                    line(s) ({requirement.total_headcount}{' '}
                                    headcount)
                                </span>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="repeat_request_received_date"
                                    className="text-xs font-semibold"
                                >
                                    Request Received Date{' '}
                                    <span className="text-rose-500">*</span>
                                </Label>
                                <Input
                                    id="repeat_request_received_date"
                                    type="date"
                                    value={data.request_received_date}
                                    onChange={(e) =>
                                        setData(
                                            'request_received_date',
                                            e.target.value,
                                        )
                                    }
                                    required
                                />
                                {errors.request_received_date && (
                                    <p className="text-xs text-rose-500">
                                        {errors.request_received_date}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="repeat_required_by_date"
                                    className="text-xs font-semibold"
                                >
                                    Required-By Target Date{' '}
                                    <span className="text-rose-500">*</span>
                                </Label>
                                <Input
                                    id="repeat_required_by_date"
                                    type="date"
                                    value={data.required_by_date}
                                    onChange={(e) =>
                                        setData(
                                            'required_by_date',
                                            e.target.value,
                                        )
                                    }
                                    required
                                />
                                {errors.required_by_date && (
                                    <p className="text-xs text-rose-500">
                                        {errors.required_by_date}
                                    </p>
                                )}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="repeat_client_reference_number"
                                    className="text-xs font-semibold"
                                >
                                    Client Reference #
                                </Label>
                                <Input
                                    id="repeat_client_reference_number"
                                    placeholder="e.g. PO-8921"
                                    value={data.client_reference_number}
                                    onChange={(e) =>
                                        setData(
                                            'client_reference_number',
                                            e.target.value,
                                        )
                                    }
                                />
                                {errors.client_reference_number && (
                                    <p className="text-xs text-rose-500">
                                        {errors.client_reference_number}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-xs font-semibold">
                                    Priority
                                </Label>
                                <AppSelect
                                    value={data.priority}
                                    onValueChange={(val) =>
                                        setData(
                                            'priority',
                                            val as 'normal' | 'urgent',
                                        )
                                    }
                                >
                                    <AppSelectItem value="normal">
                                        Normal
                                    </AppSelectItem>
                                    <AppSelectItem value="urgent">
                                        Urgent
                                    </AppSelectItem>
                                </AppSelect>
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label className="text-xs font-semibold">
                                Assigned Recruiter
                            </Label>
                            <AppSelect
                                value={data.assigned_to || 'unassigned'}
                                onValueChange={(val) =>
                                    setData(
                                        'assigned_to',
                                        val === 'unassigned' ? '' : val,
                                    )
                                }
                            >
                                <AppSelectItem value="unassigned">
                                    Unassigned
                                </AppSelectItem>
                                {recruiters.map((r) => (
                                    <AppSelectItem
                                        key={r.id}
                                        value={String(r.id)}
                                    >
                                        {r.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {errors.assigned_to && (
                                <p className="text-xs text-rose-500">
                                    {errors.assigned_to}
                                </p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <Label
                                htmlFor="repeat_reason"
                                className="text-xs font-semibold"
                            >
                                Reason / Additional Notes
                            </Label>
                            <Textarea
                                id="repeat_reason"
                                rows={2}
                                placeholder="Optional context for repeating this requirement..."
                                value={data.reason}
                                onChange={(e) =>
                                    setData('reason', e.target.value)
                                }
                            />
                        </div>
                    </div>

                    <DialogFooter className="flex flex-row justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="gap-1.5"
                        >
                            {processing && (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            )}
                            Create Repeated Requisition
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
