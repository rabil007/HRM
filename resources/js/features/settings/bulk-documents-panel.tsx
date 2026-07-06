import { router } from '@inertiajs/react';
import { ChevronDown, Download, FileCheck2, Info, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { downloadBulkZip } from '@/features/organization/documents/shared/download-bulk-zip';
import { toast } from '@/lib/toast';

export function BulkDocumentsPanel() {
    const [isGenerating, setIsGenerating] = useState(false);
    const [isClearing, setIsClearing] = useState(false);
    const [isDownloading, setIsDownloading] = useState(false);
    const [clearDialogOpen, setClearDialogOpen] = useState(false);

    const handleGenerateSalaryDeclarations = () => {
        if (isBusy) {
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

    const handleDownloadSalaryDeclarations = async () => {
        if (isBusy) {
            return;
        }

        setIsDownloading(true);

        try {
            await downloadBulkZip(
                '/settings/application/bulk-documents/salary-declarations/download',
                {},
                'salary_declarations.zip',
            );
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Download failed. Please try again.',
            );
        } finally {
            setIsDownloading(false);
        }
    };

    const handleClearSalaryDeclarations = () => {
        if (isBusy) {
            return;
        }

        setIsClearing(true);

        router.delete(
            '/settings/application/bulk-documents/salary-declarations',
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsClearing(false);
                    setClearDialogOpen(false);
                },
            },
        );
    };

    const isBusy = isGenerating || isClearing || isDownloading;

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
                            already have this document are skipped. Download all
                            exports every generated PDF in a zip file. Use clear
                            all to remove them for the current company.
                        </AlertDescription>
                    </Alert>

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                type="button"
                                className="h-11 rounded-xl px-6"
                                disabled={isBusy}
                            >
                                {isBusy ? (
                                    <Spinner />
                                ) : (
                                    <FileCheck2 className="h-4 w-4" />
                                )}
                                Actions
                                <ChevronDown className="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" className="w-64">
                            <DropdownMenuItem
                                disabled={isBusy}
                                onSelect={handleGenerateSalaryDeclarations}
                            >
                                <FileCheck2 className="h-4 w-4" />
                                Generate salary declarations
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                disabled={isBusy}
                                onSelect={() => {
                                    void handleDownloadSalaryDeclarations();
                                }}
                            >
                                <Download className="h-4 w-4" />
                                Download all as zip
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                disabled={isBusy}
                                variant="destructive"
                                onSelect={() => setClearDialogOpen(true)}
                            >
                                <Trash2 className="h-4 w-4" />
                                Clear all salary declarations
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </CardContent>
            </Card>

            <ConfirmDeleteDialog
                open={clearDialogOpen}
                onOpenChange={setClearDialogOpen}
                title="Clear all salary declarations?"
                description="This permanently deletes every salary declaration document for active employees in the current company. You can generate them again afterwards."
                confirmText={isClearing ? 'Clearing...' : 'Clear all'}
                onConfirm={handleClearSalaryDeclarations}
            />
        </div>
    );
}
