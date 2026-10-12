import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
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
import { nowInCompanyDate } from '@/lib/company-timezone';
import { RequiredIndicator } from '@/pages/organization/_components/record-form-field';
import {
    offerKnownGeneralError,
    offerUnrenderedErrors,
} from '../lib/offer-form-errors';
import { resolveOfferSentDateGuidance } from '../lib/offer-sent-date-guidance';
import type {
    CandidateDetail,
    CandidateFormOptions,
    CandidateOfferDetail,
} from '../types';
import { CandidateOfferFields } from './candidate-offer-fields';
import { CandidateOfferStatusBadge } from './candidate-stage-badge';

type OfferFormState = {
    salary_amount: string;
    salary_currency_code: string;
    proposed_joining_date: string;
    offer_date: string;
    expiry_date: string;
    notes: string;
    offer_document: File | null;
    acceptance_document: File | null;
    remove_offer_document: boolean;
    remove_acceptance_document: boolean;
    reason: string;
    sent_at: string;
    accepted_at: string;
    rejected_at: string;
};

function emptyOfferForm(
    candidate: CandidateDetail,
    options: CandidateFormOptions,
    offer?: CandidateOfferDetail | null,
): OfferFormState {
    const lineCurrency =
        candidate.line?.salary_currency_code ||
        options.default_currency_code ||
        'AED';

    const companyToday = nowInCompanyDate(candidate.timezone);

    return {
        salary_amount: offer?.salary_amount ?? '',
        salary_currency_code: offer?.salary_currency_code ?? lineCurrency,
        proposed_joining_date: offer?.proposed_joining_date ?? '',
        offer_date: offer?.offer_date ?? companyToday,
        expiry_date: offer?.expiry_date ?? '',
        notes: offer?.notes ?? '',
        offer_document: null,
        acceptance_document: null,
        remove_offer_document: false,
        remove_acceptance_document: false,
        reason: '',
        sent_at: companyToday,
        accepted_at: companyToday,
        rejected_at: companyToday,
    };
}

