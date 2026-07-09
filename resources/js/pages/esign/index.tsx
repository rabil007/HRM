import { Form, Head, usePage } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Download, PenLine } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { formatSignedDate } from '@/features/esign/format-signed-date';
import { PdfSignatureViewer } from '@/features/esign/pdf-signature-viewer';
import type { SignaturePlacementConfig } from '@/features/settings/esign-placement/esign-placement-coordinates';
import { cn } from '@/lib/utils';

type Props = {
    employeeName: string;
    employeeNo: string | null;
    companyName: string;
    documentLabel: string;
    expiresAt: string | null;
    status: string;
    alreadySubmitted: boolean;
    submitUrl: string;
    downloadUrl: string;
    placement: SignaturePlacementConfig;
};

type WizardStep = 1 | 2 | 3;

const STEPS: { id: WizardStep; label: string }[] = [
    { id: 1, label: 'Review' },
    { id: 2, label: 'Sign' },
    { id: 3, label: 'Submit' },
];

function stepState(
    step: WizardStep,
    current: WizardStep,
): 'done' | 'current' | 'todo' {
    if (step < current) {
return 'done';
}

    if (step === current) {
return 'current';
}

    return 'todo';
}

export default function DocumentEsignPage({
    employeeName,
    employeeNo,
    companyName,
    documentLabel,
    expiresAt,
    alreadySubmitted,
    submitUrl,
    downloadUrl,
    placement,
}: Props) {
    const { errors } = usePage().props;
    const submitError =
        (typeof errors.signature_data === 'string' ? errors.signature_data : null) ??
        (typeof errors.consent === 'string' ? errors.consent : null) ??
        (typeof errors.signed_name === 'string' ? errors.signed_name : null) ??
        (typeof errors.token === 'string' ? errors.token : null) ??
        null;
    const [step, setStep] = useState<WizardStep>(submitError ? 3 : 1);
    const [signatureData, setSignatureData] = useState<string | null>(null);
    const [consent, setConsent] = useState(false);
    const signedDate = formatSignedDate();
    const hasSignature = Boolean(signatureData);
    const canSubmit = hasSignature && consent;

    const expiryLabel = useMemo(() => {
        if (!expiresAt) {
return null;
}

        const date = new Date(expiresAt);

        if (Number.isNaN(date.getTime())) {
return null;
}

        return date.toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        });
    }, [expiresAt]);

    const goNext = () => {
        if (step === 1) {
 setStep(2);

 return; 
}

        if (step === 2 && hasSignature) {
setStep(3);
}
    };

    const goBack = () => {
        if (step === 2) {
 setStep(1);

 return; 
}

        if (step === 3) {
setStep(2);
}
    };

    if (alreadySubmitted) {
        return (
            <>
                <Head title={`Sign ${documentLabel}`} />
                <div className="flex min-h-svh items-center justify-center bg-muted/40 px-4 py-10">
                    <div className="w-full max-w-md rounded-2xl border bg-background p-8 text-center shadow-sm">
                        <div className="mx-auto mb-4 flex size-16 items-center justify-center rounded-full bg-emerald-50 dark:bg-emerald-950/40">
                            <CheckCircle2 className="size-8 text-emerald-500" />
                        </div>
                        <h1 className="text-xl font-semibold">Submitted for review</h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Your signed {documentLabel.toLowerCase()} was received.
                            HR will review it and update your employee record.
                        </p>
                    </div>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={`Sign ${documentLabel}`} />

            {/* ── Mobile fixed top bar ──────────────────────────────── */}
            <header className="fixed inset-x-0 top-0 z-40 sm:hidden">
                <div className="flex h-13 items-center gap-3 border-b bg-background/95 px-4 backdrop-blur">
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                            {companyName}
                        </p>
                        <p className="truncate text-[13px] font-semibold leading-tight">
                            {documentLabel}
                        </p>
                    </div>

                    {/* Connected step dots */}
                    <div className="flex shrink-0 items-center gap-0">
                        {STEPS.map((item, idx) => {
                            const state = stepState(item.id, step);

                            return (
                                <div key={item.id} className="flex items-center">
                                    {idx > 0 ? (
                                        <div
                                            className={cn(
                                                'h-px w-5 transition-colors',
                                                state === 'done' || (state === 'current' && idx < step)
                                                    ? 'bg-primary'
                                                    : 'bg-border',
                                            )}
                                        />
                                    ) : null}
                                    <div
                                        className={cn(
                                            'flex size-6 items-center justify-center rounded-full text-[11px] font-bold transition-all',
                                            state === 'done' && 'bg-emerald-500 text-white shadow-sm',
                                            state === 'current' && 'bg-primary text-primary-foreground shadow-sm ring-2 ring-primary/20',
                                            state === 'todo' && 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        {state === 'done' ? (
                                            <CheckCircle2 className="size-3.5" />
                                        ) : (
                                            item.id
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                {/* Step progress bar */}
                <div className="h-0.5 bg-border">
                    <div
                        className="h-full bg-primary transition-all duration-500 ease-out"
                        style={{ width: `${((step - 1) / 2) * 100}%` }}
                    />
                </div>
            </header>

            <div className="bg-muted/30 pt-13.5 pb-24 sm:min-h-svh sm:px-4 sm:py-8 sm:pb-8 sm:pt-8">
                <div className="mx-auto w-full max-w-3xl sm:space-y-4">

                    {/* Desktop-only full header */}
                    <header className="hidden rounded-2xl border bg-background p-6 shadow-sm sm:block">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {companyName}
                        </p>
                        <h1 className="mt-1 text-2xl font-semibold tracking-tight">
                            Sign your {documentLabel.toLowerCase()}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Step {step} of 3 — {STEPS[step - 1]?.label}
                        </p>

                        <div className="mt-4 flex items-center gap-1.5">
                            {STEPS.map((item, idx) => {
                                const state = stepState(item.id, step);

                                return (
                                    <div key={item.id} className="flex flex-1 items-center gap-1.5">
                                        {idx > 0 ? (
                                            <div
                                                className={cn(
                                                    'h-px flex-1 transition-colors',
                                                    step > idx ? 'bg-primary' : 'bg-border',
                                                )}
                                            />
                                        ) : null}
                                        <div
                                            className={cn(
                                                'flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-medium transition-all',
                                                state === 'done' && 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-100',
                                                state === 'current' && 'bg-primary text-primary-foreground shadow-sm',
                                                state === 'todo' && 'bg-muted text-muted-foreground',
                                            )}
                                        >
                                            <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-black/10 text-[11px] font-bold dark:bg-white/10">
                                                {state === 'done' ? <CheckCircle2 className="size-3.5" /> : item.id}
                                            </span>
                                            {item.label}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        <dl className="mt-4 grid gap-2 rounded-xl border bg-muted/20 p-3 text-sm sm:grid-cols-2">
                            <div className="flex items-center justify-between gap-3 sm:block">
                                <dt className="text-muted-foreground">Employee</dt>
                                <dd className="font-medium sm:mt-0.5">{employeeName}</dd>
                            </div>
                            {employeeNo ? (
                                <div className="flex items-center justify-between gap-3 sm:block">
                                    <dt className="text-muted-foreground">Employee no.</dt>
                                    <dd className="font-medium sm:mt-0.5">{employeeNo}</dd>
                                </div>
                            ) : null}
                            <div className="flex items-center justify-between gap-3 sm:block">
                                <dt className="text-muted-foreground">Signing date</dt>
                                <dd className="font-medium sm:mt-0.5">{signedDate}</dd>
                            </div>
                            {expiryLabel ? (
                                <div className="flex items-center justify-between gap-3 sm:block">
                                    <dt className="text-muted-foreground">Link expires</dt>
                                    <dd className="font-medium sm:mt-0.5">{expiryLabel}</dd>
                                </div>
                            ) : null}
                        </dl>
                    </header>

                    <Form
                        action={submitUrl}
                        method="post"
                        className="sm:space-y-4"
                        onSubmit={(event) => {
                            if (step !== 3 || !canSubmit) {
event.preventDefault();
}
                        }}
                    >
                        {({ processing }) => (
                            <>
                                <input type="hidden" name="signed_name" value={employeeName} />
                                <input type="hidden" name="signature_data" value={signatureData ?? ''} />
                                {consent ? <input type="hidden" name="consent" value="1" /> : null}

                                <section className="bg-background px-4 py-4 sm:rounded-2xl sm:border sm:p-6 sm:shadow-sm">
                                    {step === 1 || step === 2 ? (
                                        <PdfSignatureViewer
                                            pdfUrl={downloadUrl}
                                            page={placement.page}
                                            placement={placement}
                                            mode={step === 1 ? 'review' : 'sign'}
                                            signatureData={signatureData}
                                            onSignatureChange={setSignatureData}
                                        />
                                    ) : null}

                                    {step === 1 ? (
                                        <div className="mt-3 flex items-center justify-between border-t pt-3">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="h-8 gap-1.5 px-2 text-xs text-muted-foreground hover:text-foreground"
                                                asChild
                                            >
                                                <a href={downloadUrl}>
                                                    <Download className="size-3.5" />
                                                    Download unsigned PDF
                                                </a>
                                            </Button>
                                        </div>
                                    ) : null}

                                    {step === 3 ? (
                                        <div className="space-y-4">
                                            <div className="flex items-center gap-2.5">
                                                <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-emerald-50 dark:bg-emerald-950/40">
                                                    <CheckCircle2 className="size-5 text-emerald-600 dark:text-emerald-400" />
                                                </div>
                                                <div>
                                                    <h2 className="text-sm font-semibold">Almost done</h2>
                                                    <p className="text-xs text-muted-foreground">
                                                        Review and confirm your signature below.
                                                    </p>
                                                </div>
                                            </div>

                                            {submitError ? (
                                                <div className="rounded-xl border border-destructive/30 bg-destructive/10 px-3 py-2.5 text-sm text-destructive">
                                                    {submitError}
                                                </div>
                                            ) : null}

                                            <div className="overflow-hidden rounded-xl border">
                                                <div className="bg-muted/30 px-3 py-2">
                                                    <p className="text-xs font-medium text-muted-foreground">
                                                        Your signature
                                                    </p>
                                                </div>
                                                {signatureData ? (
                                                    <div className="bg-white p-4">
                                                        <img
                                                            src={signatureData}
                                                            alt="Signature preview"
                                                            className="mx-auto h-20 w-full max-w-xs object-contain"
                                                        />
                                                    </div>
                                                ) : null}
                                                <div className="border-t bg-muted/10 px-3 py-2">
                                                    <p className="text-center text-xs text-muted-foreground">
                                                        {employeeName} · {signedDate}
                                                    </p>
                                                </div>
                                            </div>

                                            <label className="flex cursor-pointer items-start gap-3 rounded-xl border bg-muted/20 p-3.5 transition-colors hover:bg-muted/30">
                                                <Checkbox
                                                    checked={consent}
                                                    onCheckedChange={(checked) => setConsent(checked === true)}
                                                    className="mt-0.5 size-5"
                                                />
                                                <span className="text-sm leading-snug">
                                                    I confirm this declaration is correct and I am signing voluntarily.
                                                </span>
                                            </label>
                                        </div>
                                    ) : null}
                                </section>

                                <div
                                    className={cn(
                                        'fixed inset-x-0 bottom-0 z-40 border-t bg-background/95 px-4 pt-2.5 backdrop-blur',
                                        'pb-[max(0.625rem,env(safe-area-inset-bottom))]',
                                        'sm:static sm:z-auto sm:rounded-2xl sm:border sm:bg-background sm:p-4 sm:shadow-sm sm:backdrop-blur-none',
                                    )}
                                >
                                    {step === 2 && !hasSignature ? (
                                        <div className="mb-2 flex items-center gap-1.5">
                                            <PenLine className="size-3.5 shrink-0 text-amber-500" />
                                            <p className="text-xs text-muted-foreground">
                                                Draw or upload your signature to continue.
                                            </p>
                                        </div>
                                    ) : null}
                                    {step === 3 && submitError ? (
                                        <p className="mb-2 text-xs text-destructive">{submitError}</p>
                                    ) : null}
                                    {step === 3 && canSubmit && !submitError ? (
                                        <div className="mb-2 flex items-center gap-1.5">
                                            <CheckCircle2 className="size-3.5 shrink-0 text-emerald-500" />
                                            <p className="text-xs text-emerald-700 dark:text-emerald-300">
                                                Ready — tap Submit to send to HR.
                                            </p>
                                        </div>
                                    ) : null}

                                    <div className="flex gap-2">
                                        {step > 1 ? (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="lg"
                                                className="h-11 w-24 shrink-0 gap-1.5 sm:flex-1"
                                                disabled={processing}
                                                onClick={goBack}
                                            >
                                                <ArrowLeft className="size-4" />
                                                Back
                                            </Button>
                                        ) : null}

                                        {step < 3 ? (
                                            <Button
                                                type="button"
                                                size="lg"
                                                className="h-11 flex-1 text-[15px] font-semibold"
                                                disabled={step === 2 && !hasSignature}
                                                onClick={goNext}
                                            >
                                                Continue
                                            </Button>
                                        ) : (
                                            <Button
                                                type="submit"
                                                size="lg"
                                                className="h-11 flex-1 bg-emerald-600 text-[15px] font-semibold text-white hover:bg-emerald-700 disabled:bg-muted"
                                                disabled={!canSubmit || processing}
                                            >
                                                {processing ? 'Submitting…' : 'Submit for HR review'}
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            </div>
        </>
    );
}
