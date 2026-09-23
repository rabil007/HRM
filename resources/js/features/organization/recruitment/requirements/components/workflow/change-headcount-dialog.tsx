import { useForm } from '@inertiajs/react';
import { Loader2, Users } from 'lucide-react';
import { useEffect, useMemo } from 'react';
import RequirementChangeHeadcountController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementChangeHeadcountController';
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

export function ChangeHeadcountDialog({
    open,
    onOpenChange,
    requirement,
    lines = [],
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
            return requirement.lines.map((l) => ({
                id: l.id,
                position_title: l.position_title,
                required_headcount: l.required_headcount,
            }));
        }

        if (requirement.positions_summary) {
            return requirement.positions_summary.map((p) => ({
                id: p.id,
                position_title: p.position_title,
                required_headcount: p.required_headcount,
            }));
        }

        return [];
    }, [lines, requirement]);

    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm({
            requirement_line_id: availableLines[0]?.id
                ? String(availableLines[0].id)
                : '',
            new_headcount: availableLines[0]?.required_headcount
                ? String(availableLines[0].required_headcount)
                : '1',
            reason: '',
        });

    useEffect(() => {
        if (open && availableLines.length > 0) {
            clearErrors();
            const first = availableLines[0];
            setData({
                requirement_line_id: String(first.id),
                new_headcount: String(first.required_headcount),
                reason: '',
            });
        }
    }, [open, availableLines, clearErrors, setData]);

    if (!requirement) {
        return null;
    }

    const selectedLine = availableLines.find(
        (l) => String(l.id) === String(data.requirement_line_id),
    );

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(RequirementChangeHeadcountController.url(requirement.id), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Headcount revised successfully.');
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
            <DialogContent className="max-w-md p-6">
                <form onSubmit={handleSubmit}>
                    <DialogHeader className="space-y-2">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                <Users className="h-5 w-5" />
                            </div>
                            <div>
                                <DialogTitle className="text-lg font-bold">
                                    Revise Headcount
                                </DialogTitle>
                                <DialogDescription className="text-xs text-muted-foreground">
                                    {requirement.requirement_number} —{' '}
                                    {requirement.client_name}
                                </DialogDescription>
                            </div>
                        </div>
                    </DialogHeader>

                    <div className="my-5 space-y-4">
                        {availableLines.length > 1 && (
                            <div className="space-y-2">
                                <Label className="text-xs font-semibold">
                                    Select Position
                                </Label>
                                <AppSelect
                                    value={data.requirement_line_id}
                                    onValueChange={(val) => {
                                        const target = availableLines.find(
                                            (l) => String(l.id) === val,
                                        );
                                        setData((prev) => ({
                                            ...prev,
                                            requirement_line_id: val,
                                            new_headcount: target
                                                ? String(
                                                      target.required_headcount,
                                                  )
                                                : prev.new_headcount,
                                        }));
                                    }}
                                >
                                    {availableLines.map((line) => (
                                        <AppSelectItem
                                            key={line.id}
                                            value={String(line.id)}
                                        >
                                            {line.position_title} (Current:{' '}
                                            {line.required_headcount})
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                            </div>
                        )}

                        {selectedLine && (
                            <div className="flex items-center justify-between rounded-lg border border-border/70 bg-muted/30 p-3 text-xs">
                                <span className="text-muted-foreground">
                                    Position:{' '}
                                    <strong className="text-foreground">
                                        {selectedLine.position_title}
                                    </strong>
                                </span>
                                <span>
                                    Current target:{' '}
                                    <strong className="font-semibold text-primary">
                                        {selectedLine.required_headcount}
                                    </strong>
                                </span>
                            </div>
                        )}

                        <div className="space-y-2">
                            <Label
                                htmlFor="new_headcount"
                                className="text-xs font-semibold"
                            >
                                New Headcount Target{' '}
                                <span className="text-rose-500">*</span>
                            </Label>
                            <Input
                                id="new_headcount"
                                type="number"
                                min={1}
                                max={500}
                                value={data.new_headcount}
                                onChange={(e) =>
                                    setData('new_headcount', e.target.value)
                                }
                                required
                            />
                            {errors.new_headcount && (
                                <p className="text-xs text-rose-500">
                                    {errors.new_headcount}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="reason"
                                className="text-xs font-semibold"
                            >
                                Reason for Headcount Change{' '}
                                <span className="text-rose-500">*</span>
                            </Label>
                            <Textarea
                                id="reason"
                                rows={3}
                                placeholder="Explain why the headcount target was revised..."
                                value={data.reason}
                                onChange={(e) =>
                                    setData('reason', e.target.value)
                                }
                                required
                            />
                            {errors.reason && (
                                <p className="text-xs text-rose-500">
                                    {errors.reason}
                                </p>
                            )}
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
                            Update Headcount
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
