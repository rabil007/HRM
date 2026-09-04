import { FileStack, FileText, Loader2, Sparkles } from 'lucide-react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { BulkDocumentTypeOption } from '../types';

export function DocumentContextHeader({
    documentTypeKey,
    documentTypeOptions,
    missingCount,
    selectedCount,
    generateLabel,
    canGenerate,
    isGenerating,
    onDocumentTypeChange,
    onGenerate,
}: {
    documentTypeKey: string;
    documentTypeOptions: BulkDocumentTypeOption[];
    missingCount: number;
    selectedCount: number;
    generateLabel: string;
    canGenerate: boolean;
    isGenerating: boolean;
    onDocumentTypeChange: (value: string) => void;
    onGenerate: () => void;
}) {
    const selectedOption = documentTypeOptions.find(
        (opt) => opt.value === documentTypeKey,
    );
    const selectedLabel = selectedOption?.label ?? 'Unknown Document';
    const category = selectedOption?.category ?? 'System Templates';
    const isCompanyTemplate = category === 'Company Templates';

    return (
        <Card className="overflow-hidden border-border/60 bg-linear-to-br from-card via-card to-primary/5 shadow-sm">
            <CardContent className="p-0">
                <div className="flex flex-col gap-5 p-5 lg:flex-row lg:items-center lg:justify-between">
                    <div className="flex min-w-0 items-start gap-4">
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-primary/15 bg-primary/10 text-primary shadow-sm">
                            {isCompanyTemplate ? (
                                <FileText className="h-5 w-5" />
                            ) : (
                                <FileStack className="h-5 w-5" />
                            )}
                        </div>
                        <div className="min-w-0 space-y-2">
                            <p className="text-[10px] font-bold tracking-[0.16em] text-muted-foreground uppercase">
                                Document template
                            </p>
                            <h2 className="truncate text-lg leading-tight font-semibold tracking-tight text-foreground">
                                {selectedLabel}
                            </h2>
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge
                                    variant="secondary"
                                    className="text-xs font-normal"
                                >
                                    {isCompanyTemplate
                                        ? 'Company Template'
                                        : 'System Template'}
                                </Badge>
                                {selectedOption ? (
                                    <Badge
                                        variant="outline"
                                        className="border-dashed text-xs font-normal"
                                    >
                                        {selectedOption.template_format ===
                                        'pdf_overlay'
                                            ? 'PDF'
                                            : 'Rich Text'}
                                    </Badge>
                                ) : null}
                                <Badge className="border-0 bg-emerald-500/10 text-xs font-normal text-emerald-700 hover:bg-emerald-500/15 dark:text-emerald-400">
                                    {missingCount > 0
                                        ? `${missingCount} ready to generate`
                                        : 'All documents generated'}
                                </Badge>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {selectedCount > 0
                                    ? `${selectedCount} selected · generation will apply only to this selection.`
                                    : missingCount > 0
                                      ? 'Select employees below, or generate for everyone currently missing it.'
                                      : 'Select generated employees below to replace their existing copies.'}
                            </p>
                        </div>
                    </div>

                    <div className="flex w-full flex-col gap-3 sm:flex-row sm:items-end lg:w-auto">
                        <div className="w-full space-y-1.5 lg:w-72">
                            <p className="text-xs font-medium text-muted-foreground">
                                Change template
                            </p>
                            <AppSelect
                                value={documentTypeKey}
                                onValueChange={onDocumentTypeChange}
                                className="h-11 w-full rounded-xl border-border/70 bg-background/80 shadow-xs"
                            >
                                {documentTypeOptions.map((option) => (
                                    <AppSelectItem
                                        key={option.value}
                                        value={option.value}
                                        keywords={option.category}
                                    >
                                        {option.category === 'Company Templates'
                                            ? `📄 ${option.label}`
                                            : option.label}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        </div>
                        {canGenerate ? (
                            <Button
                                type="button"
                                size="lg"
                                className="h-11 w-full gap-2 rounded-xl px-5 shadow-sm sm:w-auto"
                                onClick={onGenerate}
                                disabled={
                                    isGenerating ||
                                    (selectedCount === 0 && missingCount === 0)
                                }
                            >
                                {isGenerating ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <Sparkles className="h-4 w-4" />
                                )}
                                {generateLabel}
                            </Button>
                        ) : null}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
