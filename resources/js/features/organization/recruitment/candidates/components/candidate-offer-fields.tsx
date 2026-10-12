import type { InertiaFormProps } from '@inertiajs/react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { RequiredIndicator } from '@/pages/organization/_components/record-form-field';
import type { CandidateFormOptions } from '../types';

export type CandidateOfferFieldsData = {
    salary_amount: string;
    salary_currency_code: string;
    proposed_joining_date: string;
    offer_date: string;
    expiry_date: string;
    notes: string;
    offer_document: File | null;
};

type OfferFieldsForm = Pick<
    InertiaFormProps<CandidateOfferFieldsData>,
    'data' | 'errors' | 'setData'
>;

export function CandidateOfferFields({
    form,
    options,
    idPrefix = 'offer',
    salaryReference = null,
}: {
    form: OfferFieldsForm;
    options: CandidateFormOptions;
    idPrefix?: string;
    salaryReference?: string | null;
}) {
    return (
        <div className="space-y-4">
            {salaryReference ? (
                <p className="rounded-lg border border-border/60 bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
                    Position salary range (reference only): {salaryReference}
                </p>
            ) : null}

            <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-2">
                    <Label htmlFor={`${idPrefix}-salary-amount`}>
                        Offered salary amount
                        <RequiredIndicator show />
                    </Label>
                    <Input
                        id={`${idPrefix}-salary-amount`}
                        type="number"
                        step="0.01"
                        min="0"
                        required
                        aria-required
                        aria-invalid={Boolean(form.errors.salary_amount)}
                        value={form.data.salary_amount}
                        onChange={(event) =>
                            form.setData('salary_amount', event.target.value)
                        }
                    />
                    <InputError message={form.errors.salary_amount} />
                </div>
                <div className="space-y-2">
                    <Label>
                        Salary currency
                        <RequiredIndicator show />
                    </Label>
                    <AppSelect
                        value={form.data.salary_currency_code || 'none'}
                        invalid={Boolean(form.errors.salary_currency_code)}
                        onValueChange={(value) =>
                            form.setData(
                                'salary_currency_code',
                                value === 'none' ? '' : value,
                            )
                        }
                    >
                        <AppSelectItem value="none">
                            Select currency
                        </AppSelectItem>
                        {options.currencies.map((currency) => (
                            <AppSelectItem
                                key={currency.code}
                                value={currency.code}
                            >
                                {currency.code}
                                {currency.name ? ` · ${currency.name}` : ''}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                    <InputError message={form.errors.salary_currency_code} />
                </div>
                <div className="space-y-2">
                    <Label htmlFor={`${idPrefix}-offer-date`}>
                        Offer date
                        <RequiredIndicator show />
                    </Label>
                    <p className="text-xs text-muted-foreground">
                        Date recorded on the offer document.
                    </p>
                    <Input
                        id={`${idPrefix}-offer-date`}
                        type="date"
                        required
                        aria-required
                        aria-invalid={Boolean(form.errors.offer_date)}
                        value={form.data.offer_date}
                        onChange={(event) =>
                            form.setData('offer_date', event.target.value)
                        }
                    />
                    <InputError message={form.errors.offer_date} />
                </div>
                <div className="space-y-2">
                    <Label htmlFor={`${idPrefix}-joining-date`}>
                        Proposed joining date
                        <RequiredIndicator show />
                    </Label>
                    <p className="text-xs text-muted-foreground">
                        Planned joining date, not the confirmed actual joining
                        date.
                    </p>
                    <Input
                        id={`${idPrefix}-joining-date`}
                        type="date"
                        required
                        aria-required
                        aria-invalid={Boolean(
                            form.errors.proposed_joining_date,
                        )}
                        value={form.data.proposed_joining_date}
                        onChange={(event) =>
                            form.setData(
                                'proposed_joining_date',
                                event.target.value,
                            )
                        }
                    />
                    <InputError message={form.errors.proposed_joining_date} />
                </div>
                <div className="space-y-2">
                    <Label htmlFor={`${idPrefix}-expiry-date`}>
                        Expiry date (optional)
                    </Label>
                    <Input
                        id={`${idPrefix}-expiry-date`}
                        type="date"
                        value={form.data.expiry_date}
                        onChange={(event) =>
                            form.setData('expiry_date', event.target.value)
                        }
                    />
                    <InputError message={form.errors.expiry_date} />
                </div>
                <div className="space-y-2">
                    <Label htmlFor={`${idPrefix}-document`}>
                        Offer / JOL document (optional)
                    </Label>
                    <Input
                        id={`${idPrefix}-document`}
                        type="file"
                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp"
                        onChange={(event) =>
                            form.setData(
                                'offer_document',
                                event.target.files?.[0] ?? null,
                            )
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        PDF, Word, or image. Follow company storage limits.
                    </p>
                    <InputError message={form.errors.offer_document} />
                </div>
                <div className="space-y-2 md:col-span-2">
                    <Label htmlFor={`${idPrefix}-notes`}>
                        Notes (optional)
                    </Label>
                    <Textarea
                        id={`${idPrefix}-notes`}
                        value={form.data.notes}
                        onChange={(event) =>
                            form.setData('notes', event.target.value)
                        }
                    />
                    <InputError message={form.errors.notes} />
                </div>
            </div>
        </div>
    );
}
