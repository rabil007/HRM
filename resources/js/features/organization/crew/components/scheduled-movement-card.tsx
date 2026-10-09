import { router, useForm } from '@inertiajs/react';
import { CalendarClock, Pencil, X } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { CrewScheduledMovementCard } from '@/features/organization/crew/types';
import { cn } from '@/lib/utils';
import {
    cancel as cancelScheduledMovement,
    update as updateScheduledMovement,
} from '@/routes/organization/crew-scheduled-movements';

function statusClass(status: string): string {
    switch (status) {
        case 'needs_attention':
            return 'border-amber-500/40 bg-amber-500/10 text-amber-900 dark:text-amber-100';
        case 'scheduled':
            return 'border-sky-500/30 bg-sky-500/10 text-sky-900 dark:text-sky-100';
        case 'executed':
            return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-900 dark:text-emerald-100';
        case 'cancelled':
            return 'border-border/60 bg-muted/40 text-muted-foreground';
        case 'processing':
            return 'border-violet-500/30 bg-violet-500/10 text-violet-900 dark:text-violet-100';
        default:
            return 'border-border/60 bg-muted/30';
    }
}

export function ScheduledMovementCard({
    schedule,
    className,
}: {
    schedule: CrewScheduledMovementCard;
    className?: string;
}): ReactElement {
    const [editing, setEditing] = useState(false);
    const form = useForm({
        scheduled_at: schedule.scheduled_at ?? '',
        remarks: '',
        reason: '',
    });

    const save = (): void => {
        form.put(updateScheduledMovement.url(schedule.id), {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    const cancel = (): void => {
        if (
            !confirm(
                'Cancel this scheduled movement? It will remain in history and will not execute.',
            )
        ) {
            return;
        }

        router.post(
            cancelScheduledMovement.url(schedule.id),
            { reason: form.data.reason || undefined },
            { preserveScroll: true },
        );
    };

    return (
        <Card
            className={cn('border-border/80 dark:border-white/10', className)}
        >
            <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-base">
                    <CalendarClock className="size-4" />
                    Scheduled Movement
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
                <div
                    className={cn(
                        'inline-flex rounded-md border px-2 py-1 text-xs font-medium',
                        statusClass(schedule.status),
                    )}
                >
                    {schedule.status_label}
                </div>

                <div className="space-y-1">
                    <div>
                        <span className="text-muted-foreground">Action: </span>
                        <span className="font-medium">
                            {schedule.movement_action_label}
                        </span>
                    </div>
                    <div>
                        <span className="text-muted-foreground">When: </span>
                        <span className="font-medium">
                            {schedule.scheduled_at_display ??
                                schedule.scheduled_at ??
                                '—'}
                        </span>
                    </div>
                    <div>
                        <span className="text-muted-foreground">
                            Expected result:{' '}
                        </span>
                        <span className="font-medium">
                            {schedule.expected_result_phase_label}
                        </span>
                    </div>
                    {schedule.updated_by ? (
                        <div>
                            <span className="text-muted-foreground">
                                Last updated by:{' '}
                            </span>
                            <span className="font-medium">
                                {schedule.updated_by.name}
                            </span>
                        </div>
                    ) : null}
                </div>

                {schedule.status === 'needs_attention' &&
                schedule.last_error_message ? (
                    <div className="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs">
                        <p className="font-medium">Needs Attention</p>
                        <p className="mt-1 text-muted-foreground">
                            {schedule.last_error_message}
                        </p>
                    </div>
                ) : null}

                {editing ? (
                    <div className="space-y-3 rounded-lg border border-border/60 p-3">
                        <div className="space-y-2">
                            <Label htmlFor={`reschedule-${schedule.id}`}>
                                New scheduled date and time
                            </Label>
                            <Input
                                id={`reschedule-${schedule.id}`}
                                type="datetime-local"
                                value={form.data.scheduled_at}
                                onChange={(event) =>
                                    form.setData(
                                        'scheduled_at',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.scheduled_at} />
                            <InputError
                                message={
                                    'error' in form.errors
                                        ? String(form.errors.error ?? '')
                                        : undefined
                                }
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setEditing(false)}
                                disabled={form.processing}
                            >
                                Close
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                onClick={save}
                                disabled={form.processing}
                            >
                                Save Reschedule
                            </Button>
                        </div>
                    </div>
                ) : null}

                {(schedule.can_edit || schedule.can_cancel) && !editing ? (
                    <div className="flex flex-wrap gap-2">
                        {schedule.can_edit ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setEditing(true)}
                            >
                                <Pencil className="mr-1 size-3.5" />
                                Edit / Reschedule
                            </Button>
                        ) : null}
                        {schedule.can_cancel ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={cancel}
                            >
                                <X className="mr-1 size-3.5" />
                                Cancel
                            </Button>
                        ) : null}
                    </div>
                ) : null}

                {schedule.can_cancel && editing ? (
                    <div className="space-y-2 border-t border-border/60 pt-3">
                        <Label htmlFor={`cancel-reason-${schedule.id}`}>
                            Cancel reason (optional)
                        </Label>
                        <Textarea
                            id={`cancel-reason-${schedule.id}`}
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            rows={2}
                        />
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}
