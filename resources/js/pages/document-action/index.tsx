import { Form, Head, usePage } from '@inertiajs/react';
import { CheckCircle2, Download } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SignatureCapture } from '@/features/esign/signature-capture';

type Props = {
    companyName: string;
    documentTitle: string;
    employeeName: string;
    expiresAt: string | null;
    status: string;
    action: 'sign' | 'acknowledge';
    alreadyCompleted: boolean;
    documentUrl: string;
    submitSignUrl: string | null;
    submitAcknowledgeUrl: string | null;
    acknowledgementStatement: string;
};

export default function DocumentActionPage({
    companyName,
    documentTitle,
    employeeName,
    expiresAt,
    action,
    alreadyCompleted,
    documentUrl,
    submitSignUrl,
    submitAcknowledgeUrl,
    acknowledgementStatement,
}: Props) {
    const { errors } = usePage().props;
    const [signatureData, setSignatureData] = useState<string | null>(null);
    const [signatureClearToken] = useState(0);
    const [consent, setConsent] = useState(false);
    const [acknowledgement, setAcknowledgement] = useState(false);
    const [typedName, setTypedName] = useState(employeeName);

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

    const submitError =
        (typeof errors.token === 'string' ? errors.token : null) ??
        (typeof errors.signature_data === 'string'
            ? errors.signature_data
            : null) ??
        (typeof errors.consent === 'string' ? errors.consent : null) ??
        (typeof errors.acknowledgement === 'string'
            ? errors.acknowledgement
            : null) ??
        null;

    if (alreadyCompleted) {
        return (
            <>
                <Head title={documentTitle} />
                <div className="flex min-h-svh items-center justify-center bg-muted/40 px-4 py-10">
                    <div className="w-full max-w-md rounded-2xl border bg-background p-8 text-center shadow-sm">
                        <div className="mx-auto mb-4 flex size-16 items-center justify-center rounded-full bg-emerald-50 dark:bg-emerald-950/40">
                            <CheckCircle2 className="size-8 text-emerald-500" />
                        </div>
                        <h1 className="text-xl font-semibold">
                            {action === 'sign'
                                ? 'Signature submitted'
                                : 'Acknowledged'}
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {action === 'sign'
                                ? 'Your signed document has been recorded.'
                                : 'Your acknowledgement has been recorded.'}
                        </p>
                    </div>
                </div>
            </>
        );
    }

    const formAction = action === 'sign' ? submitSignUrl : submitAcknowledgeUrl;

    return (
        <>
            <Head title={documentTitle} />
            <div className="min-h-svh bg-muted/30 px-4 py-8">
                <div className="mx-auto w-full max-w-3xl space-y-4">
                    <header className="rounded-2xl border bg-background p-6 shadow-sm">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {companyName}
                        </p>
                        <h1 className="mt-1 text-2xl font-semibold tracking-tight">
                            {documentTitle}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {action === 'sign'
                                ? 'Review the document and submit your signature.'
                                : 'Review the document and submit your acknowledgement.'}
                        </p>
                        {expiryLabel ? (
                            <p className="mt-2 text-xs text-muted-foreground">
                                Link expires {expiryLabel}
                            </p>
                        ) : null}
                    </header>

                    <section className="overflow-hidden rounded-2xl border bg-background shadow-sm">
                        <div className="flex items-center justify-between border-b px-4 py-2">
                            <p className="text-sm font-medium">
                                Document preview
                            </p>
                            <Button variant="ghost" size="sm" asChild>
                                <a
                                    href={documentUrl}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Download className="mr-1.5 size-4" />
                                    Open PDF
                                </a>
                            </Button>
                        </div>
                        <iframe
                            title="Document preview"
                            src={documentUrl}
                            className="h-[60vh] w-full border-0 bg-white"
                        />
                    </section>

                    {formAction ? (
                        <Form
                            action={formAction}
                            method="post"
                            className="space-y-4"
                        >
                            {({ processing }) => (
                                <>
                                    {submitError ? (
                                        <div className="rounded-xl border border-destructive/30 bg-destructive/10 px-3 py-2.5 text-sm text-destructive">
                                            {submitError}
                                        </div>
                                    ) : null}

                                    <section className="rounded-2xl border bg-background p-6 shadow-sm">
                                        <div className="space-y-4">
                                            <div>
                                                <Label htmlFor="name">
                                                    Full name
                                                </Label>
                                                <Input
                                                    id="name"
                                                    name={
                                                        action === 'sign'
                                                            ? 'signed_name'
                                                            : 'name'
                                                    }
                                                    value={typedName}
                                                    onChange={(event) =>
                                                        setTypedName(
                                                            event.target.value,
                                                        )
                                                    }
                                                    required
                                                    className="mt-1.5"
                                                />
                                            </div>

                                            {action === 'sign' ? (
                                                <>
                                                    <SignatureCapture
                                                        clearToken={
                                                            signatureClearToken
                                                        }
                                                        onChange={
                                                            setSignatureData
                                                        }
                                                    />
                                                    <input
                                                        type="hidden"
                                                        name="signature_data"
                                                        value={
                                                            signatureData ?? ''
                                                        }
                                                    />
                                                    <label className="flex cursor-pointer items-start gap-3 rounded-xl border bg-muted/20 p-3.5">
                                                        <Checkbox
                                                            checked={consent}
                                                            onCheckedChange={(
                                                                checked,
                                                            ) =>
                                                                setConsent(
                                                                    checked ===
                                                                        true,
                                                                )
                                                            }
                                                            className="mt-0.5"
                                                        />
                                                        <span className="text-sm leading-snug">
                                                            I consent to sign
                                                            this document
                                                            electronically.
                                                        </span>
                                                    </label>
                                                    {consent ? (
                                                        <input
                                                            type="hidden"
                                                            name="consent"
                                                            value="1"
                                                        />
                                                    ) : null}
                                                </>
                                            ) : (
                                                <label className="flex cursor-pointer items-start gap-3 rounded-xl border bg-muted/20 p-3.5">
                                                    <Checkbox
                                                        checked={
                                                            acknowledgement
                                                        }
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            setAcknowledgement(
                                                                checked ===
                                                                    true,
                                                            )
                                                        }
                                                        className="mt-0.5"
                                                    />
                                                    <span className="text-sm leading-snug">
                                                        {
                                                            acknowledgementStatement
                                                        }
                                                    </span>
                                                </label>
                                            )}
                                            {action === 'acknowledge' &&
                                            acknowledgement ? (
                                                <input
                                                    type="hidden"
                                                    name="acknowledgement"
                                                    value="1"
                                                />
                                            ) : null}
                                        </div>
                                    </section>

                                    <Button
                                        type="submit"
                                        size="lg"
                                        className="w-full"
                                        disabled={
                                            processing ||
                                            (action === 'sign' &&
                                                (!signatureData || !consent)) ||
                                            (action === 'acknowledge' &&
                                                !acknowledgement)
                                        }
                                    >
                                        {processing
                                            ? 'Submitting…'
                                            : action === 'sign'
                                              ? 'Submit signature'
                                              : 'Submit acknowledgement'}
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : null}
                </div>
            </div>
        </>
    );
}
