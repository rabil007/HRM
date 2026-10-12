import type { InertiaFormProps } from '@inertiajs/react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { RequiredIndicator } from '@/pages/organization/_components/record-form-field';

export type CandidateReadinessFieldsData = {
    expected_joining_date: string;
    joining_readiness_status: 'pending' | 'ready';
    joining_readiness_notes: string;
    joining_blocker_notes: string;
    reason?: string;
    lock_version: number;
};

type ReadinessFieldsForm = Pick<
    InertiaFormProps<CandidateReadinessFieldsData>,
    'data' | 'errors' | 'setData'
>;

export function CandidateReadinessFields({
    form,
    idPrefix = 'readiness',
    disabled = false,
    twoColumn = false,
}: {
    form: ReadinessFieldsForm;
    idPrefix?: string;
    disabled?: boolean;
    twoColumn?: boolean;
}) {
    return (
        <div className={twoColumn ? 'grid gap-4 md:grid-cols-2' : 'space-y-3'}>
            <div className="space-y-1.5">
                <Label htmlFor={`${idPrefix}-expected-joining-date`}>
                    Expected joining date
                </Label>
                <Input
                    id={`${idPrefix}-expected-joining-date`}
                    type="date"
                    disabled={disabled}
                    value={form.data.expected_joining_date}
                    onChange={(event) =>
                        form.setData(
                            'expected_joining_date',
                            event.target.value,
                        )
                    }
                />
                <InputError message={form.errors.expected_joining_date} />
            </div>

            <div className="space-y-1.5">
                <Label htmlFor={`${idPrefix}-status`}>
                    Readiness status
                    <RequiredIndicator show />
                </Label>
                <AppSelect
                    value={form.data.joining_readiness_status}
                    disabled={disabled}
                    onValueChange={(value) =>
                        form.setData(
                            'joining_readiness_status',
                            value as 'pending' | 'ready',
                        )
                    }
                >
                    <AppSelectItem value="pending">Pending</AppSelectItem>
                    <AppSelectItem value="ready">Ready</AppSelectItem>
                </AppSelect>
                <InputError message={form.errors.joining_readiness_status} />
            </div>

            <div className="space-y-1.5 md:col-span-2">
                <Label htmlFor={`${idPrefix}-notes`}>Readiness notes</Label>
                <Textarea
                    id={`${idPrefix}-notes`}
                    rows={2}
                    disabled={disabled}
                    placeholder="e.g. Visa processed, flight booked, accommodation assigned."
                    value={form.data.joining_readiness_notes}
                    onChange={(event) =>
                        form.setData(
                            'joining_readiness_notes',
                            event.target.value,
                        )
                    }
                />
                <InputError message={form.errors.joining_readiness_notes} />
            </div>

            <div className="space-y-1.5 md:col-span-2">
                <Label htmlFor={`${idPrefix}-blockers`}>Blocker notes</Label>
                <Textarea
                    id={`${idPrefix}-blockers`}
                    rows={2}
                    disabled={disabled}
                    placeholder="e.g. Medical clearance pending, missing original passport."
                    value={form.data.joining_blocker_notes}
                    onChange={(event) =>
                        form.setData(
                            'joining_blocker_notes',
                            event.target.value,
                        )
                    }
                />
                <InputError message={form.errors.joining_blocker_notes} />
            </div>
        </div>
    );
}
