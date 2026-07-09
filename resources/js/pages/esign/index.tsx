import { Form, Head } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Download } from 'lucide-react';
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

const STEPS = [
    { id: 1 as const, label: 'Review' },
    { id: 2 as const, label: 'Sign' },
    { id: 3 as const, label: 'Submit' },
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
    const [step, setStep] = useState<WizardStep>(1);
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

    const currentStepMeta = STEPS.find((item) => item.id === step) ?? STEPS[0];

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
                        <CheckCircle2 className="mx-auto mb-4 h-12 w-12 text-emerald-500" />
                        <h1 className="text-xl font-semibold">
                            Submitted for review
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Your signed {documentLabel.toLowerCase()} was
                            received. HR will review it and update your employee
                            record.
                        </p>
                    </div>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={`Sign ${documentLabel}`} />
            <div className="min-h-svh bg-muted/40 px-3 py-4 sm:px-4 sm:py-8">
                <div className="mx-auto w-full max-w-3xl space-y-4">
                    <header className="rounded-2xl border bg-background p-4 shadow-sm sm:p-6">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {companyName}
                        </p>
                        <h1 className="mt-1 text-xl font-semibold tracking-tight sm:text-2xl">
                            Sign your {documentLabel.toLowerCase()}
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Step {step} of 3 — {currentStepMeta.label}
                        </p>

                        <div className="mt-4 flex items-center gap-2">
                            {STEPS.map((item, index) => {
                                const state = stepState(item.id, step);

                                return (
                                    <div
                                        key={item.id}
                                        className="flex min-w-0 flex-1 items-center gap-2"
                                    >
                                        <div
                                            className={cn(
                                                'flex w-full items-center gap-2 rounded-full px-2 py-1.5 sm:px-3',
                                                state === 'done' &&
                                                    'bg-emerald-100 text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-100',
                                                state === 'current' &&
                                                    'bg-primary text-primary-foreground',
                                                state === 'todo' &&
                                                    'bg-muted text-muted-foreground',
                                            )}
                                        >
                                            <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-black/10 text-[11px] font-semibold dark:bg-white/10">
                                                {state === 'done' ? (
                                                    <CheckCircle2 className="size-3.5" />
                                                ) : (
                                                    item.id
                                                )}
                                            </span>
                                            <span className="truncate text-xs font-medium sm:text-sm">
                                                {item.label}
                                            </span>
                                        </div>
                                        {index < STEPS.length - 1 ? (
                                            <span className="hidden h-px w-3 shrink-0 bg-border sm:block" />
                                        ) : null}
                                    </div>
                                );
                            })}
                        </div>

                        <dl className="mt-4 grid gap-2 rounded-xl border bg-muted/20 p-3 text-sm sm:grid-cols-2">
                            <div className="flex items-center justify-between gap-3 sm:block">
                                <dt className="text-muted-foreground">
                                    Employee
                                </dt>
                                <dd className="font-medium sm:mt-0.5">
                                    {employeeName}
                                </dd>
                            </div>
                            {employeeNo ? (
                                <div className="flex items-center justify-between gap-3 sm:block">
                                    <dt className="text-muted-foreground">
                                        Employee no.
                                    </dt>
                                    <dd className="font-medium sm:mt-0.5">
                                        {employeeNo}
                                    </dd>
                                </div>
                            ) : null}
                            <div className="flex items-center justify-between gap-3 sm:block">
                                <dt className="text-muted-foreground">
                                    Signing date
                                </dt>
                                <dd className="font-medium sm:mt-0.5">
                                    {signedDate}
                                </dd>
                            </div>
                            {expiryLabel ? (
                                <div className="flex items-center justify-between gap-3 sm:block">
                                    <dt className="text-muted-foreground">
                                        Link expires
                                    </dt>
                                    <dd className="font-medium sm:mt-0.5">
                                        {expiryLabel}
                                    </dd>
                                </div>
                            ) : null}
                        </dl>
                    </header>

                    <Form
                        action={submitUrl}
                        method="post"
                        className="space-y-4"
                        onSubmit={(event) => {
                            if (step !== 3 || !canSubmit) {
                                event.preventDefault();
                            }
                        }}
                    >
                        <input
                            type="hidden"
                            name="signed_name"
                            value={employeeName}
                        />
                        <input
                            type="hidden"
                            name="signature_data"
                            value={signatureData ?? ''}
                        />
                        {consent ? (
                            <input type="hidden" name="consent" value="1" />
                        ) : null}

                        <section className="rounded-2xl border bg-background p-4 shadow-sm sm:p-6">
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
                                <div className="mt-3 border-t pt-3">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="h-9 px-2 text-muted-foreground"
                                        asChild
                                    >
                                        <a href={downloadUrl}>
                                            <Download className="mr-2 size-4" />
                                            Prefer paper? Download unsigned PDF
                                        </a>
                                    </Button>
                                </div>
                            ) : null}

                            {step === 3 ? (
                                <div className="space-y-4">
                                    <div>
                                        <h2 className="text-sm font-semibold sm:text-base">
                                            Confirm and submit
                                        </h2>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            After you submit, HR reviews the
                                            signed PDF. Your employee record is
                                            updated only when they approve it.
                                        </p>
                                    </div>

                                    <div className="rounded-xl border bg-muted/20 p-3">
                                        <p className="mb-2 text-xs font-medium text-muted-foreground">
                                            Your signature
                                        </p>
                                        {signatureData ? (
                                            <img
                                                src={signatureData}
                                                alt="Signature preview"
                                                className="mx-auto h-24 w-full max-w-sm object-contain rounded-lg border bg-white p-2"
                                            />
                                        ) : null}
                                        <p className="mt-2 text-center text-xs text-muted-foreground">
                                            Signing as {employeeName} ·{' '}
                                            {signedDate}
                                        </p>
                                    </div>

                                    <label className="flex cursor-pointer items-start gap-3 rounded-xl border bg-muted/20 p-3 text-sm">
                                        <Checkbox
                                            checked={consent}
                                            onCheckedChange={(checked) =>
                                                setConsent(checked === true)
                                            }
                                            className="mt-0.5 size-5"
                                        />
                                        <span>
                                            I confirm this declaration is
                                            correct and I am signing
                                            voluntarily.
                                        </span>
                                    </label>
                                </div>
                            ) : null}
                        </section>

                        <div className="sticky bottom-0 z-10 -mx-3 space-y-3 border-t bg-background/95 p-3 backdrop-blur sm:static sm:mx-0 sm:rounded-2xl sm:border sm:bg-background sm:p-4 sm:shadow-sm sm:backdrop-blur-none">
                            {step === 2 && !hasSignature ? (
                                <p className="text-sm text-muted-foreground">
                                    Add your signature to continue.
                                </p>
                            ) : null}
                            {step === 3 && !consent ? (
                                <p className="text-sm text-muted-foreground">
                                    Tick the confirmation to submit.
                                </p>
                            ) : null}
                            {step === 3 && canSubmit ? (
                                <p className="text-sm text-emerald-700 dark:text-emerald-300">
                                    Ready to send to HR for review.
                                </p>
                            ) : null}

                            <div className="flex flex-col gap-2 sm:flex-row">
                                {step > 1 ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="lg"
                                        className="h-12 sm:flex-1"
                                        onClick={goBack}
                                    >
                                        <ArrowLeft className="mr-2 size-4" />
                                        Back
                                    </Button>
                                ) : null}

                                {step < 3 ? (
                                    <Button
                                        type="button"
                                        size="lg"
                                        className="h-12 flex-1 text-base"
                                        disabled={step === 2 && !hasSignature}
                                        onClick={goNext}
                                    >
                                        Continue
                                    </Button>
                                ) : (
                                    <Button
                                        type="submit"
                                        size="lg"
                                        className="h-12 flex-1 text-base"
                                        disabled={!canSubmit}
                                    >
                                        Submit for HR review
                                    </Button>
                                )}
                            </div>
                        </div>
                    </Form>
                </div>
            </div>
        </>
    );
}
