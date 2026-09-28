import type { InertiaFormProps } from '@inertiajs/react';
import type { ReactElement } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { inclusivePeriodDays } from '../lib/past-crew-period-days';
import type {
    HistoricalCrewAssignmentFormData,
    HistoricalFormOptions,
} from '../types';

function PeriodSection({
    title,
    fromId,
    toId,
    fromValue,
    toValue,
    fromError,
    toError,
    onFromChange,
    onToChange,
}: {
    title: string;
    fromId: string;
    toId: string;
    fromValue: string;
    toValue: string;
    fromError?: string;
    toError?: string;
    onFromChange: (value: string) => void;
    onToChange: (value: string) => void;
}): ReactElement {
    const days = inclusivePeriodDays(fromValue, toValue);

    return (
        <div className="space-y-3 rounded-xl border border-border/80 bg-muted/20 p-4">
            <div className="text-sm font-semibold text-foreground">{title}</div>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div className="space-y-1.5">
                    <Label htmlFor={fromId}>From</Label>
                    <Input
                        id={fromId}
                        type="date"
                        value={fromValue}
                        onChange={(e) => onFromChange(e.target.value)}
                    />
                    <InputError message={fromError} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor={toId}>To</Label>
                    <Input
                        id={toId}
                        type="date"
                        value={toValue}
                        onChange={(e) => onToChange(e.target.value)}
                    />
                    <InputError message={toError} />
                </div>
                <div className="space-y-1.5">
                    <Label>Days</Label>
                    <Input value={days} readOnly placeholder="—" />
                </div>
            </div>
        </div>
    );
}

export function PastCrewPeriodFields({
    form,
    timezoneLabel,
}: {
    form: InertiaFormProps<HistoricalCrewAssignmentFormData>;
    formOptions?: HistoricalFormOptions;
    timezoneLabel: string;
}): ReactElement {
    return (
        <div className="space-y-4">
            <div className="text-sm font-semibold text-foreground">
                Movement Periods
            </div>
            <p className="text-xs text-muted-foreground">
                Enter known periods only. Leave the current period&apos;s To
                date empty. Days are calculated automatically. Dates use company
                timezone ({timezoneLabel}).
            </p>

            <PeriodSection
                title="Sign-On Standby"
                fromId="past-sign-on-from"
                toId="past-sign-on-to"
                fromValue={form.data.sign_on_standby_from ?? ''}
                toValue={form.data.sign_on_standby_to ?? ''}
                fromError={form.errors.sign_on_standby_from}
                toError={form.errors.sign_on_standby_to}
                onFromChange={(value) =>
                    form.setData('sign_on_standby_from', value)
                }
                onToChange={(value) =>
                    form.setData('sign_on_standby_to', value)
                }
            />

            <PeriodSection
                title="Onsite / On Vessel"
                fromId="past-onsite-from"
                toId="past-onsite-to"
                fromValue={form.data.onsite_from ?? ''}
                toValue={form.data.onsite_to ?? ''}
                fromError={form.errors.onsite_from}
                toError={form.errors.onsite_to}
                onFromChange={(value) => form.setData('onsite_from', value)}
                onToChange={(value) => form.setData('onsite_to', value)}
            />

            <PeriodSection
                title="Sign-Off Standby"
                fromId="past-sign-off-from"
                toId="past-sign-off-to"
                fromValue={form.data.sign_off_standby_from ?? ''}
                toValue={form.data.sign_off_standby_to ?? ''}
                fromError={form.errors.sign_off_standby_from}
                toError={form.errors.sign_off_standby_to}
                onFromChange={(value) =>
                    form.setData('sign_off_standby_from', value)
                }
                onToChange={(value) =>
                    form.setData('sign_off_standby_to', value)
                }
            />

            <div className="space-y-3 rounded-xl border border-border/80 bg-muted/20 p-4">
                <div className="text-sm font-semibold text-foreground">
                    Home Date
                </div>
                <div className="max-w-xs space-y-1.5">
                    <Label htmlFor="past-home-from">Home Date</Label>
                    <Input
                        id="past-home-from"
                        type="date"
                        value={form.data.home_available_from ?? ''}
                        onChange={(e) =>
                            form.setData('home_available_from', e.target.value)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        Enter the date the crew member reached home / became
                        available.
                    </p>
                    <InputError message={form.errors.home_available_from} />
                </div>
            </div>
        </div>
    );
}
