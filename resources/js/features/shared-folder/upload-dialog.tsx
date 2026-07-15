import { Form, usePage } from '@inertiajs/react';
import { Loader2, Upload } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type DocumentTypeOption = {
    id: number;
    title: string;
};

export function SharedFolderUploadDialog({
    open,
    onOpenChange,
    uploadUrl,
    documentTypes,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    uploadUrl: string;
    documentTypes: DocumentTypeOption[];
}) {
    const { errors } = usePage().props as {
        errors?: Record<string, string>;
    };
    const [documentTypeId, setDocumentTypeId] = useState('');

    useEffect(() => {
        if (!open) {
            setDocumentTypeId('');
        }
    }, [open]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="border-zinc-800 bg-zinc-950 text-zinc-100 sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Upload className="h-4 w-4 text-zinc-400" />
                        Upload document
                    </DialogTitle>
                </DialogHeader>

                <Form
                    action={uploadUrl}
                    method="post"
                    encType="multipart/form-data"
                    className="space-y-4"
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ processing }) => (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="guest-file">
                                    File <span className="text-red-400">*</span>
                                </Label>
                                <Input
                                    id="guest-file"
                                    name="file"
                                    type="file"
                                    required
                                    accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                    className="border-zinc-800 bg-zinc-900 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-800 file:px-3 file:py-1 file:text-xs file:text-zinc-200"
                                />
                                <p className="text-xs text-zinc-500">
                                    PDF, JPG, or PNG. Max 20 MB.
                                </p>
                                {errors?.file ? (
                                    <p className="text-sm text-red-400">
                                        {errors.file}
                                    </p>
                                ) : null}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="guest-document-type">
                                    Document type
                                    <span className="ml-1 font-normal text-zinc-500">
                                        (optional)
                                    </span>
                                </Label>
                                <select
                                    id="guest-document-type"
                                    name="document_type_id"
                                    value={documentTypeId}
                                    onChange={(event) =>
                                        setDocumentTypeId(event.target.value)
                                    }
                                    className="flex h-10 w-full rounded-xl border border-zinc-800 bg-zinc-900 px-3 text-sm text-zinc-100"
                                >
                                    <option value="">No type</option>
                                    {documentTypes.map((type) => (
                                        <option key={type.id} value={type.id}>
                                            {type.title}
                                        </option>
                                    ))}
                                </select>
                                {errors?.document_type_id ? (
                                    <p className="text-sm text-red-400">
                                        {errors.document_type_id}
                                    </p>
                                ) : null}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="guest-document-number">
                                    Document number
                                    <span className="ml-1 font-normal text-zinc-500">
                                        (optional)
                                    </span>
                                </Label>
                                <Input
                                    id="guest-document-number"
                                    name="document_number"
                                    className="border-zinc-800 bg-zinc-900"
                                />
                                {errors?.document_number ? (
                                    <p className="text-sm text-red-400">
                                        {errors.document_number}
                                    </p>
                                ) : null}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="guest-issue-date">
                                        Issue date
                                        <span className="ml-1 font-normal text-zinc-500">
                                            (optional)
                                        </span>
                                    </Label>
                                    <Input
                                        id="guest-issue-date"
                                        name="issue_date"
                                        type="date"
                                        className="border-zinc-800 bg-zinc-900"
                                    />
                                    {errors?.issue_date ? (
                                        <p className="text-sm text-red-400">
                                            {errors.issue_date}
                                        </p>
                                    ) : null}
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="guest-expiry-date">
                                        Expiry date
                                        <span className="ml-1 font-normal text-zinc-500">
                                            (optional)
                                        </span>
                                    </Label>
                                    <Input
                                        id="guest-expiry-date"
                                        name="expiry_date"
                                        type="date"
                                        className="border-zinc-800 bg-zinc-900"
                                    />
                                    {errors?.expiry_date ? (
                                        <p className="text-sm text-red-400">
                                            {errors.expiry_date}
                                        </p>
                                    ) : null}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="guest-notes">
                                    Notes
                                    <span className="ml-1 font-normal text-zinc-500">
                                        (optional)
                                    </span>
                                </Label>
                                <Textarea
                                    id="guest-notes"
                                    name="notes"
                                    rows={3}
                                    className="border-zinc-800 bg-zinc-900"
                                />
                                {errors?.notes ? (
                                    <p className="text-sm text-red-400">
                                        {errors.notes}
                                    </p>
                                ) : null}
                            </div>

                            <DialogFooter className="gap-2 sm:justify-end">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="rounded-xl border-zinc-700"
                                    onClick={() => onOpenChange(false)}
                                    disabled={processing}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    className="rounded-xl"
                                    disabled={processing}
                                >
                                    {processing ? (
                                        <>
                                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                            Uploading…
                                        </>
                                    ) : (
                                        'Upload'
                                    )}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
