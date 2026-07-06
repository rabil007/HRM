import { router } from '@inertiajs/react';
import { FileCheck2, Info } from 'lucide-react';
import { useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';

export type BulkDocumentsPanelProps = {
    can: {
        bulk_documents: boolean;
    };
};

export function BulkDocumentsPanel({ can }: BulkDocumentsPanelProps) {
    const [isGenerating, setIsGenerating] = useState(false);

    const handleGenerateSalaryDeclarations = () => {
        if (!can.bulk_documents || isGenerating) {
            return;
        }

        setIsGenerating(true);

        router.post(
            '/settings/application/bulk-documents/salary-declarations',
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsGenerating(false);
                },
            },
        );
    };

    return (
        <div className="space-y-6">
            <Card className="border-border/80 bg-card dark:border-white/5 dark:bg-white/5">
                <CardContent className="p-6">
                    <div className="mb-6 flex items-center gap-4">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border border-rose-500/20 bg-rose-500/10 text-rose-600 dark:text-rose-400">
                            <FileCheck2 className="h-5 w-5" />
                        </div>
                        <div>
                            <h2 className="text-base font-bold tracking-tight text-foreground">
                                Salary declarations
                            </h2>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Generate bilingual salary declaration PDFs for
                                active employees.
                            </p>
                        </div>
                    </div>

                    <Alert className="mb-6 border-border/80 bg-muted/20 dark:border-white/5 dark:bg-white/[0.02]">
                        <Info className="h-4 w-4" />
                        <AlertTitle>How this works</AlertTitle>
                        <AlertDescription>
                            A background job creates one PDF per active employee
                            and stores it in employee documents under the
                            &quot;Salary Declaration&quot; type. Employees who
                            already have this document are skipped.
                        </AlertDescription>
                    </Alert>

                    <Button
                        type="button"
                        className="h-11 rounded-xl px-6"
                        disabled={!can.bulk_documents || isGenerating}
                        onClick={handleGenerateSalaryDeclarations}
                    >
                        {isGenerating ? (
                            <Spinner />
                        ) : (
                            <FileCheck2 className="h-4 w-4" />
                        )}
                        Generate salary declarations
                    </Button>
                </CardContent>
            </Card>
        </div>
    );
}
