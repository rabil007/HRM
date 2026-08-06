import type { InertiaFormProps } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { tourSourceLabel } from '@/features/organization/crew/lib/tour-of-duty';
import type {
    CrewRankPolicyFormData,
    CrewRankPolicyItem,
} from '@/features/organization/crew-operations/rank-policies/types';

export function RankPolicyFormSheet({
    open,
    onOpenChange,
    policy,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    policy: CrewRankPolicyItem | null;
    form: InertiaFormProps<CrewRankPolicyFormData>;
    onSubmit: () => void;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        Company Tour of Duty
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        {policy
                            ? `Override the global default for ${policy.rank_name}.`
                            : 'Set a company override for this rank.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-6 overflow-y-auto p-8">
                    {policy ? (
                        <div className="space-y-3 rounded-xl border border-border/70 bg-muted/20 p-4 text-sm">
                            <div>
                                <span className="text-muted-foreground">
                                    Rank:{' '}
                                </span>
                                <span className="font-medium">
                                    {policy.rank_name}
                                </span>
                            </div>
                            <div>
                                <span className="text-muted-foreground">
                                    Global default:{' '}
                                </span>
                                <span className="font-medium">
                                    {policy.global_tour_of_duty_days != null
                                        ? `${policy.global_tour_of_duty_days} days`
                                        : 'Not set'}
                                </span>
                            </div>
                            <div>
                                <span className="text-muted-foreground">
                                    Current company override:{' '}
                                </span>
                                <span className="font-medium">
                                    {policy.company_tour_of_duty_days != null
                                        ? `${policy.company_tour_of_duty_days} days`
                                        : 'None'}
                                </span>
                            </div>
                            <div>
                                <span className="text-muted-foreground">
                                    Resolved source:{' '}
                                </span>
                                <span className="font-medium">
                                    {tourSourceLabel(
                                        policy.resolved_tour_of_duty_source,
                                    )}
                                </span>
                            </div>
                        </div>
                    ) : null}

                    <div className="space-y-2">
                        <Label
                            htmlFor="rank-policy-days"
                            className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                        >
                            Company Tour of Duty (days)
                        </Label>
                        <Input
                            id="rank-policy-days"
                            type="number"
                            min={1}
                            max={365}
                            className="h-11 rounded-xl border-border bg-card"
                            value={form.data.tour_of_duty_days}
                            onChange={(event) => {
                                const value = event.target.value;

                                form.setData(
                                    'tour_of_duty_days',
                                    value === '' ? '' : Number(value),
                                );
                            }}
                        />
                        <p className="text-xs text-muted-foreground">
                            Between 1 and 365 days. Applies to new joins when no
                            assignment override is set.
                        </p>
                        <InputError message={form.errors.tour_of_duty_days} />
                        <InputError message={form.errors.rank_id} />
                    </div>
                </div>

                <SheetFooter className="border-t border-border/60 p-8 pt-6">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={form.processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={onSubmit}
                        disabled={form.processing}
                    >
                        {form.processing ? <Spinner className="mr-2" /> : null}
                        Save override
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
