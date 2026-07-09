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

const STEPS: { id: WizardStep; label: string }[] = [
    { id: 1, label: 'Review' },
    { id: 2, label: 'Sign' },
    { id: 3, label: 'Submit' },
];

function stepState(
    step: WizardStep,
    current: WizardStep,
): 'done' | 'current' | 'todo' {
    if (step < current) return 'done';
    if (step === current) return 'current';
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
        if (!expiresAt) return null;
        const date = new Date(expiresAt);
        if (Number.isNaN(date.getTime())) return null;
        return date.toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        });
    }, [expiresAt]);

    const goNext = () => {
        if (step === 1) { setStep(2); return; }
        if (step === 2 && hasSignature) setStep(3);
    };

    const goBack = () => {
        if (step === 2) { setStep(1); return; }
        if (step === 3) setStep(2);
    };

    if (alreadySubmitted) {
        return (
            <>
                <Head title={`Sign ${documentLabel}`} />
                <div className="flex min-h-svh items-center justify-center bg-muted/40 px-4 py-10">
                    <div className="w-full max-w-md rounded-2xl border bg-background p-8 text-center shadow-sm">
                        <CheckCircle2 className="mx-auto mb-4 h-12 w-12 text-emerald-500" />
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

            {/* ── Mobile: fixed compact header ─────────────────────── */}
            <header className="fixed inset-x-0 top-0 z-40 border-b bg-background/95 backdrop-blur sm:hidden">
                <div className="flex h-12 items-center gap-2 px-3">
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-xs text-muted-foreground">{companyName}</p>
                        <p className="truncate text-sm font-semibold leading-tight">{documentLabel}</p>
                    </div>
                    <div className="flex shrink-0 gap-1">
                        {STEPS.map((item) => {
                            const state = stepState(item.id, step);
                            return (
                                <span
                                    key={item.id}
                                    className={cn(
                                        'flex size-6 items-center justify-center rounded-full text-[11px] font-semibold',
                                        state === 'done' && 'bg-emerald-500 text-white',
                                        state === 'current' && 'bg-primary text-primary-foreground',
                                        state === 'todo' && 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    {state === 'done' ? <CheckCircle2 className="size-3.5" /> : item.id}
                                </span>
                            );
                        })}
                    </div>
                </div>
            </header>

            {/* ── Desktop header card ───────────────────────────────── */}
            <div className="hidden sm:block">
                {/* rendered below inside max-w container */}
            </div>

            <div className="bg-muted/40 pt-12 pb-24 sm:min-h-svh sm:px-4 sm:py-8 sm:pb-8 sm:pt-8">
                <div className="mx-auto w-full max-w-3xl sm:space-y-4">

                    {/* Desktop-only full header */}
                    <header className="hidden rounded-2xl border bg-background p-6 shadow-sm sm:block">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {companyName}
                        </p>
                        <h1 className="mt-1 text-2xl font-semibold tracking-tight">
                            Sign your {documentLabel.toLowerCase()}
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Step {step} of 3 — {STEPS[step - 1]?.label}
                        </p>

                        <div className="mt-4 flex items-center gap-2">
                            {STEPS.map((item) => {
                                const state = stepState(item.id, step);
                                return (
                                    <div
                                        key={item.id}
                                        className={cn(
                                            'flex min-w-0 flex-1 items-center justify-center gap-2 rounded-full px-3 py-1.5',
                                            state === 'done' && 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-100',
                                            state === 'current' && 'bg-primary text-primary-foreground',
                                            state === 'todo' && 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-black/10 text-[11px] font-semibold dark:bg-white/10">
                                            {state === 'done' ? <CheckCircle2 className="size-3.5" /> : item.id}
                                        </span>
                                        <span className="truncate text-sm font-medium">{item.label}</span>
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
                            if (step !== 3 || !canSubmit) event.preventDefault();
                        }}
                    >
                        <input type="hidden" name="signed_name" value={employeeName} />
                        <input type="hidden" name="signature_data" value={signatureData ?? ''} />
                        {consent ? <input type="hidden" name="consent" value="1" /> : null}

                        {/* ── Main content area ─────────────────────────── */}
                        <section className="bg-background px-3 py-3 sm:rounded-2xl sm:border sm:p-6 sm:shadow-sm">
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
                                <div className="mt-2 border-t pt-2">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="h-8 px-2 text-xs text-muted-foreground"
                                        asChild
                                    >
                                        <a href={downloadUrl}>
                                            <Download className="mr-1.5 size-3.5" />
                                            Download unsigned PDF
                                        </a>
                                    </Button>
                                </div>
                            ) : null}

                            {step === 3 ? (
                                <div className="space-y-4">
                                    <div>
                                        <h2 className="text-base font-semibold">Confirm and submit</h2>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            HR reviews the signed PDF. Your record is updated only when they approve it.
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
                                                className="mx-auto h-20 w-full max-w-xs rounded-lg border bg-white object-contain p-2"
                                            />
                                        ) : null}
                                        <p className="mt-2 text-center text-xs text-muted-foreground">
                                            {employeeName} · {signedDate}
                                        </p>
                                    </div>

                                    <label className="flex cursor-pointer items-start gap-3 rounded-xl border bg-muted/20 p-3 text-sm">
                                        <Checkbox
                                            checked={consent}
                                            onCheckedChange={(checked) => setConsent(checked === true)}
                                            className="mt-0.5 size-5"
                                        />
                                        <span>
                                            I confirm this declaration is correct and I am signing voluntarily.
                                        </span>
                                    </label>
                                </div>
                            ) : null}
                        </section>

                        {/* ── Fixed bottom action bar ───────────────────── */}
                        <div
                            className={cn(
                                'fixed inset-x-0 bottom-0 z-40 border-t bg-background/95 px-3 py-2 backdrop-blur',
                                'pb-[max(0.5rem,env(safe-area-inset-bottom))]',
                                'sm:static sm:z-auto sm:rounded-2xl sm:border sm:bg-background sm:p-4 sm:shadow-sm sm:backdrop-blur-none',
                            )}
                        >
                            {step === 2 && !hasSignature ? (
                                <p className="mb-1.5 text-xs text-muted-foreground">
                                    Draw or upload your signature first.
                                </p>
                            ) : null}
                            {step === 3 && canSubmit ? (
                                <p className="mb-1.5 text-xs text-emerald-700 dark:text-emerald-300">
                                    Ready — tap Submit to send to HR.
                                </p>
                            ) : null}

                            <div className="flex gap-2">
                                {step > 1 ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="lg"
                                        className="h-11 w-24 shrink-0 sm:flex-1"
                                        onClick={goBack}
                                    >
                                        <ArrowLeft className="mr-1.5 size-4" />
                                        Back
                                    </Button>
                                ) : null}

                                {step < 3 ? (
                                    <Button
                                        type="button"
                                        size="lg"
                                        className="h-11 flex-1 text-base"
                                        disabled={step === 2 && !hasSignature}
                                        onClick={goNext}
                                    >
                                        Continue
                                    </Button>
                                ) : (
                                    <Button
                                        type="submit"
                                        size="lg"
                                        className="h-11 flex-1 text-base"
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
