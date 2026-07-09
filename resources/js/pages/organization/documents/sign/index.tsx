import { Form, Head } from '@inertiajs/react';
import { CheckCircle2, Download } from 'lucide-react';
import { useState } from 'react';
import { SignaturePad } from '@/components/signature-pad';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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
};

export default function BulkDocumentSignPage({
    employeeName,
    employeeNo,
    companyName,
    documentLabel,
    alreadySubmitted,
    submitUrl,
    downloadUrl,
}: Props) {
    const [signedName, setSignedName] = useState(employeeName);
    const [signatureData, setSignatureData] = useState<string | null>(null);
    const [consent, setConsent] = useState(false);

    if (alreadySubmitted) {
        return (
            <>
                <Head title={`Sign ${documentLabel}`} />
                <div className="flex min-h-svh items-center justify-center bg-muted/30 px-4 py-10">
                    <div className="w-full max-w-lg rounded-2xl border bg-background p-8 text-center shadow-sm">
                        <CheckCircle2 className="mx-auto mb-4 h-12 w-12 text-emerald-500" />
                        <h1 className="text-xl font-semibold">Submitted for review</h1>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Your signed {documentLabel.toLowerCase()} has been
                            submitted. HR will review it and update your employee
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
            <div className="flex min-h-svh items-center justify-center bg-muted/30 px-4 py-10">
                <div className="w-full max-w-xl rounded-2xl border bg-background p-6 shadow-sm sm:p-8">
                    <div className="mb-6 space-y-1">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            {companyName}
                        </p>
                        <h1 className="text-2xl font-semibold">{documentLabel}</h1>
                        <p className="text-sm text-muted-foreground">
                            Please review and sign your declaration electronically,
                            or download the unsigned PDF to sign manually.
                        </p>
                    </div>

                    <div className="mb-6 grid gap-3 rounded-lg border bg-muted/20 p-4 text-sm">
                        <div className="flex justify-between gap-4">
                            <span className="text-muted-foreground">Employee</span>
                            <span className="font-medium">{employeeName}</span>
                        </div>
                        {employeeNo ? (
                            <div className="flex justify-between gap-4">
                                <span className="text-muted-foreground">
                                    Employee no.
                                </span>
                                <span className="font-medium">{employeeNo}</span>
                            </div>
                        ) : null}
                    </div>

                    <Form
                        action={submitUrl}
                        method="post"
                        className="space-y-5"
                        onSubmit={(event) => {
                            if (!signatureData || !consent || !signedName.trim()) {
                                event.preventDefault();
                            }
                        }}
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="signed_name">Full name</Label>
                            <Input
                                id="signed_name"
                                name="signed_name"
                                value={signedName}
                                onChange={(event) =>
                                    setSignedName(event.target.value)
                                }
                                required
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label>Signature</Label>
                            <SignaturePad onChange={setSignatureData} />
                            <input
                                type="hidden"
                                name="signature_data"
                                value={signatureData ?? ''}
                            />
                        </div>

                        <label className="flex items-start gap-3 text-sm">
                            <Checkbox
                                checked={consent}
                                onCheckedChange={(checked) =>
                                    setConsent(checked === true)
                                }
                            />
                            {consent ? (
                                <input type="hidden" name="consent" value="1" />
                            ) : null}
                            <span>
                                I confirm that the information in this declaration
                                is correct and I am signing voluntarily.
                            </span>
                        </label>

                        <div className="flex flex-col gap-3 sm:flex-row">
                            <Button
                                type="submit"
                                className="flex-1"
                                disabled={
                                    !signatureData || !consent || !signedName.trim()
                                }
                            >
                                Submit signature
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                className="flex-1"
                                asChild
                            >
                                <a href={downloadUrl}>
                                    <Download className="mr-2 h-4 w-4" />
                                    Download unsigned PDF
                                </a>
                            </Button>
                        </div>
                    </Form>
                </div>
            </div>
        </>
    );
}
