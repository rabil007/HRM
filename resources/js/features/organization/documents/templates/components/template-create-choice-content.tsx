import { Link } from '@inertiajs/react';
import { FileText, Layers } from 'lucide-react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { templates } from '@/routes/organization/documents';
import {
    content as createContent,
    pdf as createPdf,
} from '@/routes/organization/documents/templates/create';

export function TemplateCreateChoiceContent() {
    return (
        <Main>
            <DetailsHeader
                kicker="Documents"
                title="Create Document Template"
                description="Choose how you would like to design this document template."
                backHref={templates.url()}
                backLabel="Back to Templates"
            />

            <div className="mx-auto grid max-w-2xl gap-4">
                <Link
                    href={createContent.url()}
                    className="flex items-start gap-4 rounded-xl border border-border/80 bg-card p-5 text-left transition-colors hover:border-primary/50 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                >
                    <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400">
                        <FileText className="size-6" />
                    </div>
                    <div className="space-y-1.5">
                        <p className="text-base font-semibold text-foreground">
                            Content Template
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Create a text or HTML-based template with dynamic
                            merge fields like employee name, designation, and
                            company details.
                        </p>
                    </div>
                </Link>

                <Link
                    href={createPdf.url()}
                    className="flex items-start gap-4 rounded-xl border border-border/80 bg-card p-5 text-left transition-colors hover:border-primary/50 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                >
                    <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400">
                        <Layers className="size-6" />
                    </div>
                    <div className="space-y-1.5">
                        <p className="text-base font-semibold text-foreground">
                            Upload Existing PDF
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Upload an official branded company PDF and visually
                            place employee merge fields across any page with
                            drag-and-drop.
                        </p>
                    </div>
                </Link>
            </div>
        </Main>
    );
}
