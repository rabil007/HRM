import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { nowInCompanyDate } from '@/lib/company-timezone';
import {
    offerKnownGeneralError,
    offerUnrenderedErrors,
} from '../lib/offer-form-errors';
import type {
    CandidateDetail,
    CandidateFormOptions,
    CandidateIndexRow,
} from '../types';
import type { CandidateOfferFieldsData } from './candidate-offer-fields';
import { CandidateOfferFields } from './candidate-offer-fields';

function defaultPrepareData(
    candidate: CandidateIndexRow | CandidateDetail,
    options: CandidateFormOptions,
    timezone: string,
): CandidateOfferFieldsData {
    const detail = candidate as CandidateDetail;
    const lineCurrency =
        detail.line?.salary_currency_code ||
        options.default_currency_code ||
        'AED';

    return {
        salary_amount: '',
        salary_currency_code: lineCurrency,
        proposed_joining_date: '',
        offer_date: nowInCompanyDate(timezone),
        expiry_date: '',
        notes: '',
        offer_document: null,
    };
}

export function CandidatePrepareOfferSheet({
    open,
    onOpenChange,
    candidate,
    options,
    timezone,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    candidate: CandidateIndexRow | CandidateDetail | null;
    options: CandidateFormOptions;
    timezone: string;
}) {
    const form = useForm<CandidateOfferFieldsData>({
        salary_amount: '',
        salary_currency_code: options.default_currency_code || 'AED',
        proposed_joining_date: '',
        offer_date: nowInCompanyDate(timezone),
        expiry_date: '',
        notes: '',
        offer_document: null,
    });

    useEffect(() => {
        if (open && candidate) {
            form.setData(defaultPrepareData(candidate, options, timezone));
            form.clearErrors();
        }
        // Reset only when the sheet opens for a candidate.
        // eslint-disable-next-line react-hooks/exhaustive-deps -- intentional open/id trigger
    }, [open, candidate?.id, timezone]);

    if (!candidate) {
        return null;
    }

    const detail = candidate as CandidateDetail;
    const salaryReference =
        detail.line?.salary_min || detail.line?.salary_max
            ? `${detail.line.salary_currency_code || ''} ${detail.line.salary_min ?? '—'} – ${detail.line.salary_max ?? '—'}`.trim()
            : null;

    const errors = form.errors as Record<string, string>;
    const generalError = offerKnownGeneralError(errors);
    const unrendered = offerUnrenderedErrors(errors);

    const submit = () => {
        if (form.processing) {
            return;
        }

        form.clearErrors();
        form.transform((data) => ({
            salary_amount: data.salary_amount,
            salary_currency_code: data.salary_currency_code,
            proposed_joining_date: data.proposed_joining_date,
            offer_date: data.offer_date,
            expiry_date: data.expiry_date || null,
            notes: data.notes || null,
            offer_document: data.offer_document,
            lock_version: candidate.lock_version,
            expected_stage: candidate.stage,
            expected_outcome: candidate.interview_outcome ?? '',
        }));
        form.post(
            `/organization/recruitment/candidates/${candidate.id}/offers`,
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                },
                onFinish: () => form.transform((data) => data),
            },
        );
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none p-0 sm:max-w-xl"
            >
                <SheetHeader className="border-b border-border/60 p-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        Prepare Offer/JOL
                    </SheetTitle>
                    <SheetDescription className="mt-1 space-y-1 text-xs text-muted-foreground/80">
                        <span className="block font-medium text-foreground">
                            {candidate.name}
                        </span>
                        <span className="block">
                            {candidate.position_title} ·{' '}
                            {candidate.requirement_number}
                        </span>
                        <span className="block">
                            Creates a Draft offer and moves the candidate to
                            Offer/JOL after a successful save.
                        </span>
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-4 overflow-y-auto p-6">
                    {generalError ? (
                        <p className="text-sm text-destructive" role="alert">
                            {generalError}
                        </p>
                    ) : null}
                    {unrendered.length > 0 ? (
                        <div
                            className="space-y-1 text-sm text-destructive"
                            role="alert"
                        >
                            {unrendered.map((message) => (
                                <p key={message}>{message}</p>
                            ))}
                        </div>
                    ) : null}

                    <CandidateOfferFields
                        form={form}
                        options={options}
                        idPrefix={`prepare-offer-${candidate.id}`}
                        salaryReference={salaryReference}
                    />
                </div>

                <SheetFooter className="border-t border-border/60 p-6">
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
                        onClick={submit}
                        disabled={form.processing}
                    >
                        {form.processing ? 'Preparing…' : 'Prepare Offer/JOL'}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
