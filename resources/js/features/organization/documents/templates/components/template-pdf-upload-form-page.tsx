import { router } from '@inertiajs/react';
import { FileUp, Loader2, UploadCloud, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { templates } from '@/routes/organization/documents';
import { store as storeTemplate } from '@/routes/organization/documents/templates';
import type { DocumentTypeOption } from '../types';

export function TemplatePdfUploadFormPage({
    documentTypes,
}: {
    documentTypes: DocumentTypeOption[];
}) {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [documentTypeId, setDocumentTypeId] = useState<string>('none');
    const [file, setFile] = useState<File | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const selected = e.target.files?.[0] || null;

        if (!selected) {
            return;
        }

        if (
            selected.type !== 'application/pdf' &&
            !selected.name.endsWith('.pdf')
        ) {
            setErrorMessage('The selected file must be a PDF document.');

            return;
        }

        if (selected.size > 20 * 1024 * 1024) {
            setErrorMessage('The selected PDF exceeds the 20 MB size limit.');

            return;
        }

        setErrorMessage(null);
        setFile(selected);

        if (!name.trim()) {
            const baseName = selected.name
                .replace(/\.[^/.]+$/, '')
                .replace(/[-_]/g, ' ');
            setName(baseName.charAt(0).toUpperCase() + baseName.slice(1));
        }
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (!name.trim()) {
            setErrorMessage('Template name is required.');

            return;
        }

        if (!file) {
            setErrorMessage('Please choose a PDF document to upload.');

            return;
        }

        setIsSubmitting(true);
        setErrorMessage(null);

        const formData = new FormData();
        formData.append('template_format', 'pdf_overlay');
        formData.append('name', name.trim());

        if (description.trim()) {
            formData.append('description', description.trim());
        }

        if (documentTypeId !== 'none') {
            formData.append('document_type_id', documentTypeId);
        }

        formData.append('file', file);

        router.post(storeTemplate.url(), formData, {
            forceFormData: true,
            onFinish: () => {
                setIsSubmitting(false);
            },
            onError: (errors) => {
                const firstError = Object.values(errors)[0];
                setErrorMessage(
                    typeof firstError === 'string'
                        ? firstError
                        : 'Failed to upload PDF template.',
                );
            },
        });
    };

    const formatBytes = (bytes: number) => {
        if (bytes === 0) {
            return '0 B';
        }

        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));

        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    };

    return (
        <Main>
            <DetailsHeader
                kicker="Documents"
                title="Upload PDF Template"
                description="Upload an existing company PDF. You will place merge fields visually on the next page."
                backHref={templates.url()}
                backLabel="Back to Templates"
            />

            <Card className="mx-auto max-w-xl overflow-hidden glass-card dark:border-white/5 dark:bg-white/5">
                <CardContent className="p-6">
                    <form onSubmit={handleSubmit} className="space-y-5">
                        {errorMessage ? (
                            <div className="rounded-lg bg-destructive/10 p-3 text-xs font-medium text-destructive">
                                {errorMessage}
                            </div>
                        ) : null}

                        <div className="space-y-1.5">
                            <Label htmlFor="pdf-template-name">
                                Template Name{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Input
                                id="pdf-template-name"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder="e.g. Standard NDA 2026"
                                maxLength={200}
                                disabled={isSubmitting}
                                className="h-10"
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="pdf-template-description">
                                Description
                            </Label>
                            <Textarea
                                id="pdf-template-description"
                                value={description}
                                onChange={(e) => setDescription(e.target.value)}
                                placeholder="Optional details about this template..."
                                rows={2}
                                maxLength={1000}
                                disabled={isSubmitting}
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="pdf-template-doc-type">
                                Document Type
                            </Label>
                            <Select
                                value={documentTypeId}
                                onValueChange={setDocumentTypeId}
                                disabled={isSubmitting}
                            >
                                <SelectTrigger id="pdf-template-doc-type">
                                    <SelectValue placeholder="Select type (optional)" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">None</SelectItem>
                                    {documentTypes.map((dt) => (
                                        <SelectItem
                                            key={dt.id}
                                            value={String(dt.id)}
                                        >
                                            {dt.title}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <Label>
                                PDF File{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept=".pdf,application/pdf"
                                onChange={handleFileChange}
                                className="hidden"
                                disabled={isSubmitting}
                            />

                            {!file ? (
                                <button
                                    type="button"
                                    onClick={() =>
                                        fileInputRef.current?.click()
                                    }
                                    className="flex w-full cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed border-border/80 p-8 text-center transition-colors hover:border-primary/50 hover:bg-muted/30"
                                    disabled={isSubmitting}
                                >
                                    <div className="flex size-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                        <UploadCloud className="size-5" />
                                    </div>
                                    <p className="mt-2 text-xs font-semibold text-foreground">
                                        Click to upload or drag & drop
                                    </p>
                                    <p className="text-[11px] text-muted-foreground">
                                        PDF format up to 20 MB
                                    </p>
                                </button>
                            ) : (
                                <div className="flex items-center justify-between rounded-xl border border-border/80 bg-muted/30 p-3">
                                    <div className="flex items-center gap-3 overflow-hidden">
                                        <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                            <FileUp className="size-4" />
                                        </div>
                                        <div className="min-w-0">
                                            <p className="truncate text-xs font-medium text-foreground">
                                                {file.name}
                                            </p>
                                            <p className="text-[11px] text-muted-foreground">
                                                {formatBytes(file.size)}
                                            </p>
                                        </div>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-7 shrink-0 text-muted-foreground hover:text-destructive"
                                        onClick={() => {
                                            setFile(null);

                                            if (fileInputRef.current) {
                                                fileInputRef.current.value = '';
                                            }
                                        }}
                                        disabled={isSubmitting}
                                    >
                                        <X className="size-4" />
                                    </Button>
                                </div>
                            )}
                        </div>

                        <div className="flex flex-wrap justify-end gap-2 pt-2">
                            <Button
                                type="button"
                                variant="outline"
                                className="h-11 rounded-xl px-5"
                                onClick={() => router.visit(templates.url())}
                                disabled={isSubmitting}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                className="h-11 rounded-xl px-5"
                                disabled={isSubmitting || !name.trim() || !file}
                            >
                                {isSubmitting ? (
                                    <>
                                        <Loader2 className="mr-2 size-4 animate-spin" />
                                        Uploading...
                                    </>
                                ) : (
                                    'Create & open designer'
                                )}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </Main>
    );
}