export function CandidateOfferPanel({
    candidate,
    options,
    onPrepareOffer,
}: {
    candidate: CandidateDetail;
    options: CandidateFormOptions;
    onPrepareOffer?: () => void;
}) {
    const offer = candidate.current_offer;
    const [reasonAction, setReasonAction] = useState<
        'reject' | 'revise' | null
    >(null);
    const [isPosting, setIsPosting] = useState(false);
    const form = useForm<OfferFormState>(
        emptyOfferForm(candidate, options, offer),
    );

    const isBusy = form.processing || isPosting;

    const salaryReference =
        candidate.line?.salary_min || candidate.line?.salary_max
            ? `${candidate.line.salary_currency_code || ''} ${candidate.line.salary_min ?? '—'} – ${candidate.line.salary_max ?? '—'}`.trim()
            : null;

    const resetFromOffer = (next?: CandidateOfferDetail | null) => {
        form.setData(emptyOfferForm(candidate, options, next ?? offer));
        form.clearErrors();
    };

    const submitPrepare = () => {
        if (isBusy) {
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
                onFinish: () => form.transform((data) => data),
            },
        );
    };

    const submitUpdate = () => {
        if (!offer || isBusy) {
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
            acceptance_document: data.acceptance_document,
            remove_offer_document: data.remove_offer_document,
            remove_acceptance_document: data.remove_acceptance_document,
            lock_version: candidate.lock_version,
            offer_lock_version: offer.lock_version,
            expected_stage: candidate.stage,
            expected_offer_status: offer.status,
        }));
        form.post(
            `/organization/recruitment/candidates/${candidate.id}/offers/${offer.id}`,
            {
                forceFormData: true,
                preserveScroll: true,
                onFinish: () => form.transform((data) => data),
            },
        );
    };

    const postAction = (
        url: string,
        data: Record<string, string | number | boolean | File | null>,
        forceFormData = false,
        onSuccess?: () => void,
    ) => {
        if (isBusy) {
            return;
        }

        setIsPosting(true);
        form.clearErrors();

        router.post(url, data, {
            preserveScroll: true,
            forceFormData,
            onSuccess: () => {
                onSuccess?.();
            },
            onError: (errors) => {
                form.setError(
                    errors as Partial<Record<keyof OfferFormState, string>>,
                );
            },
            onFinish: () => {
                setIsPosting(false);
            },
        });
    };

    const offerErrors = form.errors as Record<string, string>;
    const generalError = offerKnownGeneralError(offerErrors);
    const unrenderedErrors = offerUnrenderedErrors(offerErrors);

    return (
        <section
            id="offer-jol"
            className="scroll-mt-4 rounded-xl border border-border/60 bg-card p-5"
        >
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-semibold tracking-wide uppercase">
                    Offer / JOL
                </h2>
                {offer ? (
                    <CandidateOfferStatusBadge
                        status={offer.status}
                        label={offer.status_label}
                    />
                ) : null}
            </div>

            {salaryReference ? (
                <p className="mb-3 text-sm text-muted-foreground">
                    Position salary range (reference only): {salaryReference}
                </p>
            ) : (
                <p className="mb-3 text-sm text-muted-foreground">
                    Enter an explicit offered amount. Requirement salary is
                    reference only and is never applied automatically.
                </p>
            )}

            {generalError ? (
                <p className="mb-3 text-sm text-destructive" role="alert">
                    {generalError}
                </p>
            ) : null}

            {unrenderedErrors.length > 0 ? (
                <div
                    className="mb-3 space-y-1 text-sm text-destructive"
                    role="alert"
                >
                    {unrenderedErrors.map((msg, i) => (
                        <p key={i}>{msg}</p>
                    ))}
                </div>
            ) : null}

            {!offer && candidate.can_prepare_offer ? (
                <div className="space-y-3">
                    <p className="text-sm text-muted-foreground">
                        Prepare the Offer/JOL with salary, offer date, and
                        proposed joining details.
                    </p>
                    <Button
                        onClick={() => {
                            if (onPrepareOffer) {
                                onPrepareOffer();

                                return;
                            }

                            submitPrepare();
                        }}
                        disabled={isBusy}
                    >
                        Prepare Offer/JOL
                    </Button>
                </div>
            ) : null}

            {!offer && !candidate.can_prepare_offer ? (
                <p className="text-sm text-muted-foreground">
                    {candidate.stage === 'interview' &&
                    candidate.interview_outcome === 'selected'
                        ? 'You do not have permission to prepare an offer for this candidate.'
                        : 'Prepare an offer after the candidate is Selected at Interview.'}
                </p>
            ) : null}

            {offer ? (
                <div className="space-y-4">
                    <dl className="grid gap-3 text-sm md:grid-cols-2">
                        <div>
                            <dt className="text-muted-foreground">Revision</dt>
                            <dd>#{offer.revision_number}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Salary</dt>
                            <dd>
                                {offer.salary_currency_code}{' '}
                                {offer.salary_amount}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                Offer date
                            </dt>
                            <dd>{offer.offer_date || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                Proposed joining
                            </dt>
                            <dd>{offer.proposed_joining_date || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Expiry</dt>
                            <dd>{offer.expiry_date || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Notes</dt>
                            <dd className="whitespace-pre-wrap">
                                {offer.notes || '—'}
                            </dd>
                        </div>
                        {offer.sent_at ? (
                            <div>
                                <dt className="text-muted-foreground">Sent</dt>
                                <dd>
                                    {offer.sent_at}
                                    {offer.sent_by_name
                                        ? ` · ${offer.sent_by_name}`
                                        : ''}
                                </dd>
                            </div>
                        ) : null}
                        {offer.accepted_at ? (
                            <div>
                                <dt className="text-muted-foreground">
                                    Accepted
                                </dt>
                                <dd>
                                    {offer.accepted_at}
                                    {offer.accepted_by_name
                                        ? ` · ${offer.accepted_by_name}`
                                        : ''}
                                </dd>
                            </div>
                        ) : null}
                        {offer.rejected_at ? (
                            <div>
                                <dt className="text-muted-foreground">
                                    Offer rejected
                                </dt>
                                <dd>
                                    {offer.rejected_at}
                                    {offer.rejected_by_name
                                        ? ` · ${offer.rejected_by_name}`
                                        : ''}
                                    {offer.rejection_reason
                                        ? ` — ${offer.rejection_reason}`
                                        : ''}
                                </dd>
                            </div>
                        ) : null}
                    </dl>

                    <div className="flex flex-wrap gap-2">
                        {offer.can_download_offer_document ? (
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={`/organization/recruitment/candidates/${candidate.id}/offers/${offer.id}/documents/offer`}
                                >
                                    Download offer document
                                </a>
                            </Button>
                        ) : null}
                        {offer.can_download_acceptance_document ? (
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={`/organization/recruitment/candidates/${candidate.id}/offers/${offer.id}/documents/acceptance`}
                                >
                                    Download signed acceptance
                                </a>
                            </Button>
                        ) : null}
                    </div>

                    {offer.can_update ? (
                        <div className="space-y-4 border-t border-border/50 pt-4">
                            <h3 className="text-sm font-medium">Edit draft</h3>
                            <CandidateOfferFields
                                form={form}
                                options={options}
                                idPrefix={`draft-offer-${offer.id}`}
                                salaryReference={salaryReference}
                            />
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    onClick={submitUpdate}
                                    disabled={isBusy}
                                >
                                    Save draft
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => resetFromOffer()}
                                    disabled={isBusy}
                                >
                                    Reset
                                </Button>
                            </div>
                        </div>
                    ) : null}

                    {offer.can_send ? (
                        <OfferMarkSentSection
                            candidate={candidate}
                            offer={offer}
                            sentAt={form.data.sent_at}
                            sentAtError={form.errors.sent_at}
                            isBusy={isBusy}
                            onSentAtChange={(value) =>
                                form.setData('sent_at', value)
                            }
                            onMarkSent={() =>
                                postAction(
                                    `/organization/recruitment/candidates/${candidate.id}/offers/${offer.id}/send`,
                                    {
                                        lock_version: candidate.lock_version,
                                        offer_lock_version: offer.lock_version,
                                        expected_stage: candidate.stage,
                                        expected_offer_status: offer.status,
                                        sent_at: form.data.sent_at || null,
                                    },
                                )
                            }
                        />
                    ) : null}

                    {offer.can_accept ? (
                        <div className="space-y-3 rounded-lg border border-border/60 bg-muted/20 p-4">
                            <h4 className="text-sm font-medium">
                                Record acceptance
                            </h4>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="space-y-1">
                                    <Label htmlFor="offer-accepted-date">
                                        Actual acceptance date
                                    </Label>
                                    <Input
                                        id="offer-accepted-date"
                                        type="date"
                                        value={form.data.accepted_at}
                                        onChange={(event) =>
                                            form.setData(
                                                'accepted_at',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={form.errors.accepted_at}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="offer-acceptance-document">
                                        Signed acceptance (optional)
                                    </Label>
                                    <Input
                                        id="offer-acceptance-document"
                                        type="file"
                                        onChange={(event) =>
                                            form.setData(
                                                'acceptance_document',
                                                event.target.files?.[0] ?? null,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={
                                            form.errors.acceptance_document
                                        }
                                    />
                                </div>
                            </div>
                            <div>
                                <Button
                                    disabled={isBusy}
                                    onClick={() =>
                                        postAction(
                                            `/organization/recruitment/candidates/${candidate.id}/offers/${offer.id}/accept`,
                                            {
                                                lock_version:
                                                    candidate.lock_version,
                                                offer_lock_version:
                                                    offer.lock_version,
                                                expected_stage: candidate.stage,
                                                expected_offer_status:
                                                    offer.status,
                                                accepted_at:
                                                    form.data.accepted_at ||
                                                    null,
                                                acceptance_document:
                                                    form.data
                                                        .acceptance_document,
                                            },
                                            true,
                                        )
                                    }
                                >
                                    Mark Accepted
                                </Button>
                            </div>
                        </div>
                    ) : null}

                    {offer.can_reject ||
                    (offer.can_revise && candidate.stage !== 'joined') ? (
                        <div className="flex flex-wrap gap-2">
                            {offer.can_reject ? (
                                <Button
                                    variant="destructive"
                                    disabled={isBusy}
                                    onClick={() => setReasonAction('reject')}
                                >
                                    Reject Offer
                                </Button>
                            ) : null}
                            {offer.can_revise &&
                            candidate.stage !== 'joined' ? (
                                <Button
                                    variant="outline"
                                    disabled={isBusy}
                                    onClick={() => setReasonAction('revise')}
                                >
                                    Revise Offer
                                </Button>
                            ) : null}
                        </div>
                    ) : null}

                    {candidate.stage === 'joined' ? (
                        <p className="text-xs text-muted-foreground">
                            Offers cannot be revised while candidate is in
                            Joined stage. Undo joined first if revision is
                            required.
                        </p>
                    ) : null}

                    {candidate.offer_history.length > 1 ? (
                        <div className="border-t border-border/50 pt-4">
                            <h3 className="mb-2 text-sm font-medium">
                                Offer revisions
                            </h3>
                            <ul className="space-y-2 text-sm">
                                {candidate.offer_history.map((item) => (
                                    <li key={item.id}>
                                        #{item.revision_number} ·{' '}
                                        {item.status_label}
                                        {item.is_current ? ' (current)' : ''}
                                        {item.revision_reason
                                            ? ` — ${item.revision_reason}`
                                            : ''}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : null}
                </div>
            ) : null}

            <Dialog
                open={reasonAction !== null}
                onOpenChange={(open) => {
                    if (!open && !isBusy) {
                        setReasonAction(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {reasonAction === 'revise'
                                ? 'Revise offer'
                                : 'Reject offer'}
                        </DialogTitle>
                        <DialogDescription>
                            {reasonAction === 'revise'
                                ? 'Creates a new Draft revision. Sent/accepted/rejected offers are preserved in history.'
                                : 'Records that the candidate declined the offer. This does not set interview outcome to Not Selected.'}
                        </DialogDescription>
                    </DialogHeader>

                    {generalError ? (
                        <p className="text-sm text-destructive" role="alert">
                            {generalError}
                        </p>
                    ) : null}
                    {unrenderedErrors.length > 0 ? (
                        <div
                            className="space-y-1 text-sm text-destructive"
                            role="alert"
                        >
                            {unrenderedErrors.map((msg, i) => (
                                <p key={i}>{msg}</p>
                            ))}
                        </div>
                    ) : null}

                    <div className="space-y-3">
                        <div className="space-y-2">
                            <Label htmlFor="offer-dialog-reason">Reason</Label>
                            <Textarea
                                id="offer-dialog-reason"
                                value={form.data.reason}
                                onChange={(event) =>
                                    form.setData('reason', event.target.value)
                                }
                            />
                            <InputError message={form.errors.reason} />
                        </div>
                        {reasonAction === 'reject' ? (
                            <div className="space-y-2">
                                <Label htmlFor="offer-dialog-rejected-at">
                                    Decision date
                                </Label>
                                <Input
                                    id="offer-dialog-rejected-at"
                                    type="date"
                                    value={form.data.rejected_at}
                                    onChange={(event) =>
                                        form.setData(
                                            'rejected_at',
                                            event.target.value,
                                        )
                                    }
                                />
                                <InputError message={form.errors.rejected_at} />
                            </div>
                        ) : null}
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={isBusy}
                            onClick={() => setReasonAction(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant={
                                reasonAction === 'reject'
                                    ? 'destructive'
                                    : 'default'
                            }
                            disabled={isBusy || form.data.reason.trim() === ''}
                            onClick={() => {
                                if (!offer || !reasonAction || isBusy) {
                                    return;
                                }

                                if (reasonAction === 'reject') {
                                    postAction(
                                        `/organization/recruitment/candidates/${candidate.id}/offers/${offer.id}/reject`,
                                        {
                                            reason: form.data.reason,
                                            rejected_at:
                                                form.data.rejected_at || null,
                                            lock_version:
                                                candidate.lock_version,
                                            offer_lock_version:
                                                offer.lock_version,
                                            expected_stage: candidate.stage,
                                            expected_offer_status: offer.status,
                                        },
                                        false,
                                        () => {
                                            setReasonAction(null);
                                            form.setData('reason', '');
                                        },
                                    );
                                } else {
                                    postAction(
                                        `/organization/recruitment/candidates/${candidate.id}/offers/${offer.id}/revise`,
                                        {
                                            reason: form.data.reason,
                                            salary_amount:
                                                form.data.salary_amount ||
                                                offer.salary_amount,
                                            salary_currency_code:
                                                form.data
                                                    .salary_currency_code ||
                                                offer.salary_currency_code,
                                            proposed_joining_date:
                                                form.data
                                                    .proposed_joining_date ||
                                                offer.proposed_joining_date,
                                            offer_date:
                                                form.data.offer_date ||
                                                offer.offer_date,
                                            expiry_date:
                                                form.data.expiry_date || null,
                                            notes: form.data.notes || null,
                                            lock_version:
                                                candidate.lock_version,
                                            offer_lock_version:
                                                offer.lock_version,
                                            expected_stage: candidate.stage,
                                            expected_offer_status: offer.status,
                                        },
                                        false,
                                        () => {
                                            setReasonAction(null);
                                            form.setData('reason', '');
                                        },
                                    );
                                }
                            }}
                        >
                            Confirm
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}

function OfferMarkSentSection({
    candidate,
    offer,
    sentAt,
    sentAtError,
    isBusy,
    onSentAtChange,
    onMarkSent,
}: {
    candidate: CandidateDetail;
    offer: CandidateOfferDetail;
    sentAt: string;
    sentAtError?: string;
    isBusy: boolean;
    onSentAtChange: (value: string) => void;
    onMarkSent: () => void;
}) {
    const guidance = resolveOfferSentDateGuidance({
        offerDate: offer.offer_date,
        sentAt,
        companyToday: nowInCompanyDate(candidate.timezone),
    });

    return (
        <div className="space-y-3 rounded-lg border border-border/60 bg-muted/20 p-4">
            <h4 className="text-sm font-medium">Mark offer sent</h4>
            <p className="text-xs text-muted-foreground">
                Offer Date is the date on the offer document. Actual Sent Date
                is when the offer was actually sent to the candidate. Mark Sent
                records that event only — it does not send email.
            </p>
            <div className="grid gap-3 sm:grid-cols-2 sm:items-end">
                <div className="space-y-1">
                    <Label htmlFor="offer-sent-date">
                        Actual sent date
                        <RequiredIndicator show />
                    </Label>
                    <Input
                        id="offer-sent-date"
                        type="date"
                        value={sentAt}
                        required
                        aria-required
                        aria-invalid={
                            Boolean(sentAtError) || !guidance.canMarkSent
                        }
                        onChange={(event) => onSentAtChange(event.target.value)}
                    />
                    <InputError message={sentAtError} />
                </div>
                <div>
                    <Button
                        disabled={isBusy || !guidance.canMarkSent}
                        onClick={onMarkSent}
                    >
                        Mark Sent
                    </Button>
                </div>
            </div>
            {guidance.message ? (
                <p
                    className={
                        guidance.canMarkSent
                            ? 'text-xs text-muted-foreground'
                            : 'text-xs text-amber-800 dark:text-amber-300'
                    }
                    role={guidance.canMarkSent ? 'note' : 'status'}
                >
                    {guidance.message}
                </p>
            ) : null}
            <p className="text-xs text-muted-foreground">
                Offer date on file: {offer.offer_date || '—'}
            </p>
        </div>
    );
}
