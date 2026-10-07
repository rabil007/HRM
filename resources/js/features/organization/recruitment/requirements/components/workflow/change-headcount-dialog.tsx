import { useForm } from '@inertiajs/react';
import { Loader2, Users } from 'lucide-react';
import { useEffect, useMemo } from 'react';
import RequirementChangeHeadcountController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementChangeHeadcountController';
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
    HeadcountRevisionMode,
    RequirementDetail,
    RequirementIndexRow,
} from '@/types/recruitment';

type LineItem = {
    id: number;
    position_title: string;
    required_headcount: number;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    requirement: RequirementIndexRow | RequirementDetail | null;
    lines?: LineItem[];
    onSuccess?: () => void;
};

type HeadcountForm = {
    lines: Array<{ id: number; required_headcount: string }>;
    reason: string;
};

function revisionMode(
    requirement: RequirementIndexRow | RequirementDetail,
): HeadcountRevisionMode {
    return requirement.headcount_revision_mode ?? 'direct';
}

export function ChangeHeadcountDialog({
    open,
    onOpenChange,
    requirement,
    lines,
    onSuccess,
}: Props) {
    const availableLines: LineItem[] = useMemo(() => {
        if (lines && lines.length > 0) {
            return lines;
        }

        if (!requirement) {
            return [];
        }

        if ('lines' in requirement && requirement.lines) {
            return requirement.lines.map((line) => ({
                id: line.id,
                position_title: line.position_title,
                required_headcount: line.required_headcount,
            }));
        }

        if (requirement.positions_summary) {
            return requirement.positions_summary.map((position) => ({
                id: position.id,
                position_title: position.position_title,
                required_headcount: position.required_headcount,
            }));
        }

        return [];
    }, [lines, requirement]);

    const mode = requirement ? revisionMode(requirement) : 'direct';
    const noteOptional = mode === 'requester';

    const {
        data,
        setData,
        post,
        processing,
        errors,
        reset,
        clearErrors,
        transform,
    } = useForm<HeadcountForm>({
        lines: [],
        reason: '',
    });
    const formErrors = errors as Record<string, string | undefined>;

    useEffect(() => {
        if (!open) {
            return;
        }

        clearErrors();
        setData({
            lines: availableLines.map((line) => ({
                id: line.id,
                required_headcount: String(line.required_headcount),
            })),
            reason: '',
        });
    }, [open, availableLines, clearErrors, setData]);

    if (!requirement) {
        return null;
    }

    const title =
        mode === 'recruiter'
            ? 'Request Headcount Revision'
            : 'Revise Headcount';
    const submitLabel =
        mode === 'recruiter'
            ? 'Request Revision'
            : mode === 'requester'
              ? 'Submit Revision'
              : 'Update Headcount';
    const noteLabel = noteOptional ? 'Note' : 'Reason';

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        transform((payload) => ({
            lines: payload.lines.map((line) => ({
                id: line.id,
                required_headcount: Number(line.required_headcount),
            })),
            reason: payload.reason,
        }));
        post(RequirementChangeHeadcountController.url(requirement.id), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(
                    mode === 'direct'
                        ? 'Headcount updated successfully.'
                        : 'Headcount revision submitted for approval.',
                );
                onOpenChange(false);
                reset();
                onSuccess?.();
            },
            onError: () => {
                toast.error(
                    'Failed to change headcount. Please review input fields.',
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
                                <Users className="h-5 w-5" />
                            </div>
                            <div>
                                <DialogTitle className="text-lg font-bold">
                                    {title}
                                </DialogTitle>
                                <DialogDescription className="text-xs text-muted-foreground">
                                    {requirement.requirement_number} —{' '}
                                    {requirement.client_name}
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    <div className="my-5 space-y-4">
                        {mode === 'requester' ? (
                            <p className="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-300">
                                The official headcount will remain unchanged
                                until the assigned recruiter approves this
                                revision.
                            </p>
                        ) : null}

                        <div className="space-y-3">
                            {availableLines.map((line, index) => (
                                <div
                                    key={line.id}
                                    className="rounded-lg border border-border/70 bg-muted/30 p-3"
                                >
                                    <p className="text-sm font-semibold">
                                        {line.position_title}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Current: {line.required_headcount}
                                    </p>
                                    <div className="mt-2 space-y-1">
                                        <Label
                                            htmlFor={`proposed-headcount-${line.id}`}
                                            className="text-xs font-semibold"
                                        >
                                            Proposed
                                        </Label>
                                        <Input
                                            id={`proposed-headcount-${line.id}`}
                                            type="number"
                                            min={1}
                                            value={
                                                data.lines[index]
                                                    ?.required_headcount ??
                                                String(line.required_headcount)
                                            }
                                            onChange={(event) => {
                                                const next = [...data.lines];
                                                next[index] = {
                                                    id: line.id,
                                                    required_headcount:
                                                        event.target.value,
                                                };
                                                setData('lines', next);
                                            }}
                                            required
                                        />
                                        {formErrors[
                                            `lines.${index}.required_headcount`
                                        ] ? (
                                            <p className="text-xs text-rose-500">
                                                {
                                                    formErrors[
                                                        `lines.${index}.required_headcount`
                                                    ]
                                                }
                                            </p>
                                        ) : null}
                                    </div>
                                </div>
                            ))}
                        </div>
                        {formErrors.lines ? (
                            <p className="text-xs text-rose-500">
                                {formErrors.lines}
                            </p>
                        ) : null}
                        {formErrors.status ? (
                            <p className="text-xs text-rose-500">
                                {formErrors.status}
                            </p>
                        ) : null}

                        <div className="space-y-2">
                            <Label
                                htmlFor="headcount-revision-note"
                                className="text-xs font-semibold"
                            >
                                {noteLabel}{' '}
                                {noteOptional ? (
                                    <span className="font-normal text-muted-foreground">
                                        (optional)
                                    </span>
                                ) : (
                                    <span className="text-rose-500">*</span>
                                )}
                            </Label>
                            <Textarea
                                id="headcount-revision-note"
                                rows={3}
                                placeholder={
                                    noteOptional
                                        ? 'Add a note for the assigned recruiter...'
                                        : 'Explain why the headcount should change...'
                                }
                                value={data.reason}
                                onChange={(event) =>
                                    setData('reason', event.target.value)
                                }
                                required={!noteOptional}
                            />
                            {formErrors.reason ? (
                                <p className="text-xs text-rose-500">
                                    {formErrors.reason}
                                </p>
                            ) : null}
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
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
