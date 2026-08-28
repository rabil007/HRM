import { Form, Head, usePage } from '@inertiajs/react';
import { CheckCircle2, Download } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SignatureCapture } from '@/features/esign/signature-capture';

type Props = {
    request: {
        id: number;
        status: string;
        recipient_role_label: string;
        document_title: string | null;
        employee_name: string | null;
        source_version: number | null;
        expires_at: string | null;
        already_completed: boolean;
    };
    document_url: string;
    submit_sign_url: string;
};

export default function RecipientRequestRespondPage({
    request,
    document_url,
    submit_sign_url,
}: Props) {
    const { auth, errors } = usePage().props as {
        auth?: { user?: { name?: string } };
        errors: Record<string, string>;
    };
    const [signatureData, setSignatureData] = useState<string | null>(null);
    const [signatureClearToken] = useState(0);
    const [consent, setConsent] = useState(false);
    const [typedName, setTypedName] = useState(auth?.user?.name ?? '');

    const expiryLabel = useMemo(() => {
        if (!request.expires_at) {
            return null;
        }

        const date = new Date(request.expires_at);

        if (Number.isNaN(date.getTime())) {
            return null;
        }

        return date.toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        });
    }, [request.expires_at]);

    const submitError =
        (typeof errors.token === 'string' ? errors.token : null) ??
        (typeof errors.signature_data === 'string'
            ? errors.signature_data
            : null) ??
        (typeof errors.consent === 'string' ? errors.consent : null) ??
        null;

    if (request.already_completed) {
        return (
            <>
                <Head title="Countersignature completed" />
                <div className="flex min-h-svh items-center justify-center bg-muted/40 px-4 py-10">
                    <div className="w-full max-w-md rounded-2xl border bg-background p-8 text-center shadow-sm">
                        <div className="mx-auto mb-4 flex size-16 items-center justify-center rounded-full bg-emerald-50 dark:bg-emerald-950/40">
                            <CheckCircle2 className="size-8 text-emerald-500" />
                        </div>
                        <h1 className="text-xl font-semibold">
                            Countersignature submitted
                        </h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Your company countersignature has been recorded.
                        </p>
                    </div>
                </div>
            </>
        );
    }

    return (
        <>
            <Head
                title={request.document_title ?? 'Company countersignature'}
            />
            <div className="min-h-svh bg-muted/30 px-4 py-8">
                <div className="mx-auto w-full max-w-3xl space-y-4">
                    <header className="rounded-2xl border bg-background p-6 shadow-sm">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {request.recipient_role_label}
                        </p>
                        <h1 className="mt-1 text-2xl font-semibold tracking-tight">
                            {request.document_title ?? 'Document'}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Subject employee: {request.employee_name ?? '—'}
                        </p>
                        {request.source_version ? (
                            <p className="mt-1 text-sm text-muted-foreground">
                                Source version: v{request.source_version}
                            </p>
                        ) : null}
                        {expiryLabel ? (
                            <p className="mt-2 text-xs text-muted-foreground">
                                Request expires {expiryLabel}
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
                                    href={document_url}
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
                            src={document_url}
                            className="h-[60vh] w-full border-0 bg-white"
                        />
                    </section>

                    <Form
                        action={submit_sign_url}
                        method="post"
                        className="space-y-4"
                    >
                        <section className="rounded-2xl border bg-background p-6 shadow-sm">
                            <h2 className="text-base font-semibold">
                                Your signature
                            </h2>
                            <div className="mt-4 space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="signed_name">
                                        Typed full name
                                    </Label>
                                    <Input
                                        id="signed_name"
                                        name="signed_name"
                                        value={typedName}
                                        onChange={(event) =>
                                            setTypedName(event.target.value)
                                        }
                                        required
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label>Draw signature</Label>
                                    <SignatureCapture
                                        clearToken={signatureClearToken}
                                        onChange={setSignatureData}
                                    />
                                    <input
                                        type="hidden"
                                        name="signature_data"
                                        value={signatureData ?? ''}
                                    />
                                </div>
                                <label className="flex items-start gap-2 text-sm">
                                    <Checkbox
                                        checked={consent}
                                        onCheckedChange={(checked) =>
                                            setConsent(checked === true)
                                        }
                                    />
                                    <span>
                                        I consent to sign this document
                                        electronically.
                                    </span>
                                </label>
                                <input
                                    type="hidden"
                                    name="consent"
                                    value={consent ? '1' : '0'}
                                />
                                {submitError ? (
                                    <p className="text-sm text-destructive">
                                        {submitError}
                                    </p>
                                ) : null}
                            </div>
                        </section>
                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                disabled={
                                    !consent ||
                                    !signatureData ||
                                    typedName.trim() === ''
                                }
                            >
                                Submit countersignature
                            </Button>
                        </div>
                    </Form>
                </div>
            </div>
        </>
    );
}
